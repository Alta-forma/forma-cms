<?php
/**
 * Forma OAuth endpoints for remote MCP connector authorization.
 */
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__, 2));
    require_once ROOT_DIR . '/lib/bootstrap.php';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = forma_site_base_path();
if ($base !== '' && str_starts_with($uriPath, $base)) {
    $uriPath = substr($uriPath, strlen($base));
}
$path = '/' . trim($uriPath, '/');

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

try {
    if (str_starts_with($path, '/oauth/')) {
        AgentOAuth::requireSecureTransport();
    }
    if ($method === 'GET' && (
        $path === '/.well-known/oauth-protected-resource'
        || $path === '/.well-known/oauth-protected-resource/api/v1/mcp'
    )) {
        oauth_json(AgentOAuth::protectedResourceMetadata());
    }
    if ($method === 'GET' && $path === '/.well-known/oauth-authorization-server') {
        oauth_json(AgentOAuth::authorizationServerMetadata());
    }
    if ($method === 'POST' && $path === '/oauth/register') {
        $raw = file_get_contents('php://input') ?: '';
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            throw new OAuthException('invalid_client_metadata', 'Registration body must be JSON');
        }
        oauth_json(
            AgentOAuth::register($input, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            201
        );
    }
    if ($method === 'POST' && $path === '/oauth/token') {
        $input = $_POST;
        if (!$input) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $input = is_array($decoded) ? $decoded : [];
        }
        oauth_json(AgentOAuth::token($input));
    }
    if ($path === '/oauth/authorize' && in_array($method, ['GET', 'POST'], true)) {
        oauth_authorize($method);
    }
    oauth_json(['error' => 'not_found', 'error_description' => 'OAuth endpoint not found'], 404);
} catch (OAuthException $e) {
    oauth_json([
        'error' => $e->oauthError,
        'error_description' => $e->getMessage(),
    ], $e->httpStatus);
} catch (Throwable $e) {
    error_log('Forma OAuth: ' . $e->getMessage());
    oauth_json([
        'error' => 'server_error',
        'error_description' => 'The authorization server could not complete the request',
    ], 500);
}

function oauth_authorize(string $method): void {
    Auth::startSession();
    if ($method === 'POST') {
        Auth::requireAdmin(false);
        $consentId = (string)($_POST['consent_id'] ?? '');
        $consents = $_SESSION['forma_oauth_consents'] ?? [];
        $request = is_array($consents) && isset($consents[$consentId])
            ? $consents[$consentId]
            : null;
        unset($_SESSION['forma_oauth_consents'][$consentId]);
        if (!is_array($request)) {
            throw new OAuthException('invalid_request', 'Authorization request expired');
        }
        if ((string)($_POST['decision'] ?? '') !== 'allow') {
            header('Location: ' . AgentOAuth::errorRedirect($request, 'access_denied', 'The site owner declined access'));
            exit;
        }
        if (Auth::usesDefaultPassword()) {
            oauth_consent_error('Change the default admin password before connecting an AI tool.');
        }
        $code = AgentOAuth::approve($request, Auth::user() ?? 'Admin');
        header('Location: ' . AgentOAuth::successRedirect($request, $code));
        exit;
    }

    if (isset($_GET['resume'])) {
        $request = $_SESSION['forma_oauth_pending'] ?? null;
        unset($_SESSION['forma_oauth_pending']);
        if (!is_array($request)) {
            throw new OAuthException('invalid_request', 'Authorization request expired; start the connector again');
        }
    } else {
        $request = AgentOAuth::authorizationRequest($_GET);
    }

    if (!Auth::user()) {
        $_SESSION['forma_oauth_pending'] = $request;
        $login = rtrim(forma_admin_base_href(), '/') . '/login.php?oauth=1';
        header('Location: ' . $login);
        exit;
    }
    if (Auth::usesDefaultPassword()) {
        oauth_consent_error('Change the default admin password before connecting an AI tool.');
    }

    $consentId = bin2hex(random_bytes(24));
    $_SESSION['forma_oauth_consents'][$consentId] = $request;
    oauth_consent_page($request, $consentId);
}

/** Admin stylesheet URL, cache-busted the same way admin/index.php does. */
function oauth_stylesheet_href(): string {
    $version = (int)@filemtime(ADMIN_DIR . '/css/core.css');
    $href = rtrim(forma_admin_base_href(), '/') . '/css/core.css'
        . ($version > 0 ? '?v=' . $version : '');
    return htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
}

function oauth_consent_page(array $request, string $consentId): void {
    $client = htmlspecialchars((string)$request['client_name'], ENT_QUOTES, 'UTF-8');
    $host = htmlspecialchars((string)(parse_url((string)$request['redirect_uri'], PHP_URL_HOST) ?: 'AI connector'), ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars(forma_site_title(), ENT_QUOTES, 'UTF-8');
    $csrf = htmlspecialchars(Auth::csrf(), ENT_QUOTES, 'UTF-8');
    $id = htmlspecialchars($consentId, ENT_QUOTES, 'UTF-8');
    $action = htmlspecialchars(forma_site_base_path() . '/oauth/authorize', ENT_QUOTES, 'UTF-8');
    $css = oauth_stylesheet_href();
    $scopeLabels = [
        'content:read' => 'Read pages, posts, snippets, media, and SEO',
        'content:write' => 'Create and update pages, posts, and snippets',
        'media:write' => 'Upload media',
        'site:write' => 'Update public site identity and SEO',
        'rollback:write' => 'Put agent edits back if you request it',
    ];
    $items = '';
    foreach ($request['scopes'] as $scope) {
        $label = $scopeLabels[$scope] ?? $scope;
        $items .= '                <li>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . "</li>\n";
    }
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>Connect {$client} — {$site}</title>
    <link rel="stylesheet" href="{$css}">
</head>
<body class="fx-consent-body">
<main class="fx-consent">
    <div class="fx-consent-card">
        <p class="fx-consent-brand">{$site}</p>
        <h1 class="fx-consent-title">Connect {$client}?</h1>
        <p class="fx-consent-sub"><strong>{$host}</strong> is asking for Site editor access.</p>
        <div class="fx-consent-grant">
            <h2 class="fx-consent-grant-title">This allows it to</h2>
            <ul class="fx-consent-scopes">
{$items}            </ul>
        </div>
        <p class="fx-consent-note">Edits publish immediately. Forma protects one last-known-good copy before the first edit. This connection cannot delete content or media, change accounts, import backups, or update Forma core.</p>
        <form class="fx-consent-actions" method="post" action="{$action}">
            <input type="hidden" name="csrf_token" value="{$csrf}">
            <input type="hidden" name="consent_id" value="{$id}">
            <button class="fx-consent-btn fx-consent-deny" type="submit" name="decision" value="deny">Cancel</button>
            <button class="fx-consent-btn fx-consent-allow" type="submit" name="decision" value="allow">Allow Site editor</button>
        </form>
    </div>
</main>
</body>
</html>
HTML;
    exit;
}

function oauth_consent_error(string $message): void {
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $site = htmlspecialchars(forma_site_title(), ENT_QUOTES, 'UTF-8');
    $css = oauth_stylesheet_href();
    $settings = htmlspecialchars(
        rtrim(forma_admin_base_href(), '/') . '/index.php?section=settings&sub=access',
        ENT_QUOTES,
        'UTF-8'
    );
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow">
    <title>Connection blocked — {$site}</title>
    <link rel="stylesheet" href="{$css}">
</head>
<body class="fx-consent-body">
<main class="fx-consent">
    <div class="fx-consent-card">
        <p class="fx-consent-brand">{$site}</p>
        <h1 class="fx-consent-title">Connection blocked</h1>
        <p class="fx-consent-sub">{$safe}</p>
        <p class="fx-consent-links"><a href="{$settings}">Open Settings → Access</a></p>
    </div>
</main>
</body>
</html>
HTML;
    exit;
}

function oauth_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

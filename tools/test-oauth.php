<?php
/**
 * Isolated OAuth 2.1 + PKCE integration test.
 *
 * Uses a temporary SQLite database and never touches the installed site.
 */
$tmp = sys_get_temp_dir() . '/forma-oauth-' . bin2hex(random_bytes(5));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    throw new RuntimeException('Could not create temporary test directory');
}

define('ROOT_DIR', dirname(__DIR__));
define('DB_FILE', $tmp . '/forma.db');
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'oauth-test.example';
$_SERVER['DOCUMENT_ROOT'] = ROOT_DIR;
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/api/v1/mcp';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
require ROOT_DIR . '/lib/bootstrap.php';

$failure = null;
$expectError = static function (string $error, callable $fn): void {
    try {
        $fn();
    } catch (OAuthException $e) {
        if ($e->oauthError === $error) {
            return;
        }
        throw new RuntimeException("Expected {$error}, got {$e->oauthError}");
    }
    throw new RuntimeException("Expected OAuth error {$error}");
};

try {
    $metadata = AgentOAuth::authorizationServerMetadata();
    if (($metadata['code_challenge_methods_supported'] ?? []) !== ['S256']
        || empty($metadata['registration_endpoint'])
        || empty($metadata['authorization_response_iss_parameter_supported'])
    ) {
        throw new RuntimeException('Authorization metadata is incomplete');
    }
    unset($_SERVER['HTTPS']);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $expectError('invalid_request', static function (): void {
        AgentOAuth::requireSecureTransport();
    });
    $_SERVER['HTTPS'] = 'on';
    $resource = AgentOAuth::resourceUri();
    if (($resource !== 'https://oauth-test.example/api/v1/mcp')
        || (AgentOAuth::protectedResourceMetadata()['resource'] ?? '') !== $resource
    ) {
        throw new RuntimeException('Protected resource metadata is not audience-bound');
    }

    $client = AgentOAuth::register([
        'client_name' => 'Grok test',
        'redirect_uris' => ['https://grok.com/connectors-oauth-exchange-code/'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_method' => 'none',
    ], '203.0.113.10');
    if (!str_starts_with((string)$client['client_id'], 'fxc_')) {
        throw new RuntimeException('DCR did not issue a public client ID');
    }
    $expectError('invalid_redirect_uri', static function (): void {
        AgentOAuth::register([
            'client_name' => 'Bad redirect',
            'redirect_uris' => ['https://good.example/callback#fragment'],
        ], '203.0.113.11');
    });
    $expectError('invalid_scope', static function () use ($client, $resource): void {
        AgentOAuth::authorizationRequest([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://grok.com/connectors-oauth-exchange-code/',
            'scope' => 'settings:write',
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'resource' => $resource,
        ]);
    });

    $verifier = str_repeat('oauth-verifier-', 4);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $request = AgentOAuth::authorizationRequest([
        'response_type' => 'code',
        'client_id' => $client['client_id'],
        'redirect_uri' => 'https://grok.com/connectors-oauth-exchange-code/',
        'scope' => 'content:read content:write media:write site:write rollback:write',
        'state' => 'state-123',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'resource' => $resource,
    ]);
    $successRedirect = AgentOAuth::successRedirect($request, 'sample-code');
    if (!str_contains($successRedirect, 'iss=https%3A%2F%2Foauth-test.example')) {
        throw new RuntimeException('Authorization response issuer is missing');
    }
    $code = AgentOAuth::approve($request, 'admin');
    $expectError('invalid_grant', static function () use ($client, $code, $resource): void {
        AgentOAuth::token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'redirect_uri' => 'https://grok.com/connectors-oauth-exchange-code/',
            'code_verifier' => str_repeat('wrong-verifier-', 4),
            'resource' => $resource,
        ]);
    });

    $tokens = AgentOAuth::token([
        'grant_type' => 'authorization_code',
        'client_id' => $client['client_id'],
        'code' => $code,
        'redirect_uri' => 'https://grok.com/connectors-oauth-exchange-code/',
        'code_verifier' => $verifier,
        'resource' => $resource,
    ]);
    if (($tokens['token_type'] ?? '') !== 'Bearer'
        || !str_starts_with((string)($tokens['access_token'] ?? ''), 'fx_oauth_')
        || empty($tokens['refresh_token'])
    ) {
        throw new RuntimeException('Code exchange did not issue the expected token pair');
    }
    $expectError('invalid_grant', static function () use ($client, $code, $verifier, $resource): void {
        AgentOAuth::token([
            'grant_type' => 'authorization_code',
            'client_id' => $client['client_id'],
            'code' => $code,
            'redirect_uri' => 'https://grok.com/connectors-oauth-exchange-code/',
            'code_verifier' => $verifier,
            'resource' => $resource,
        ]);
    });

    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens['access_token'];
    $authenticated = Agent::authenticate($resource);
    if (($authenticated['oauth_client_id'] ?? '') !== $client['client_id']) {
        throw new RuntimeException('OAuth access token did not enter the normal Agent auth pipeline');
    }

    $rotated = AgentOAuth::token([
        'grant_type' => 'refresh_token',
        'client_id' => $client['client_id'],
        'refresh_token' => $tokens['refresh_token'],
        'resource' => $resource,
    ]);
    if ($rotated['refresh_token'] === $tokens['refresh_token']) {
        throw new RuntimeException('Refresh token was not rotated');
    }
    $oldAccess = Database::get()->queryOne(
        'SELECT revoked_at FROM api_tokens WHERE token_hash = ?',
        [hash('sha256', $tokens['access_token'])]
    );
    if (empty($oldAccess['revoked_at'])) {
        throw new RuntimeException('Previous access token remained active after refresh');
    }

    $expectError('invalid_grant', static function () use ($client, $tokens, $resource): void {
        AgentOAuth::token([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
            'resource' => $resource,
        ]);
    });
    $connection = Database::get()->queryOne(
        'SELECT revoked_at FROM oauth_clients WHERE client_id = ?',
        [$client['client_id']]
    );
    if (empty($connection['revoked_at'])) {
        throw new RuntimeException('Connection revocation failed');
    }

    echo "PASS: HTTPS, discovery, DCR, PKCE, replay defense, access auth, refresh rotation, and revocation\n";
} catch (Throwable $e) {
    $failure = $e;
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmp);
}

if ($failure !== null) {
    exit(1);
}

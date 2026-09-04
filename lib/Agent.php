<?php
/**
 * Forma – Agent API token auth, scopes, audit.
 */
class Agent {
    public const SCOPES = [
        'content:read',
        'content:write',
        'media:write',
        'settings:write',
        'backup:read',
        'podcast:write',
    ];

    public static function createToken(string $name, array $scopes): array {
        $name = trim($name) ?: 'Agent';
        $scopes = array_values(array_intersect($scopes, self::SCOPES));
        if (!$scopes) {
            $scopes = ['content:read', 'content:write'];
        }
        $raw = 'fx_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $raw);
        Database::get()->execute(
            'INSERT INTO api_tokens (name, token_hash, scopes) VALUES (?, ?, ?)',
            [$name, $hash, json_encode($scopes)]
        );
        return [
            'id'     => Database::get()->lastInsertId(),
            'name'   => $name,
            'scopes' => $scopes,
            'token'  => $raw, // shown once
        ];
    }

    public static function listTokens(): array {
        return Database::get()->query(
            'SELECT id, name, scopes, created_at, last_used, revoked_at FROM api_tokens ORDER BY created_at DESC'
        );
    }

    public static function revokeToken(int $id): void {
        Database::get()->execute(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [time(), $id]
        );
    }

    public static function authenticate(): array {
        $raw = self::extractBearerToken();
        if ($raw === '') {
            self::fail(401, 'Missing Bearer token');
        }
        $hash = hash('sha256', $raw);
        $row = Database::get()->queryOne(
            'SELECT * FROM api_tokens WHERE token_hash = ?',
            [$hash]
        );
        if (!$row || !empty($row['revoked_at'])) {
            self::fail(401, 'Invalid or revoked token');
        }

        $sec = Database::get()->getSetting('security');
        if (($sec['agent_https_only'] ?? true)
            && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off')
            && (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https')
            && !self::isLocalRequest()
        ) {
            self::fail(403, 'Agent API requires HTTPS');
        }

        // Simple rate limit: 120 requests / minute / token
        $window = time() - 60;
        $count = Database::get()->queryOne(
            'SELECT COUNT(*) AS c FROM api_audit WHERE token_id = ? AND created_at >= ?',
            [$row['id'], $window]
        );
        if (($count['c'] ?? 0) > 120) {
            self::fail(429, 'Rate limit exceeded');
        }

        Database::get()->execute('UPDATE api_tokens SET last_used = ? WHERE id = ?', [time(), $row['id']]);
        $row['scopes'] = json_decode($row['scopes'] ?: '[]', true) ?: [];
        return $row;
    }

    public static function requireScope(array $token, string $scope): void {
        if (!in_array($scope, $token['scopes'] ?? [], true)) {
            self::fail(403, 'Missing scope: ' . $scope);
        }
    }

    public static function audit(array $token, string $action, string $path = '', string $summary = ''): void {
        Database::get()->execute(
            'INSERT INTO api_audit (token_id, action, path, ip, summary) VALUES (?, ?, ?, ?, ?)',
            [
                $token['id'] ?? null,
                $action,
                $path,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $summary,
            ]
        );
    }

    public static function helpDocument(): array {
        $how = [
            'storage' => 'Single SQLite file (database/forma.db). Portable.',
            'pages' => 'HTML/Twig or Markdown in pages table. Full HTML docs are served as-is; META block holds slug + SEO fields.',
            'posts' => 'Markdown blog posts. Public only when published_at <= now. SEO in seo_json.',
            'snippets' => 'Reusable HTML/Twig fragments via [[shortcode]] in content.',
            'uploads' => 'Files in /uploads; referenced as uploads/filename.',
            'seo' => 'Sitewide Settings→SEO. Public /robots.txt + /sitemap.xml + /llms.txt auto-generated (or manual bodies). Meta tags + JSON-LD (incl. breadcrumbs) on render.',
            'admin' => 'htmx admin at /admin/. Agents should prefer this API over scraping admin HTML.',
            'uptime' => 'GET /up (no auth) JSON {ok,php,version,ts,fallback}. Static stamp: /fallback/php-ok.json. If stamp is 200 but /up is “No input file specified”, PHP/FastCGI is down.',
            'html_cache' => 'Settings→Cache "HTML cache" (cache.static_fallback) writes every page/post/episode to fallback/*.html on every save; Apache serves those files directly (see fallback.marker in /up). Paths without a built file fall through to a live PHP render. "Rebuild HTML cache" rebuilds everything + the search index in one pass.',
            'publish' => 'Compatibility alias for html_cache.',
            'search' => 'GET /search?q=… — SQLite FTS5 (or LIKE fallback) over pages + published posts + licensed podcast episodes. htmx fragment when header HX-Request: true, full page otherwise. Always PHP, never published as a static file, always noindex. The [[search]] snippet renders the box.',
            'docs' => 'See AGENTS.md and README.md in the Forma project root.',
        ];
        return [
            'product' => defined('FORMA_PRODUCT') ? FORMA_PRODUCT : 'Forma',
            'version' => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'auth' => [
                'header' => 'Authorization: Bearer fx_…',
                'alt_headers' => ['X-Forma-Token: fx_…', 'X-Api-Key: fx_…'],
                'scopes' => self::SCOPES,
            ],
            'how_forma_works' => $how,
            'how_formax_works' => $how, // alias — older agents look for this key

            'endpoints' => [
                ['GET', '/api/v1/help', 'This document', null],
                ['GET', '/api/v1/site', 'Site + SEO summary', 'content:read'],
                ['GET', '/api/v1/pages', 'List pages', 'content:read'],
                ['GET', '/api/v1/pages/{filename}', 'Get page (+ meta/seo)', 'content:read'],
                ['PUT', '/api/v1/pages/{filename}', 'Create/update page {content,content_type,slug,seo{…}}', 'content:write'],
                ['DELETE', '/api/v1/pages/{filename}', 'Delete page', 'content:write'],
                ['GET', '/api/v1/posts', 'List posts', 'content:read'],
                ['GET', '/api/v1/posts/{filename}', 'Get post', 'content:read'],
                ['PUT', '/api/v1/posts/{filename}', 'Create/update post (+ seo fields)', 'content:write'],
                ['DELETE', '/api/v1/posts/{filename}', 'Delete post', 'content:write'],
                ['GET', '/api/v1/snippets', 'List snippets', 'content:read'],
                ['GET', '/api/v1/snippets/{filename}', 'Get snippet', 'content:read'],
                ['PUT', '/api/v1/snippets/{filename}', 'Save snippet {shortcode,content}', 'content:write'],
                ['DELETE', '/api/v1/snippets/{filename}', 'Delete snippet', 'content:write'],
                ['GET', '/api/v1/media', 'List uploads', 'content:read'],
                ['POST', '/api/v1/media', 'Upload multipart file=… OR JSON {filename,content_base64}', 'media:write'],
                ['DELETE', '/api/v1/media/{filename}', 'Delete upload', 'media:write'],
                ['GET', '/api/v1/settings', 'All settings', 'content:read'],
                ['GET', '/api/v1/settings/{section}', 'Get settings section (site,blog,seo,cache,…)', 'content:read'],
                ['PUT', '/api/v1/settings/{section}', 'Merge-update settings section', 'settings:write'],
                ['GET', '/api/v1/seo', 'SEO settings + robots preview', 'content:read'],
                ['PUT', '/api/v1/seo', 'Update SEO settings', 'settings:write'],
                ['POST', '/api/v1/cache/flush', 'Flush PHP cache', 'settings:write'],
                ['GET', '/api/v1/export', 'Versioned JSON export (no binaries)', 'backup:read'],
                ['GET', '/api/v1/export/site', 'Full site package zip (DB + uploads + manifest)', 'backup:read'],
                ['POST', '/api/v1/import/site', 'Restore site package (multipart package=.zip)', 'settings:write'],
                ['GET', '/api/v1/episodes', 'List podcast episodes', 'content:read'],
                ['PUT', '/api/v1/episodes/{id}', 'Save episode', 'podcast:write'],
                ['DELETE', '/api/v1/episodes/{id}', 'Delete episode', 'podcast:write'],
                ['GET', '/search?q=…', 'Public site search (not under /api/v1, no auth)', null],
            ],
            'seo_fields' => Seo::PAGE_META_KEYS,
            'site_package' => [
                'format' => SitePackage::FORMAT,
                'format_version' => SitePackage::FORMAT_VERSION,
                'schema_version' => SitePackage::SCHEMA_VERSION,
            ],
            'public_urls' => ['/up', '/search', '/robots.txt', '/sitemap.xml', '/llms.txt', '/feed.xml', '/feed.json', '/fallback/php-ok.json'],
        ];
    }

    public static function json($data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        $ver = defined('FORMA_VERSION') ? FORMA_VERSION : '0';
        header('X-Forma-Version: ' . $ver);
        header('X-FormaX-Version: ' . $ver); // alias for older clients
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function fail(int $code, string $message): void {
        self::json(['error' => $message], $code);
    }

    private static function isLocalRequest(): bool {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1'], true)
            || str_starts_with($ip, '192.168.')
            || str_starts_with($ip, '10.');
    }

    /** Read Bearer token across Apache CGI / DreamHost quirks. */
    private static function extractBearerToken(): string {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['HTTP_X_FORMA_TOKEN'] ?? '',
            $_SERVER['HTTP_X_API_KEY'] ?? '',
        ];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() ?: [] as $k => $v) {
                if (strcasecmp((string)$k, 'Authorization') === 0
                    || strcasecmp((string)$k, 'X-Forma-Token') === 0
                    || strcasecmp((string)$k, 'X-Api-Key') === 0
                ) {
                    $candidates[] = (string)$v;
                }
            }
        }
        foreach ($candidates as $hdr) {
            $hdr = trim((string)$hdr);
            if ($hdr === '') {
                continue;
            }
            if (preg_match('/^Bearer\s+(\S+)/i', $hdr, $m)) {
                return $m[1];
            }
            // Bare token in X-Forma-Token / X-Api-Key
            if (str_starts_with($hdr, 'fx_')) {
                return $hdr;
            }
        }
        return '';
    }
}

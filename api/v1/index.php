<?php
/**
 * Forma Agent API v1 — JSON, Bearer token, scoped.
 * Mounted from front controller at /api/v1/*
 *
 * Discoverability: GET /api/v1/help  (requires any valid token)
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
$prefix = '/api/v1';
$rel = substr($uriPath, strlen($prefix));
$rel = '/' . trim((string)$rel, '/');
if ($rel === '/') {
    $rel = '';
}

header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Forma-Token, X-Api-Key');
header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
$requestOrigin = !empty($_SERVER['HTTP_ORIGIN']) ? parse_url((string)$_SERVER['HTTP_ORIGIN']) : null;
$siteOrigin = parse_url(forma_public_url('/'));
$requestPort = is_array($requestOrigin)
    ? (int)($requestOrigin['port'] ?? (($requestOrigin['scheme'] ?? '') === 'https' ? 443 : 80))
    : 0;
$sitePort = is_array($siteOrigin)
    ? (int)($siteOrigin['port'] ?? (($siteOrigin['scheme'] ?? '') === 'https' ? 443 : 80))
    : 0;
$sameOrigin = is_array($requestOrigin) && is_array($siteOrigin)
    && strtolower((string)($requestOrigin['scheme'] ?? '')) === strtolower((string)($siteOrigin['scheme'] ?? ''))
    && strtolower((string)($requestOrigin['host'] ?? '')) === strtolower((string)($siteOrigin['host'] ?? ''))
    && $requestPort === $sitePort;
if ($sameOrigin) {
    header('Access-Control-Allow-Origin: ' . (string)$_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
}
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Public contract imported by ChatGPT Actions, generated as JSON or YAML.
if (in_array($rel, ['/openapi.json', '/openapi.yaml'], true) && $method === 'GET') {
    header('Content-Type: ' . ($rel === '/openapi.yaml' ? 'application/yaml' : 'application/json') . '; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    echo $rel === '/openapi.yaml'
        ? AgentOpenApi::yaml()
        : json_encode(AgentOpenApi::document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Streamable HTTP requires Origin validation to prevent browser DNS rebinding.
// Cloud connectors normally make server-to-server requests with no Origin.
if ($rel === '/mcp' && !empty($_SERVER['HTTP_ORIGIN'])) {
    if (!$sameOrigin) {
        Agent::fail(403, 'MCP Origin is not allowed');
    }
}

$token = Agent::authenticate($rel === '/mcp' ? AgentOAuth::resourceUri() : null);
$body = [];
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

try {
    // Stateless MCP Streamable HTTP for Claude/ChatGPT/Grok cloud connectors.
    if ($rel === '/mcp' && $method === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        header('MCP-Protocol-Version: 2025-06-18');
        $response = AgentMcp::handle($token, $body);
        if ($response === null) {
            http_response_code(202);
            exit;
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($rel === '/mcp') {
        header('Allow: POST, OPTIONS');
        Agent::fail(405, 'Remote MCP uses POST (Streamable HTTP)');
    }

    // GET /api/v1/help — full map for agents
    if ($rel === '/help' && $method === 'GET') {
        Agent::audit($token, 'help', $rel);
        Agent::json(Agent::helpDocument($token));
    }

    // GET /api/v1/site  or  /api/v1
    if (($rel === '' || $rel === '/site') && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::audit($token, 'site.read', $rel);
        $cfg = Database::get()->getConfig();
        Agent::json([
            'product' => FORMA_PRODUCT,
            'version' => FORMA_VERSION,
            'site'    => $cfg['site'] ?? [],
            'seo'     => Seo::settings(),
            'podcast_licensed' => License::isPodcastLicensed(),
            'help'    => '/api/v1/help',
            'public'  => [
                'robots'  => '/robots.txt',
                'sitemap' => '/sitemap.xml',
                'llms'    => '/llms.txt',
                'feed_rss'=> '/feed.xml',
                'feed_json'=> '/feed.json',
            ],
        ]);
    }

    // Curated public identity settings for Site editor tokens. Canonical site
    // URL and security-sensitive sections remain settings:write only.
    if ($rel === '/site-settings' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        $site = Database::get()->getSetting('site');
        unset($site['url']);
        Agent::json(['site' => $site]);
    }
    if ($rel === '/site-settings' && ($method === 'PUT' || $method === 'POST')) {
        Agent::requireAnyScope($token, ['site:write', 'settings:write']);
        $value = $body['value'] ?? $body;
        if (!is_array($value)) {
            Agent::fail(400, 'value must be object');
        }
        $allowed = ['title', 'description', 'timezone', 'language', 'default_author'];
        $safe = array_intersect_key($value, array_flip($allowed));
        if (!$safe) {
            Agent::fail(400, 'No supported site fields supplied');
        }
        foreach ($safe as $key => $fieldValue) {
            if (!is_scalar($fieldValue) && $fieldValue !== null) {
                Agent::fail(400, $key . ' must be a string');
            }
            $safe[$key] = trim((string)$fieldValue);
        }
        AgentCheckpoint::beforeMutation($token);
        $merged = array_merge(Database::get()->getSetting('site'), $safe);
        Database::get()->saveSetting('site', $merged);
        Database::get()->flushCache();
        Feed::maybeRegenerateBlog();
        Feed::maybeRegeneratePodcast();
        StaticFallback::republishIfEnabled();
        unset($merged['url']);
        Agent::audit($token, 'site-settings.save', $rel);
        Agent::json(['success' => true, 'site' => $merged, 'checkpoint' => AgentCheckpoint::status()]);
    }

    // GET /api/v1/health — filesystem sanity (diagnose bad FTP / nested folders)
    if ($rel === '/health' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        $checks = [
            'lib/Seo.php'           => is_file(ROOT_DIR . '/lib/Seo.php'),
            'lib/Render.php'        => is_file(ROOT_DIR . '/lib/Render.php'),
            'lib/Parsedown.php'     => is_file(ROOT_DIR . '/lib/Parsedown.php'),
            'lib/Twig/init.php'     => is_file(ROOT_DIR . '/lib/Twig/init.php'),
            'lib/Twig/Environment.php' => is_file(ROOT_DIR . '/lib/Twig/Environment.php'),
            'lib/lib/ (nested bad)' => is_dir(ROOT_DIR . '/lib/lib'),
            'admin/admin/ (nested bad)' => is_dir(ROOT_DIR . '/admin/admin'),
            'admin/css/core.css'    => is_file(ROOT_DIR . '/admin/css/core.css'),
            'admin/js/admin.js'     => is_file(ROOT_DIR . '/admin/js/admin.js'),
            'admin/actions/server-fix.php' => is_file(ROOT_DIR . '/admin/actions/server-fix.php'),
            'robots.txt (placeholder)' => is_file(ROOT_DIR . '/robots.txt'),
            '.htaccess'             => is_file(ROOT_DIR . '/.htaccess'),
        ];
        $ok = !empty($checks['lib/Twig/init.php'])
            && !empty($checks['admin/css/core.css'])
            && empty($checks['lib/lib/ (nested bad)'])
            && empty($checks['admin/admin/ (nested bad)']);
        $hint = $ok ? 'Filesystem looks good' : 'Fix nested folders (lib/lib or admin/admin) and re-upload missing paths from local Forma. Do not drag a folder into a same-named folder.';
        if ($ok && empty($checks['robots.txt (placeholder)'])) {
            $hint = 'Write a robots.txt placeholder — some hosts inject a default when the file is missing.';
        }
        Agent::json([
            'ok' => $ok,
            'root' => ROOT_DIR,
            'checks' => $checks,
            'hint' => $hint,
        ]);
    }

    // One last-known-good rollback point for live agent edits.
    if ($rel === '/checkpoint' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::audit($token, 'checkpoint.read', $rel);
        Agent::json(['checkpoint' => AgentCheckpoint::status()]);
    }
    if ($rel === '/checkpoint/restore' && $method === 'POST') {
        Agent::requireScope($token, 'rollback:write');
        $result = AgentCheckpoint::restore((string)($token['name'] ?? 'Agent'));
        Agent::audit($token, 'checkpoint.restore', $rel);
        Agent::json($result);
    }
    if ($rel === '/checkpoint/accept' && $method === 'POST') {
        Agent::requireScope($token, 'rollback:write');
        $result = AgentCheckpoint::accept((string)($token['name'] ?? 'Agent'));
        Agent::audit($token, 'checkpoint.accept', $rel);
        Agent::json(['success' => true, 'checkpoint' => $result]);
    }

    // Pages
    if ($rel === '/pages' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::audit($token, 'pages.list', $rel);
        $pages = PageRepo::list();
        foreach ($pages as &$p) {
            $full = PageRepo::get($p['filename'] ?? '');
            if (!$full) {
                continue;
            }
            $health = Seo::quickHealth(Seo::forPage($full), (string)($full['content'] ?? ''));
            $p['seo_ok'] = $health['ok'];
            $p['seo_issues'] = $health['issues'];
        }
        unset($p);
        Agent::json(['pages' => $pages]);
    }

    if (preg_match('#^/pages/([a-zA-Z0-9._-]+)$#', $rel, $m)) {
        $filename = $m[1];
        if ($method === 'GET') {
            Agent::requireScope($token, 'content:read');
            $row = PageRepo::get($filename);
            if (!$row) {
                Agent::fail(404, 'Page not found');
            }
            $row['meta'] = PageRepo::extractMeta($row['content'] ?? '');
            $row['seo'] = Seo::forPage($row);
            Agent::audit($token, 'pages.get', $rel, $filename);
            Agent::json($row);
        }
        if ($method === 'PUT' || $method === 'POST') {
            Agent::requireScope($token, 'content:write');
            AgentCheckpoint::beforeMutation($token);
            $extra = [];
            if (isset($body['seo']) && is_array($body['seo'])) {
                foreach (Seo::PAGE_META_KEYS as $k) {
                    if (array_key_exists($k, $body['seo'])) {
                        $extra[$k] = (string)$body['seo'][$k];
                    }
                }
            }
            foreach (Seo::PAGE_META_KEYS as $k) {
                if (array_key_exists($k, $body)) {
                    $extra[$k] = (string)$body[$k];
                }
            }
            if (!empty($body['title'])) {
                $extra['title'] = (string)$body['title'];
            }
            $row = PageRepo::save(
                $filename,
                $body['content'] ?? (PageRepo::get($filename)['content'] ?? ''),
                $body['content_type'] ?? 'html',
                $body['slug'] ?? null,
                $extra
            );
            $warnings = $row['_warnings'] ?? [];
            unset($row['_warnings']);
            Agent::audit($token, 'pages.save', $rel, $filename);
            Agent::json(['success' => true, 'page' => $row, 'warnings' => $warnings, 'checkpoint' => AgentCheckpoint::status()]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:delete');
            AgentCheckpoint::beforeMutation($token);
            PageRepo::delete($filename);
            Agent::audit($token, 'pages.delete', $rel, $filename);
            Agent::json(['success' => true]);
        }
    }

    // Posts
    if ($rel === '/posts' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::audit($token, 'posts.list', $rel);
        $posts = BlogRepo::list(false);
        foreach ($posts as &$p) {
            $health = Seo::quickHealth(Seo::forPost($p));
            $p['seo_ok'] = $health['ok'];
            $p['seo_issues'] = $health['issues'];
        }
        unset($p);
        Agent::json(['posts' => $posts]);
    }

    if (preg_match('#^/posts/([a-zA-Z0-9._-]+)$#', $rel, $m)) {
        $filename = $m[1];
        if ($method === 'GET') {
            Agent::requireScope($token, 'content:read');
            $row = BlogRepo::get($filename);
            if (!$row) {
                Agent::fail(404, 'Post not found');
            }
            $row['seo'] = json_decode($row['seo_json'] ?? '{}', true) ?: [];
            Agent::audit($token, 'posts.get', $rel, $filename);
            Agent::json($row);
        }
        if ($method === 'PUT' || $method === 'POST') {
            Agent::requireScope($token, 'content:write');
            AgentCheckpoint::beforeMutation($token);
            $data = $body;
            $data['filename'] = $filename;
            $row = BlogRepo::save($data);
            $warnings = $row['_warnings'] ?? [];
            unset($row['_warnings']);
            Agent::audit($token, 'posts.save', $rel, $filename);
            Agent::json(['success' => true, 'post' => $row, 'warnings' => $warnings, 'checkpoint' => AgentCheckpoint::status()]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:delete');
            AgentCheckpoint::beforeMutation($token);
            BlogRepo::delete($filename);
            Agent::audit($token, 'posts.delete', $rel, $filename);
            Agent::json(['success' => true]);
        }
    }

    // Snippets
    if ($rel === '/snippets' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json(['snippets' => SnippetRepo::list()]);
    }

    if (preg_match('#^/snippets/([a-zA-Z0-9._-]+)$#', $rel, $m)) {
        $filename = $m[1];
        if ($method === 'GET') {
            Agent::requireScope($token, 'content:read');
            $row = SnippetRepo::get($filename);
            if (!$row) {
                Agent::fail(404, 'Snippet not found');
            }
            Agent::json($row);
        }
        if ($method === 'PUT' || $method === 'POST') {
            Agent::requireScope($token, 'content:write');
            AgentCheckpoint::beforeMutation($token);
            $row = SnippetRepo::save($filename, $body['shortcode'] ?? $filename, $body['content'] ?? '');
            Agent::audit($token, 'snippets.save', $rel);
            Agent::json(['success' => true, 'snippet' => $row, 'checkpoint' => AgentCheckpoint::status()]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:delete');
            AgentCheckpoint::beforeMutation($token);
            SnippetRepo::delete($filename);
            Agent::audit($token, 'snippets.delete', $rel);
            Agent::json(['success' => true]);
        }
    }

    // Media
    if ($rel === '/media' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json(['files' => MediaRepo::list()]);
    }

    if ($rel === '/media' && $method === 'POST') {
        Agent::requireScope($token, 'media:write');
        AgentCheckpoint::beforeMutation($token);
        // JSON base64 upload for agent tooling that can't multipart easily
        if (!empty($body['filename']) && !empty($body['content_base64'])) {
            $saved = MediaRepo::saveBase64(
                (string)$body['filename'],
                (string)$body['content_base64'],
                (string)($body['content_type'] ?? 'application/octet-stream')
            );
            Agent::audit($token, 'media.upload', $rel, $saved['filename']);
            Agent::json(['success' => true, 'file' => $saved, 'checkpoint' => AgentCheckpoint::status()]);
        }
        if (empty($_FILES['file'])) {
            Agent::fail(400, 'multipart file required (or JSON filename + content_base64)');
        }
        $saved = MediaRepo::saveUpload($_FILES['file']);
        Agent::audit($token, 'media.upload', $rel, $saved['filename']);
        Agent::json(['success' => true, 'file' => $saved, 'checkpoint' => AgentCheckpoint::status()]);
    }

    if (preg_match('#^/media/([a-zA-Z0-9._-]+)$#', $rel, $m) && $method === 'DELETE') {
        Agent::requireScope($token, 'media:delete');
        AgentCheckpoint::beforeMutation($token);
        AgentCheckpoint::preserveDeletedMedia($m[1]);
        MediaRepo::delete($m[1]);
        Agent::audit($token, 'media.delete', $rel, $m[1]);
        Agent::json(['success' => true]);
    }

    // Cache
    if ($rel === '/cache/flush' && $method === 'POST') {
        Agent::requireScope($token, 'settings:write');
        Database::get()->flushCache();
        Agent::audit($token, 'cache.flush', $rel);
        Agent::json(['success' => true]);
    }

    // Settings list
    if ($rel === '/settings' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        $cfg = Database::get()->getConfig();
        unset($cfg['security']); // never expose security blob wholesale if sensitive — still ok for agent with token
        Agent::json(['settings' => $cfg]);
    }

    if (preg_match('#^/settings/([a-zA-Z0-9_-]+)$#', $rel, $m)) {
        $section = $m[1];
        if ($method === 'GET') {
            Agent::requireScope($token, 'content:read');
            Agent::json(['section' => $section, 'value' => Database::get()->getSetting($section)]);
        }
        if ($method === 'PUT' || $method === 'POST') {
            Agent::requireScope($token, 'settings:write');
            AgentCheckpoint::beforeMutation($token);
            $value = $body['value'] ?? $body;
            if (!is_array($value)) {
                Agent::fail(400, 'value must be object');
            }
            // Don't allow nested 'value' key confusion when body is the section itself
            if (isset($body['value']) && is_array($body['value']) && count($body) === 1) {
                $value = $body['value'];
            } elseif (isset($body['section'])) {
                unset($value['section']);
            }
            $merged = array_merge(Database::get()->getSetting($section), $value);
            Database::get()->saveSetting($section, $merged);
            if ($section === 'blog') {
                Feed::maybeRegenerateBlog();
            }
            if ($section === 'podcast') {
                Feed::maybeRegeneratePodcast();
            }
            Database::get()->flushCache();
            if ($section === 'cache') {
                Htaccess::ensureStaticFallbackRules();
                Htaccess::ensureFastCgiSafeFrontController();
                if (!empty($merged['static_fallback'])) {
                    StaticFallback::enable();
                } else {
                    StaticFallback::disable();
                }
            } elseif (in_array($section, ['site', 'seo', 'blog', 'podcast'], true)) {
                StaticFallback::republishIfEnabled();
            }
            Agent::audit($token, 'settings.save', $rel, $section);
            Agent::json(['success' => true, 'value' => $merged]);
        }
    }

    // SEO convenience
    if ($rel === '/seo' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json([
            'seo' => Seo::settings(),
            'health' => Seo::healthReport(),
            'robots_txt' => Seo::robotsTxt(),
            'sitemap_xml' => Seo::sitemapXml(),
            'llms_txt' => Seo::llmsTxt(),
            'sitemap_url' => Seo::siteUrl() . '/sitemap.xml',
            'redirects' => RedirectRepo::list(),
        ]);
    }
    if ($rel === '/seo' && ($method === 'PUT' || $method === 'POST')) {
        Agent::requireAnyScope($token, ['site:write', 'settings:write']);
        AgentCheckpoint::beforeMutation($token);
        $value = $body['value'] ?? $body;
        if (!is_array($value)) {
            Agent::fail(400, 'value must be object');
        }
        $merged = array_merge(Seo::settings(), $value);
        // coerce booleans from JSON
        foreach ([
            'robots_auto','robots_index','robots_follow',
            'sitemap_auto','sitemap_enabled','sitemap_include_pages',
            'sitemap_include_posts','sitemap_include_podcast','sitemap_include_images',
            'llms_auto','llms_enabled',
            'title_suffix', 'json_ld_website','json_ld_organization',
        ] as $boolKey) {
            if (array_key_exists($boolKey, $value)) {
                $merged[$boolKey] = filter_var($value[$boolKey], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $merged = Seo::normalizeSettings($merged);
        Database::get()->saveSetting('seo', $merged);
        Database::get()->flushCache();
        StaticFallback::republishIfEnabled();
        Agent::audit($token, 'seo.save', $rel);
        Agent::json([
            'success' => true,
            'value' => $merged,
            'health' => Seo::healthReport(),
            'robots_txt' => Seo::robotsTxt($merged),
            'sitemap_xml' => Seo::sitemapXml($merged),
            'llms_txt' => Seo::llmsTxt($merged),
            'checkpoint' => AgentCheckpoint::status(),
        ]);
    }

    // Redirects CRUD
    if ($rel === '/redirects' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json(['redirects' => RedirectRepo::list()]);
    }
    if ($rel === '/redirects' && ($method === 'PUT' || $method === 'POST')) {
        Agent::requireScope($token, 'settings:write');
        AgentCheckpoint::beforeMutation($token);
        $data = $body['value'] ?? $body;
        if (!is_array($data)) {
            Agent::fail(400, 'value must be object');
        }
        try {
            $row = RedirectRepo::save($data);
            Database::get()->flushCache();
            Agent::audit($token, 'redirects.save', $rel, (string)($row['id'] ?? ''));
            Agent::json(['success' => true, 'redirect' => $row]);
        } catch (Throwable $e) {
            Agent::fail(400, $e->getMessage());
        }
    }
    if (preg_match('#^/redirects/(\d+)$#', $rel, $rm) && $method === 'DELETE') {
        Agent::requireScope($token, 'settings:write');
        AgentCheckpoint::beforeMutation($token);
        RedirectRepo::delete((int)$rm[1]);
        Database::get()->flushCache();
        Agent::audit($token, 'redirects.delete', $rel, $rm[1]);
        Agent::json(['success' => true]);
    }

    // Export — versioned JSON (no binaries)
    if ($rel === '/export' && $method === 'GET') {
        Agent::requireScope($token, 'backup:read');
        Agent::audit($token, 'export', $rel);
        Agent::json(SitePackage::buildDataJson());
    }

    // Full site package zip (DB + uploads + manifest)
    if (($rel === '/export/site' || $rel === '/export/package') && $method === 'GET') {
        Agent::requireScope($token, 'backup:read');
        Agent::audit($token, 'export.site', $rel);
        try {
            SitePackage::streamZipDownload();
        } catch (Throwable $e) {
            Agent::fail(500, $e->getMessage());
        }
        exit;
    }

    // Import site package (multipart package=… zip, or JSON {path} not supported — use multipart)
    if (($rel === '/import/site' || $rel === '/import/package') && $method === 'POST') {
        Agent::requireScope($token, 'settings:write');
        AgentCheckpoint::beforeMutation($token);
        if (empty($_FILES['package']['tmp_name']) || !is_uploaded_file($_FILES['package']['tmp_name'])) {
            Agent::fail(400, 'multipart field "package" (.zip) required');
        }
        $replaceDb = !array_key_exists('replace_database', $_POST) || filter_var($_POST['replace_database'], FILTER_VALIDATE_BOOLEAN);
        $mergeUploads = !array_key_exists('merge_uploads', $_POST) || filter_var($_POST['merge_uploads'], FILTER_VALIDATE_BOOLEAN);
        try {
            $result = SitePackage::importZip($_FILES['package']['tmp_name'], $replaceDb, $mergeUploads);
            Agent::audit($token, 'import.site', $rel);
            Agent::json($result);
        } catch (Throwable $e) {
            Agent::fail(400, $e->getMessage());
        }
    }

    // Podcast episodes
    if ($rel === '/episodes' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json(['episodes' => PodcastRepo::list()]);
    }

    if (preg_match('#^/episodes/([a-zA-Z0-9._-]+)$#', $rel, $m)) {
        $id = $m[1];
        if ($method === 'GET') {
            Agent::requireScope($token, 'content:read');
            $row = PodcastRepo::get($id);
            if (!$row) {
                Agent::fail(404, 'Episode not found');
            }
            Agent::json($row);
        }
        if ($method === 'PUT' || $method === 'POST') {
            Agent::requireScope($token, 'podcast:write');
            AgentCheckpoint::beforeMutation($token);
            $data = $body;
            $data['episode_id'] = $id;
            $row = PodcastRepo::save($data);
            Agent::audit($token, 'episodes.save', $rel);
            Agent::json(['success' => true, 'episode' => $row]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'podcast:write');
            AgentCheckpoint::beforeMutation($token);
            PodcastRepo::delete($id);
            Agent::audit($token, 'episodes.delete', $rel);
            Agent::json(['success' => true]);
        }
    }

    Agent::fail(404, 'Not found: ' . $rel);
} catch (Throwable $e) {
    Agent::fail(400, $e->getMessage());
}

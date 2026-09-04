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

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Forma-Token, X-Api-Key');
header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$token = Agent::authenticate();
$body = [];
$raw = file_get_contents('php://input') ?: '';
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}

try {
    // GET /api/v1/help — full map for agents
    if ($rel === '/help' && $method === 'GET') {
        Agent::audit($token, 'help', $rel);
        Agent::json(Agent::helpDocument());
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
            'robots.txt (static)'   => is_file(ROOT_DIR . '/robots.txt'),
            '.htaccess'             => is_file(ROOT_DIR . '/.htaccess'),
        ];
        $ok = !empty($checks['lib/Twig/init.php'])
            && !empty($checks['admin/css/core.css'])
            && empty($checks['lib/lib/ (nested bad)'])
            && empty($checks['admin/admin/ (nested bad)']);
        Agent::json([
            'ok' => $ok,
            'root' => ROOT_DIR,
            'checks' => $checks,
            'hint' => $ok
                ? 'Filesystem looks good'
                : 'Fix nested folders (lib/lib or admin/admin) and re-upload missing paths from local Forma. Do not drag a folder into a same-named folder.',
        ]);
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
            Agent::json(['success' => true, 'page' => $row, 'warnings' => $warnings]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:write');
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
            $data = $body;
            $data['filename'] = $filename;
            $row = BlogRepo::save($data);
            $warnings = $row['_warnings'] ?? [];
            unset($row['_warnings']);
            Agent::audit($token, 'posts.save', $rel, $filename);
            Agent::json(['success' => true, 'post' => $row, 'warnings' => $warnings]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:write');
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
            $row = SnippetRepo::save($filename, $body['shortcode'] ?? $filename, $body['content'] ?? '');
            Agent::audit($token, 'snippets.save', $rel);
            Agent::json(['success' => true, 'snippet' => $row]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'content:write');
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
        // JSON base64 upload for agent tooling that can't multipart easily
        if (!empty($body['filename']) && !empty($body['content_base64'])) {
            $bin = base64_decode((string)$body['content_base64'], true);
            if ($bin === false) {
                Agent::fail(400, 'Invalid base64');
            }
            $tmp = tempnam(sys_get_temp_dir(), 'fxup');
            file_put_contents($tmp, $bin);
            $fake = [
                'name' => basename((string)$body['filename']),
                'type' => $body['content_type'] ?? 'application/octet-stream',
                'tmp_name' => $tmp,
                'error' => UPLOAD_ERR_OK,
                'size' => strlen($bin),
            ];
            // MediaRepo::saveUpload uses move_uploaded_file — won't work on tempnam.
            // Write directly with allowed-type checks.
            $saved = self_agent_store_media($fake);
            @unlink($tmp);
            Agent::audit($token, 'media.upload', $rel, $saved['filename']);
            Agent::json(['success' => true, 'file' => $saved]);
        }
        if (empty($_FILES['file'])) {
            Agent::fail(400, 'multipart file required (or JSON filename + content_base64)');
        }
        $saved = MediaRepo::saveUpload($_FILES['file']);
        Agent::audit($token, 'media.upload', $rel, $saved['filename']);
        Agent::json(['success' => true, 'file' => $saved]);
    }

    if (preg_match('#^/media/([a-zA-Z0-9._-]+)$#', $rel, $m) && $method === 'DELETE') {
        Agent::requireScope($token, 'media:write');
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
        Agent::requireScope($token, 'settings:write');
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
        Agent::audit($token, 'seo.save', $rel);
        Agent::json([
            'success' => true,
            'value' => $merged,
            'health' => Seo::healthReport(),
            'robots_txt' => Seo::robotsTxt($merged),
            'sitemap_xml' => Seo::sitemapXml($merged),
            'llms_txt' => Seo::llmsTxt($merged),
        ]);
    }

    // Redirects CRUD
    if ($rel === '/redirects' && $method === 'GET') {
        Agent::requireScope($token, 'content:read');
        Agent::json(['redirects' => RedirectRepo::list()]);
    }
    if ($rel === '/redirects' && ($method === 'PUT' || $method === 'POST')) {
        Agent::requireScope($token, 'settings:write');
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
            $data = $body;
            $data['episode_id'] = $id;
            $row = PodcastRepo::save($data);
            Agent::audit($token, 'episodes.save', $rel);
            Agent::json(['success' => true, 'episode' => $row]);
        }
        if ($method === 'DELETE') {
            Agent::requireScope($token, 'podcast:write');
            PodcastRepo::delete($id);
            Agent::audit($token, 'episodes.delete', $rel);
            Agent::json(['success' => true]);
        }
    }

    Agent::fail(404, 'Not found: ' . $rel);
} catch (Throwable $e) {
    Agent::fail(400, $e->getMessage());
}

/** Store media from a local temp file (agent base64 path). */
function self_agent_store_media(array $file): array {
    $sec = Database::get()->getSetting('security');
    $max = (int)($sec['max_upload_size'] ?? 52428800);
    if (($file['size'] ?? 0) > $max) {
        throw new RuntimeException('File too large');
    }
    $name = basename($file['name'] ?? 'file');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = $sec['allowed_upload_types'] ?? [];
    if ($ext === '' || !in_array($ext, $allowed, true)) {
        throw new RuntimeException('File type not allowed: ' . $ext);
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', pathinfo($name, PATHINFO_FILENAME)) ?? 'file';
    $destName = $safe . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '.' . $ext;
    if (!is_dir(UPLOADS_DIR)) {
        mkdir(UPLOADS_DIR, 0755, true);
    }
    $dest = UPLOADS_DIR . '/' . $destName;
    if (!@rename($file['tmp_name'], $dest) && !@copy($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not store upload');
    }
    @unlink($file['tmp_name']);
    // tempnam() creates the source at mode 0600; rename() preserves that mode, which
    // leaves Apache unable to serve the file directly (403) when PHP runs as a different
    // user than the static-file-serving process. Normalize to a world-readable file.
    @chmod($dest, 0644);
    return [
        'filename' => $destName,
        'url'      => forma_uploads_web_url($destName),
        'size'     => filesize($dest),
    ];
}

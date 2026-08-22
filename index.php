<?php
/**
 * Forma – Front controller
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('ROOT_DIR', __DIR__);
require_once ROOT_DIR . '/lib/bootstrap.php';

try {
    $db = Database::get();
    $config = $db->getConfig();

    $basePath = forma_site_base_path();
    $reqPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path     = ($reqPath !== null && $reqPath !== false)
        ? rtrim(str_replace('\\', '/', $reqPath), '/')
        : '';
    if ($basePath !== '' && str_starts_with($path, $basePath)) {
        $path = substr($path, strlen($basePath));
    }
    if ($path === '' || $path === false) {
        $path = '/';
    }
    if ($path === '/index.php') {
        $path = '/';
    }

    // Public heartbeat — always PHP, never cached. Monitors: /up dead + /fallback/php-ok.json 200 = FastCGI down.
    if ($path === '/up') {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        http_response_code(200);
        StaticFallback::writeStamp();
        StaticFallback::refreshHomeIfStale();
        $st = StaticFallback::status();
        echo json_encode([
            'ok'      => true,
            'php'     => PHP_VERSION,
            'product' => defined('FORMA_PRODUCT') ? FORMA_PRODUCT : 'Forma',
            'version' => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'ts'      => time(),
            'fallback'=> $st,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    StaticFallback::writeStamp();

    // URL redirects (before pages / cache)
    if (!str_starts_with($path, '/admin') && !str_starts_with($path, '/api/')) {
        $redir = RedirectRepo::match($path === '' ? '/' : $path);
        if ($redir) {
            $dest = trim((string)$redir['to_url']);
            if ($dest !== '' && !preg_match('#^https?://#i', $dest)) {
                $dest = Seo::absoluteUrl($dest);
            }
            $code = (int)($redir['status'] ?? 301);
            if (!in_array($code, [301, 302, 307, 308], true)) {
                $code = 301;
            }
            http_response_code($code);
            header('Location: ' . $dest);
            header('Cache-Control: no-cache');
            exit;
        }
    }

    // Agent API
    if (str_starts_with($path, '/api/v1')) {
        require ROOT_DIR . '/api/v1/index.php';
        exit;
    }

    // Admin
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        // Missing static assets must 404 — not bounce through the admin login shell
        if (preg_match('#^/admin/(css|js)/#', $path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Missing admin asset: {$path}\nUpload it from local Forma to the matching path under /admin/.\n";
            exit;
        }
        // Canonical trailing slash so relative admin assets resolve under /admin/
        if ($path === '/admin' && !str_ends_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/')) {
            $q = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
            header('Location: ' . rtrim(forma_site_base_path(), '/') . '/admin/' . ($q ? '?' . $q : ''));
            exit;
        }
        require ADMIN_DIR . '/index.php';
        exit;
    }

    // SEO: robots + sitemap (never cache as HTML page)
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain; charset=UTF-8');
        echo Seo::robotsTxt();
        exit;
    }
    if ($path === '/sitemap.xml') {
        header('Content-Type: application/xml; charset=UTF-8');
        echo Seo::sitemapXml();
        exit;
    }

    // Dynamic feeds
    if ($path === '/feed.xml' || $path === '/blog/feed' || $path === '/feeds/blog.xml') {
        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo Feed::blogRssXml();
        exit;
    }
    if ($path === '/feed.json' || $path === '/feeds/blog.json') {
        header('Content-Type: application/feed+json; charset=UTF-8');
        echo json_encode(Feed::blogJsonFeed(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    if ($path === '/feeds/podcast.xml') {
        header('Content-Type: application/rss+xml; charset=UTF-8');
        echo Feed::podcastRssXml();
        exit;
    }

    // Page cache
    $cacheEnabled = ($config['cache']['enabled'] ?? false) === true;
    $cacheTtl     = (int)($config['cache']['ttl'] ?? 3600);
    if ($cacheEnabled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !str_starts_with($path, '/admin') && !str_starts_with($path, '/api')
    ) {
        $row = $db->queryOne('SELECT content, expires_at FROM page_cache WHERE path = ?', [$path]);
        if ($row && (int)$row['expires_at'] >= time()) {
            echo $row['content'];
            exit;
        }
    }

    $putCache = function (string $p, string $html) use ($db, $cacheEnabled, $cacheTtl): void {
        if ($html === '' || http_response_code() !== 200) {
            return;
        }
        if (str_starts_with($p, '/admin') || str_starts_with($p, '/api')) {
            return;
        }
        StaticFallback::writeHtml($p, $html);
        if (!$cacheEnabled) {
            return;
        }
        $db->execute(
            'INSERT OR REPLACE INTO page_cache (path, content, expires_at) VALUES (?, ?, ?)',
            [$p, $html, time() + $cacheTtl]
        );
    };

    // Blog (path already rtrim'd — /blog/ becomes /blog)
    if ($path === '/blog' || str_starts_with($path, '/blog/')) {
        $slug = ($path === '/blog') ? '' : trim(substr($path, 6), '/');
        if ($slug === '') {
            $html = Render::renderBlogArchive();
            $putCache($path, $html);
            echo $html;
            exit;
        }
        $row = BlogRepo::getBySlug($slug);
        if (!$row || !BlogRepo::isPubliclyVisible($row)) {
            Render::sendError(404);
        }
        $html = Render::renderBlogPost($row);
        $putCache($path, $html);
        echo $html;
        exit;
    }

    // Podcast (public pages; watermark if unlicensed)
    if (str_starts_with($path, '/podcast')) {
        $episodeId = ($path === '/podcast') ? '' : ltrim(substr($path, 9), '/');
        $podcastConfig = $config['podcast'] ?? [];
        $podcastCtx = [
            'title'       => $podcastConfig['title'] ?: ($config['site']['title'] ?? 'Podcast'),
            'description' => $podcastConfig['description'] ?? '',
            'cover_art'   => $podcastConfig['image'] ?? '',
        ];
        if ($episodeId === '') {
            $rows = PodcastRepo::list();
            $episodes = [];
            foreach ($rows as $row) {
                if (empty($row['published_at']) || (int)$row['published_at'] > time()) {
                    continue;
                }
                $ep = $row;
                $ep['id'] = $row['episode_id'];
                $ep['publish_date'] = date('Y-m-d', (int)$row['published_at']);
                $episodes[] = $ep;
            }
            $tpl = PageRepo::get('podcast-archive');
            if ($tpl) {
                $html = Render::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(Render::siteContext(), [
                    'episodes' => $episodes,
                    'podcast'  => $podcastCtx,
                ]));
                if (!License::isPodcastLicensed()) {
                    $html .= '<div style="text-align:center;padding:1rem;opacity:.55;font-size:.8rem;">Powered by Forma Podcast</div>';
                }
                $html = Render::injectGenerator(Render::expandShortcodes($html));
                echo Seo::applyToHtml($html, Seo::forSimple(
                    '/podcast',
                    $podcastCtx['title'],
                    $podcastCtx['description'],
                    $podcastCtx['cover_art'] ?? ''
                ));
                exit;
            }
        } else {
            $row = PodcastRepo::get($episodeId);
            if (!$row || empty($row['published_at']) || (int)$row['published_at'] > time()) {
                Render::sendError(404);
            }
            $episode = $row;
            $episode['id'] = $row['episode_id'];
            $episode['publish_date'] = $row['published_at'] ? date('Y-m-d', (int)$row['published_at']) : '';
            $episode['audio_url'] = forma_uploads_web_prefix() . basename($row['audio_file']);
            if (!empty($row['show_notes'])) {
                $episode['show_notes_html'] = Render::parsedown()->text($row['show_notes']);
            }
            $tpl = PageRepo::get('podcast-single');
            if ($tpl) {
                $html = Render::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(Render::siteContext(), [
                    'episode' => $episode,
                    'podcast' => $podcastCtx,
                    'podcast_feed' => '/feeds/podcast.xml',
                ]));
                if (!License::isPodcastLicensed()) {
                    $html .= '<div style="text-align:center;padding:1rem;opacity:.55;font-size:.8rem;">Powered by Forma Podcast</div>';
                }
                $html = Render::injectGenerator(Render::expandShortcodes($html));
                echo Seo::applyToHtml($html, Seo::forSimple(
                    '/podcast/' . $episodeId,
                    $row['title'] ?: $episodeId,
                    $row['description'] ?? '',
                    ($row['episode_art'] ?: ($podcastCtx['cover_art'] ?? ''))
                ));
                exit;
            }
        }
        Render::sendError(404);
    }

    // Standard pages
    $page = PageRepo::getBySlug($path);
    if (!$page) {
        $filename = ltrim($path, '/') ?: 'home';
        $page = PageRepo::get($filename);
    }
    if (!$page) {
        Render::sendError(404);
    }

    $html = Render::renderPageRow($page);
    $putCache($path, $html);
    echo $html;
} catch (Throwable $e) {
    error_log('Forma error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo Render::staticError(500);
}

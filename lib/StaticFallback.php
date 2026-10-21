<?php
/**
 * Forma – Publish engine.
 *
 * When "HTML cache" (cache.static_fallback) is on, every public page, post,
 * podcast episode/archive, and error page is written as real HTML under
 * fallback/ and Apache serves those files directly — before PHP ever runs.
 * A page that hasn't been published yet simply falls through to a live PHP
 * render (see the .htaccess -f checks), so turning this on never blanks a
 * site mid-migration. It also means Apache can still serve the site if
 * PHP/FastCGI dies outright (DreamHost "No input file specified.").
 *
 * fallback/.enabled is the single bit Apache can see without asking PHP —
 * it gates every rewrite rule in the "Forma HTML cache" .htaccess block.
 */
class StaticFallback {
    public static function dir(): string {
        return defined('FALLBACK_DIR') ? FALLBACK_DIR : (ROOT_DIR . '/fallback');
    }

    public static function enabled(): bool {
        $c = Database::get()->getSetting('cache');
        return !empty($c['static_fallback']);
    }

    public static function ttl(): int {
        $c = Database::get()->getSetting('cache');
        return max(60, (int)($c['ttl'] ?? 3600));
    }

    public static function fileForPath(string $path): string {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return self::dir() . '/index.html';
        }
        $rel = trim(str_replace('\\', '/', $path), '/');
        $rel = preg_replace('/[^a-zA-Z0-9._\/-]/', '', $rel) ?? '';
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        return self::dir() . '/' . $rel . '.html';
    }

    public static function markerFile(): string {
        return self::dir() . '/.enabled';
    }

    public static function markerPresent(): bool {
        return is_file(self::markerFile());
    }

    private static function setMarker(bool $on): void {
        self::ensureDir();
        $file = self::markerFile();
        if ($on) {
            @file_put_contents($file, (string)time());
        } elseif (is_file($file)) {
            @unlink($file);
        }
    }

    public static function writeStamp(): void {
        self::ensureDir();
        $down = self::dir() . '/.php-down';
        if (is_file($down)) {
            @unlink($down);
        }
        $payload = [
            'ok'      => true,
            'product' => defined('FORMA_PRODUCT') ? FORMA_PRODUCT : 'Forma',
            'version' => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'php'     => PHP_VERSION,
            'ts'      => time(),
            'iso'     => date('c'),
            'up'      => '/up',
            'hint'    => 'If this file is fresh but GET /up fails with “No input file specified”, PHP/FastCGI is down on the vhost.',
        ];
        @file_put_contents(self::dir() . '/php-ok.json', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public static function writeHtml(string $path, string $html): bool {
        if (!self::enabled() || $html === '' || strlen($html) < 32) {
            return false;
        }
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api')
            || $path === '/up' || str_starts_with($path, '/search')
        ) {
            return false;
        }
        $file = self::fileForPath($path);
        if ($file === '') {
            return false;
        }
        self::ensureDir();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return @file_put_contents($file, $html) !== false;
    }

    /** Delete a published file regardless of current enabled state — always clean up stale content. */
    private static function removeFile(string $path): void {
        $file = self::fileForPath($path);
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }

    // ---- Pages ---------------------------------------------------------

    public static function publishPage(array $row): bool {
        $filename = (string)($row['filename'] ?? '');
        $slug = trim((string)($row['slug'] ?? ''));
        // Internal Twig/search templates are stored as pages with no public
        // slug. They are rendered by their owning route and must never be
        // mistaken for the homepage during a full publish.
        if ($slug === '' && $filename !== 'home') {
            return false;
        }
        // Error pages (/404 …) are written as fallback/_404.html for Apache
        // ErrorDocument by publishErrorPages(); a 200 copy at fallback/404.html
        // would make /404 answer OK from the cache layer.
        if (in_array($filename, Seo::TEMPLATE_FILENAMES, true)) {
            return false;
        }
        self::ensureDir();
        try {
            $html = Render::renderPageRow($row);
        } catch (Throwable $e) {
            error_log('Forma publish page error: ' . $e->getMessage());
            return false;
        }
        if ($filename === 'home') {
            $slug = '/';
        }
        return self::writeHtml($slug, $html);
    }

    public static function unpublishPage(string $slug): void {
        $slug = trim($slug);
        if ($slug === '' || $slug === '/') {
            return; // never remove the homepage fallback this way
        }
        self::removeFile($slug);
    }

    /** Back-compat name used by earlier callers — same as publishPage(). */
    public static function refreshSavedPage(array $row): void {
        self::publishPage($row);
    }

    /** Re-render home if the last-good file is missing or older than TTL. */
    public static function refreshHomeIfStale(bool $force = false): void {
        if (!self::enabled()) {
            return;
        }
        $file = self::fileForPath('/');
        if (!$force && is_file($file) && (time() - (int)filemtime($file)) < self::ttl()) {
            return;
        }
        try {
            $page = PageRepo::get('home') ?? PageRepo::getBySlug('/');
            if (!$page) {
                return;
            }
            self::publishPage($page);
        } catch (Throwable $e) {
            error_log('Forma static fallback: ' . $e->getMessage());
        }
    }

    // ---- Blog ------------------------------------------------------------

    public static function publishPost(array $row): bool {
        if (!BlogRepo::isPubliclyVisible($row)) {
            self::unpublishPost((string)($row['slug'] ?? $row['filename'] ?? ''));
            return false;
        }
        self::ensureDir();
        try {
            $html = Render::renderBlogPost($row);
        } catch (Throwable $e) {
            error_log('Forma publish post error: ' . $e->getMessage());
            return false;
        }
        $slug = '/blog/' . trim((string)($row['slug'] ?? $row['filename'] ?? ''), '/');
        return self::writeHtml($slug, $html);
    }

    public static function unpublishPost(string $slug): void {
        $slug = trim($slug, '/');
        if ($slug === '') {
            return;
        }
        self::removeFile('/blog/' . $slug);
    }

    public static function publishBlogArchive(): bool {
        self::ensureDir();
        try {
            $html = Render::renderBlogArchive();
        } catch (Throwable $e) {
            error_log('Forma publish blog archive error: ' . $e->getMessage());
            return false;
        }
        return self::writeHtml('/blog', $html);
    }

    // ---- Podcast -----------------------------------------------------

    public static function publishEpisode(array $ep): bool {
        if (!class_exists('License') || !License::isPodcastLicensed()) {
            return false;
        }
        $episodeId = (string)($ep['episode_id'] ?? '');
        if (empty($ep['published_at']) || (int)$ep['published_at'] > time()) {
            self::unpublishEpisode($episodeId);
            return false;
        }
        self::ensureDir();
        try {
            $html = Render::renderPodcastEpisode($ep);
        } catch (Throwable $e) {
            error_log('Forma publish episode error: ' . $e->getMessage());
            return false;
        }
        return self::writeHtml('/podcast/' . $episodeId, $html);
    }

    public static function unpublishEpisode(string $episodeId): void {
        if ($episodeId === '') {
            return;
        }
        self::removeFile('/podcast/' . $episodeId);
    }

    public static function publishPodcastArchive(): bool {
        if (!class_exists('License') || !License::isPodcastLicensed()) {
            return false;
        }
        self::ensureDir();
        try {
            $html = Render::renderPodcastArchive();
        } catch (Throwable $e) {
            error_log('Forma publish podcast archive error: ' . $e->getMessage());
            return false;
        }
        return self::writeHtml('/podcast', $html);
    }

    // ---- Error pages ---------------------------------------------------

    /** Writes fallback/_404.html etc. for Apache ErrorDocument to serve during a FastCGI outage. */
    public static function publishErrorPages(): array {
        self::ensureDir();
        $written = [];
        foreach (['_404', '_403', '_500'] as $fn) {
            $row = PageRepo::get($fn);
            if (!$row) {
                continue;
            }
            try {
                $html = Render::renderPageRow($row);
            } catch (Throwable $e) {
                continue;
            }
            if (@file_put_contents(self::dir() . '/' . $fn . '.html', $html) !== false) {
                $written[] = $fn;
            }
        }
        return $written;
    }

    // ---- Full publish / enable / disable -------------------------------

    public static function republishIfEnabled(): array {
        if (!self::enabled()) {
            return ['enabled' => false];
        }
        return self::publishAll();
    }

    /** Rebuild every HTML file + search index. The "Rebuild HTML cache" action calls this. */
    public static function publishAll(): array {
        self::ensureDir();
        $counts = [
            'enabled' => self::enabled(),
            'pages' => 0, 'posts' => 0, 'blog_archive' => false,
            'podcast_episodes' => 0, 'podcast_archive' => false,
            'errors' => [], 'search' => ['page' => 0, 'post' => 0, 'podcast' => 0],
        ];
        if (!$counts['enabled']) {
            return $counts;
        }
        // Never let Apache serve a half-rebuilt tree. PHP remains live while
        // files are written; the marker returns only after completeness checks.
        self::setMarker(false);
        $pageRows = PageRepo::list();
        $postRows = BlogRepo::list(true);
        $expectedPages = 0;
        $expectedPosts = 0;
        $expectedEpisodes = 0;
        foreach ($pageRows as $row) {
            $filename = (string)($row['filename'] ?? '');
            if (in_array($filename, Seo::TEMPLATE_FILENAMES, true)) {
                // Releases before 0.6.0 published templates as ordinary pages
                // (fallback/blog-single.html …). Apache would keep serving
                // those 200 copies forever, so a rebuild removes them. Error
                // pages are re-written as fallback/_404.html by publishErrorPages().
                // Older saves may have given the template a slug (/blog-single,
                // or /404 for an error page) or published it under its filename —
                // clear both. fallback/_404.html itself is owned by
                // publishErrorPages() and is never a candidate here.
                $isErrorPage = str_starts_with($filename, '_');
                $stale = [trim((string)($row['slug'] ?? ''))];
                if (!$isErrorPage) {
                    $stale[] = '/' . $filename;
                }
                foreach (array_unique($stale) as $path) {
                    if ($path === '' || $path === '/') {
                        continue;
                    }
                    if ($isErrorPage && $path === '/' . $filename) {
                        continue; // that file is the ErrorDocument itself
                    }
                    self::unpublishPage($path);
                }
                continue;
            }
            if (self::pageIsPublishable($row)) {
                $expectedPages++;
            }
            $full = PageRepo::get($filename);
            if ($full && self::publishPage($full)) {
                $counts['pages']++;
            }
        }
        foreach ($postRows as $row) {
            $full = BlogRepo::get($row['filename']);
            if ($full && BlogRepo::isPubliclyVisible($full)) {
                $expectedPosts++;
            }
            if ($full && self::publishPost($full)) {
                $counts['posts']++;
            }
        }
        $counts['blog_archive'] = self::publishBlogArchive();
        if (class_exists('License') && License::isPodcastLicensed()) {
            foreach (PodcastRepo::list() as $ep) {
                if (!empty($ep['published_at']) && (int)$ep['published_at'] <= time()) {
                    $expectedEpisodes++;
                }
                if (self::publishEpisode($ep)) {
                    $counts['podcast_episodes']++;
                }
            }
            $counts['podcast_archive'] = self::publishPodcastArchive();
        }
        $counts['errors'] = self::publishErrorPages();
        if (class_exists('RedirectRepo') && class_exists('Htaccess')) {
            Htaccess::syncRedirectsBlock(RedirectRepo::list());
        }
        if (class_exists('Htaccess')) {
            Htaccess::ensureStaticFallbackRules();
            Htaccess::ensureFastCgiSafeFrontController();
            Htaccess::ensureErrorDocuments(true);
        }
        if (class_exists('Search')) {
            $counts['search'] = Search::reindexAll();
        }
        $expectedErrors = 0;
        foreach (['_404', '_403', '_500'] as $errorPage) {
            if (PageRepo::get($errorPage)) {
                $expectedErrors++;
            }
        }
        $counts['complete'] = $counts['pages'] === $expectedPages
            && $counts['posts'] === $expectedPosts
            && $counts['blog_archive'] === true
            && $counts['podcast_episodes'] === $expectedEpisodes
            && (!License::isPodcastLicensed() || $counts['podcast_archive'] === true)
            && count($counts['errors']) === $expectedErrors;
        self::setMarker($counts['complete']);
        if (!$counts['complete'] && class_exists('Htaccess')) {
            Htaccess::ensureErrorDocuments(false);
        }
        self::writeStamp();
        @file_put_contents(self::dir() . '/.published-at', (string)time());
        return $counts;
    }

    private static function pageIsPublishable(array $row): bool {
        $filename = (string)($row['filename'] ?? '');
        $path = $filename === 'home' ? '/' : trim((string)($row['slug'] ?? ''));
        if ($path === '' || in_array($filename, Seo::TEMPLATE_FILENAMES, true)) {
            return false;
        }
        return !str_starts_with($path, '/admin')
            && !str_starts_with($path, '/api')
            && $path !== '/up'
            && !str_starts_with($path, '/search');
    }

    /** Current DB routes that may have generated fallback HTML. */
    public static function publishedPaths(): array {
        $paths = [];
        foreach (PageRepo::list() as $row) {
            if (!self::pageIsPublishable($row)) {
                continue;
            }
            $paths[] = ($row['filename'] ?? '') === 'home'
                ? '/'
                : trim((string)($row['slug'] ?? ''));
        }
        foreach (BlogRepo::list(true) as $row) {
            $slug = trim((string)($row['slug'] ?? ''), '/');
            if ($slug !== '') {
                $paths[] = '/blog/' . $slug;
            }
        }
        foreach (PodcastRepo::list() as $row) {
            $id = trim((string)($row['episode_id'] ?? ''));
            if ($id !== '') {
                $paths[] = '/podcast/' . $id;
            }
        }
        return array_values(array_unique($paths));
    }

    /** Remove obsolete generated routes identified by rollback comparison. */
    public static function removePublishedPaths(array $paths): void {
        foreach (array_unique($paths) as $path) {
            self::removeFile((string)$path);
        }
    }

    /** Turn HTML cache on: caller must persist cache.static_fallback = true first. */
    public static function enable(): array {
        self::setMarker(true);
        return self::publishAll();
    }

    /** Minimum gap between automatic full rebuilds triggered by /up. */
    private const SELF_HEAL_INTERVAL = 600;

    /**
     * Settings say HTML cache is on but the Apache-visible marker is missing
     * (fresh DB default, deleted fallback/, or the last publishAll() was
     * incomplete). Rebuild — but never more than once per SELF_HEAL_INTERVAL,
     * otherwise a single page that fails to render would make every
     * unauthenticated heartbeat re-render the whole site.
     */
    public static function selfHeal(): bool {
        if (!self::enabled() || self::markerPresent()) {
            return false;
        }
        $last = self::lastPublishedAt();
        if ($last !== null && (time() - $last) < self::SELF_HEAL_INTERVAL) {
            return false;
        }
        self::enable();
        return true;
    }

    /**
     * Scheduled content: a post/episode with a future published_at becomes
     * public when the clock passes it, but nothing runs at that moment. Called
     * from /up (and the blog archive route) to catch items whose publish time
     * fell between the last publish run and now, then refresh the derived
     * output they appear in: their own HTML, the archives, feeds, search, and
     * the PHP page cache (sitemap/llms/archive).
     */
    public static function publishScheduledIfDue(): int {
        $since = self::lastPublishedAt();
        $now = time();
        $stampFile = self::dir() . '/.scheduled-at';
        $sinceSched = is_file($stampFile) ? (int)trim((string)@file_get_contents($stampFile)) : 0;
        $since = max((int)$since, $sinceSched);
        if ($since <= 0) {
            // No reference point yet — record one and let the next call compare.
            self::ensureDir();
            @file_put_contents($stampFile, (string)$now);
            return 0;
        }
        $db = Database::get();
        $due = 0;
        try {
            $posts = $db->query(
                'SELECT * FROM blog_posts WHERE published_at IS NOT NULL AND published_at > ? AND published_at <= ?',
                [$since, $now]
            );
            foreach ($posts as $post) {
                $due++;
                if (self::enabled()) {
                    self::publishPost($post);
                }
                if (class_exists('Search')) {
                    Search::indexPost($post);
                }
            }
            $episodes = [];
            if (class_exists('License') && License::isPodcastLicensed()) {
                $episodes = $db->query(
                    'SELECT * FROM podcast_episodes WHERE published_at IS NOT NULL AND published_at > ? AND published_at <= ?',
                    [$since, $now]
                );
                foreach ($episodes as $ep) {
                    $due++;
                    if (self::enabled()) {
                        self::publishEpisode($ep);
                    }
                    if (class_exists('Search')) {
                        Search::indexEpisode($ep);
                    }
                }
            }
            if ($due > 0) {
                $db->flushCache();
                if (class_exists('Render')) {
                    Render::forgetSiteContext();
                }
                if ($posts) {
                    if (class_exists('Feed')) {
                        Feed::maybeRegenerateBlog();
                    }
                    if (self::enabled()) {
                        self::publishBlogArchive();
                        self::refreshHomeIfStale(true);
                    }
                }
                if ($episodes) {
                    if (class_exists('Feed')) {
                        Feed::maybeRegeneratePodcast();
                    }
                    if (self::enabled()) {
                        self::publishPodcastArchive();
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Forma scheduled publish: ' . $e->getMessage());
        }
        self::ensureDir();
        @file_put_contents($stampFile, (string)$now);
        return $due;
    }

    /** Turn HTML cache off: stop serving files (marker gone), drop the ErrorDocument block. Leaves files on disk. */
    public static function disable(): void {
        self::setMarker(false);
        if (class_exists('Htaccess')) {
            Htaccess::ensureErrorDocuments(false);
        }
    }

    public static function lastPublishedAt(): ?int {
        $file = self::dir() . '/.published-at';
        if (!is_file($file)) {
            return null;
        }
        $v = (int)trim((string)@file_get_contents($file));
        return $v > 0 ? $v : null;
    }

    public static function status(): array {
        $home = self::fileForPath('/');
        $stamp = self::dir() . '/php-ok.json';
        return [
            'enabled'        => self::enabled(),
            'marker'         => self::markerPresent(),
            'dir'            => self::dir(),
            'writable'       => is_dir(self::dir()) ? is_writable(self::dir()) : is_writable(ROOT_DIR),
            'home'           => is_file($home),
            'home_age'       => is_file($home) ? (time() - (int)filemtime($home)) : null,
            'stamp'          => is_file($stamp),
            'stamp_age'      => is_file($stamp) ? (time() - (int)filemtime($stamp)) : null,
            'last_published' => self::lastPublishedAt(),
            'search_engine'  => class_exists('Search') ? Search::engine() : 'unknown',
            'search_docs'    => class_exists('Search') ? Search::count() : 0,
        ];
    }

    private static function ensureDir(): void {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "RewriteRule \\.php$ - [F,L]\nOptions -Indexes\n");
        }
    }
}

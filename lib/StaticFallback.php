<?php
/**
 * Last-good HTML on disk so Apache can still serve the site if PHP/FastCGI dies
 * (DreamHost “No input file specified.”).
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

    public static function writeStamp(): void {
        self::ensureDir();
        $down = self::dir() . '/.php-down';
        if (is_file($down)) {
            @unlink($down);
        }
        $payload = [
            'ok'        => true,
            'product'   => defined('FORMA_PRODUCT') ? FORMA_PRODUCT : 'Forma',
            'version'   => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'php'       => PHP_VERSION,
            'ts'        => time(),
            'iso'       => date('c'),
            'up'        => '/up',
            'hint'      => 'If this file is fresh but GET /up fails with “No input file specified”, PHP/FastCGI is down on the vhost.',
        ];
        @file_put_contents(self::dir() . '/php-ok.json', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public static function writeHtml(string $path, string $html): bool {
        if (!self::enabled() || $html === '' || strlen($html) < 32) {
            return false;
        }
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api') || $path === '/up') {
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
            $html = Render::renderPageRow($page);
            self::writeHtml('/', $html);
        } catch (Throwable $e) {
            error_log('Forma static fallback: ' . $e->getMessage());
        }
    }

    /** After an admin page save, refresh that path’s last-good HTML. */
    public static function refreshSavedPage(array $row): void {
        if (!self::enabled()) {
            return;
        }
        try {
            $html = Render::renderPageRow($row);
            $slug = trim((string)($row['slug'] ?? ''));
            if ($slug === '' || $slug === 'home' || ($row['filename'] ?? '') === 'home') {
                $slug = '/';
            }
            self::writeHtml($slug, $html);
        } catch (Throwable $e) {
            error_log('Forma static fallback: ' . $e->getMessage());
        }
    }

    public static function status(): array {
        $home = self::fileForPath('/');
        $stamp = self::dir() . '/php-ok.json';
        return [
            'enabled'     => self::enabled(),
            'dir'         => self::dir(),
            'writable'    => is_dir(self::dir()) ? is_writable(self::dir()) : is_writable(ROOT_DIR),
            'home'        => is_file($home),
            'home_age'    => is_file($home) ? (time() - (int)filemtime($home)) : null,
            'stamp'       => is_file($stamp),
            'stamp_age'   => is_file($stamp) ? (time() - (int)filemtime($stamp)) : null,
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

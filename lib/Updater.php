<?php
/**
 * In-app CMS updater.
 *
 * Installs GitHub *releases* only (never git main). Replaces app files.
 * Never touches site data: database/, uploads/, feeds/, fallback/, .htaccess,
 * env files, or lib/LicenseHMACSecret.hex.
 */
class Updater {
    public const REPO = 'Alta-forma/forma-cms';
    public const CACHE_TTL = 900;
    public const MAX_ZIP_BYTES = 52428800; // 50 MB
    public const KEEP_BACKUPS = 5;

    /** Root-level files replaced on update. */
    public static function rootFiles(): array {
        return [
            'index.php',
            'router.php',
            'config.php',
            'version.php',
            'AGENTS.md',
            'README.md',
            'LICENSE',
            '.gitignore',
            'forma-logo.svg',
            'forma-icon.png',
            'forma-social.png',
            'forma-social.svg',
        ];
    }

    /** Directories replaced on update (deny-list still applied inside). Not `docs/` — that would 403 a CMS page at /docs. */
    public static function rootDirs(): array {
        return ['admin', 'lib', 'api', 'mcp', 'templates'];
    }

    /** Only these files under tools/ — never a full wipe of tools/. */
    public static function toolFiles(): array {
        return [
            'formax.php',
            'import-formalite.php',
            'watch-php.php',
            'watch-sites.example.txt',
            'release.sh',
        ];
    }

    /** Never overwrite, even if present in the release zip. */
    public static function neverTouch(): array {
        return [
            'database/',
            'uploads/',
            'feeds/',
            'fallback/',
            '.htaccess',
            '.env',
            'config.local.php',
            'lib/LicenseHMACSecret.hex',
            'tools/watch-sites.txt',
            'mcp/node_modules/',
        ];
    }

    public static function dir(): string {
        return dirname(DB_FILE) . '/updates';
    }

    public static function installedVersion(): string {
        return defined('FORMA_VERSION') ? (string)FORMA_VERSION : '0';
    }

    /** @return array{ok:bool,ready:bool,message:string,checks:list<string>} */
    public static function preflight(): array {
        $checks = [];
        $ok = true;

        if (!class_exists('ZipArchive')) {
            $ok = false;
            $checks[] = 'PHP zip (ZipArchive) is missing — ask the host to enable ext-zip.';
        } else {
            $checks[] = 'ZipArchive available';
        }

        $http = extension_loaded('curl')
            || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
        if (!$http) {
            $ok = false;
            $checks[] = 'No outbound HTTPS (need curl or allow_url_fopen).';
        } else {
            $checks[] = extension_loaded('curl') ? 'curl available' : 'allow_url_fopen On';
        }

        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $ok = false;
            $checks[] = 'Cannot create database/updates/ (need write access next to forma.db).';
        } elseif (!is_writable($dir)) {
            $ok = false;
            $checks[] = 'database/updates/ is not writable.';
        } else {
            $checks[] = 'database/updates/ writable';
        }

        foreach (array_merge(self::rootDirs(), ['index.php', 'version.php']) as $rel) {
            $path = ROOT_DIR . '/' . $rel;
            if (is_file($path) && !is_writable($path)) {
                $ok = false;
                $checks[] = $rel . ' is not writable.';
            } elseif (is_dir($path) && !is_writable($path)) {
                $ok = false;
                $checks[] = $rel . '/ is not writable.';
            }
        }
        if ($ok) {
            $checks[] = 'App paths look writable';
        }

        $free = @disk_free_space(ROOT_DIR);
        if ($free !== false && $free < 40 * 1024 * 1024) {
            $ok = false;
            $checks[] = 'Less than 40 MB free disk — not enough for a zip + backup.';
        } elseif ($free !== false) {
            $checks[] = 'Disk free: ' . self::bytes((int)$free);
        }

        return [
            'ok'      => $ok,
            'ready'   => $ok,
            'message' => $ok ? 'This install can apply a release.' : 'Fix the failed checks before updating.',
            'checks'  => $checks,
        ];
    }

    /**
     * Latest GitHub release (cached).
     * @return array<string,mixed>
     */
    public static function latest(bool $refresh = false): array {
        $cacheFile = self::dir() . '/latest.json';
        if (!$refresh && is_file($cacheFile)) {
            $cached = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && (time() - (int)($cached['fetched_at'] ?? 0)) < self::CACHE_TTL) {
                return $cached;
            }
        }

        try {
            $raw = self::httpGet(
                'https://api.github.com/repos/' . self::REPO . '/releases/latest',
                ['Accept: application/vnd.github+json']
            );
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                throw new RuntimeException('GitHub returned non-JSON');
            }
            if (!empty($json['message']) && empty($json['tag_name'])) {
                throw new RuntimeException((string)$json['message']);
            }
            $tag = (string)($json['tag_name'] ?? '');
            $ver = self::normalizeVersion($tag);
            if ($ver === '') {
                throw new RuntimeException('Latest release has no version tag');
            }
            $out = [
                'ok'           => true,
                'error'        => '',
                'tag'          => $tag,
                'version'      => $ver,
                'name'         => (string)($json['name'] ?? $tag),
                'url'          => (string)($json['html_url'] ?? ('https://github.com/' . self::REPO . '/releases')),
                'notes'        => (string)($json['body'] ?? ''),
                'published_at' => (string)($json['published_at'] ?? ''),
                'zip_url'      => 'https://github.com/' . self::REPO . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
                'fetched_at'   => time(),
            ];
        } catch (Throwable $e) {
            $out = [
                'ok'           => false,
                'error'        => $e->getMessage(),
                'tag'          => '',
                'version'      => '',
                'name'         => '',
                'url'          => 'https://github.com/' . self::REPO . '/releases',
                'notes'        => '',
                'published_at' => '',
                'zip_url'      => '',
                'fetched_at'   => time(),
            ];
        }

        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $out;
    }

    /** @return array<string,mixed> */
    public static function status(bool $refresh = false): array {
        $installed = self::installedVersion();
        $pre = self::preflight();
        $latest = self::latest($refresh);
        $cmp = 0;
        $current = true;
        if ($latest['ok'] && $latest['version'] !== '') {
            $cmp = version_compare($latest['version'], $installed);
            $current = $cmp <= 0;
        }
        return [
            'installed'     => $installed,
            'installed_date'=> defined('FORMA_VERSION_DATE') ? FORMA_VERSION_DATE : '',
            'repo'          => self::REPO,
            'preflight'     => $pre,
            'latest'        => $latest,
            'update_available' => $latest['ok'] && $cmp > 0,
            'current'       => $latest['ok'] && $current,
            'backups'       => self::listBackups(),
            'last'          => self::readLog(),
            'never_touch'   => self::neverTouch(),
        ];
    }

    /**
     * Download the latest *release* and replace app files.
     * @return array{ok:bool,message:string,from:string,to:string,backup:?string,log:list<string>}
     */
    public static function apply(): array {
        $log = [];
        $from = self::installedVersion();
        $pre = self::preflight();
        if (!$pre['ok']) {
            return self::fail('Preflight failed: ' . $pre['message'], $from, $log);
        }

        $latest = self::latest(true);
        if (empty($latest['ok']) || $latest['version'] === '' || $latest['zip_url'] === '') {
            $err = $latest['error'] !== '' ? $latest['error'] : 'No GitHub release published yet.';
            return self::fail($err . ' Forma only installs tagged releases — never git main.', $from, $log);
        }

        $to = $latest['version'];
        if (version_compare($to, $from, '<')) {
            return self::fail('Refusing to downgrade from ' . $from . ' to ' . $to . '.', $from, $log);
        }
        if (version_compare($to, $from, '=')) {
            return self::fail('Already on ' . $from . ' (latest release). Nothing to apply.', $from, $log);
        }

        $lock = self::dir() . '/updating.lock';
        if (is_file($lock) && (time() - (int)filemtime($lock)) < 600) {
            return self::fail('An update is already running. Wait a few minutes, or delete database/updates/updating.lock if it is stuck.', $from, $log);
        }
        @file_put_contents($lock, (string)time());

        $backup = null;
        $staging = self::dir() . '/staging-' . bin2hex(random_bytes(4));
        $zipPath = self::dir() . '/download-' . $to . '.zip';

        try {
            @set_time_limit(180);
            ignore_user_abort(true);

            $log[] = 'Downloading ' . $latest['tag'] . ' from GitHub…';
            self::httpGetToFile($latest['zip_url'], $zipPath);
            $size = filesize($zipPath);
            if ($size === false || $size < 1024) {
                throw new RuntimeException('Downloaded zip is empty');
            }
            $log[] = 'Downloaded ' . self::bytes((int)$size);

            $log[] = 'Extracting…';
            self::extractZip($zipPath, $staging);
            $root = self::findReleaseRoot($staging);
            if ($root === null) {
                throw new RuntimeException('Zip is not a Forma tree (no version.php)');
            }
            $payloadVer = self::versionFromFile($root . '/version.php');
            if ($payloadVer === '' || self::normalizeVersion($payloadVer) !== $to) {
                throw new RuntimeException(
                    'Release zip version.php is ' . ($payloadVer !== '' ? $payloadVer : 'missing')
                    . ' but the GitHub tag is ' . $latest['tag'] . '. Refusing to install a mismatched tree.'
                );
            }
            if (!is_file($root . '/admin/index.php') || !is_file($root . '/lib/bootstrap.php') || !is_file($root . '/index.php')) {
                throw new RuntimeException('Release zip is missing admin/index.php, lib/bootstrap.php, or index.php');
            }
            $log[] = 'Verified Forma ' . $payloadVer . ' in the zip';

            $log[] = 'Backing up current app files (not your database or uploads)…';
            $backup = self::backupCurrent($from);
            $log[] = 'Backup: ' . basename($backup);

            $log[] = 'Copying release files onto this install…';
            self::copyAllowlist($root, ROOT_DIR);
            $log[] = 'App files replaced';

            if (function_exists('opcache_reset')) {
                opcache_reset();
                $log[] = 'OPcache reset';
            }

            if (class_exists('Database')) {
                Database::get()->flushCache();
                $log[] = 'PHP cache flushed';
            }

            $installedNow = self::versionFromFile(ROOT_DIR . '/version.php');
            if (self::normalizeVersion($installedNow) !== $to) {
                throw new RuntimeException('Copy finished but version.php still reads ' . $installedNow);
            }

            $result = [
                'ok'      => true,
                'message' => 'Updated Forma ' . $from . ' → ' . $to . '. Content was not touched. If you customized .htaccess, leave it — do not Write .htaccess unless you mean to.',
                'from'    => $from,
                'to'      => $to,
                'backup'  => $backup,
                'log'     => $log,
                'at'      => date('c'),
            ];
            self::writeLog($result);
            return $result;
        } catch (Throwable $e) {
            $log[] = 'FAILED: ' . $e->getMessage();
            if (is_string($backup) && is_file($backup)) {
                try {
                    $log[] = 'Rolling back from backup…';
                    self::restoreBackup($backup);
                    $log[] = 'Rollback complete';
                } catch (Throwable $rb) {
                    $log[] = 'Rollback also failed: ' . $rb->getMessage();
                }
            }
            $result = self::fail($e->getMessage(), $from, $log, $backup, $to);
            self::writeLog($result);
            return $result;
        } finally {
            self::rmTree($staging);
            @unlink($zipPath);
            @unlink($lock);
        }
    }

    /**
     * Restore the most recent app-file backup (or a named one).
     * @return array{ok:bool,message:string,from:string,to:string,backup:?string,log:list<string>}
     */
    public static function rollback(?string $basename = null): array {
        $log = [];
        $from = self::installedVersion();
        $file = null;
        if ($basename) {
            $base = basename($basename);
            if (!preg_match('/^app-backup-.+\.zip$/', $base)) {
                return self::fail('Invalid backup name.', $from, $log);
            }
            $file = self::dir() . '/' . $base;
        } else {
            $list = self::listBackups();
            $file = $list[0]['path'] ?? null;
        }
        if (!$file || !is_file($file)) {
            return self::fail('No app-file backup to restore.', $from, $log);
        }

        try {
            @set_time_limit(180);
            $log[] = 'Restoring ' . basename($file);
            self::restoreBackup($file);
            if (class_exists('Database')) {
                Database::get()->flushCache();
            }
            $to = self::versionFromFile(ROOT_DIR . '/version.php') ?: $from;
            $result = [
                'ok'      => true,
                'message' => 'Restored app files from backup. Now running ' . $to . '. Content was not touched.',
                'from'    => $from,
                'to'      => $to,
                'backup'  => $file,
                'log'     => $log,
                'at'      => date('c'),
            ];
            self::writeLog($result);
            return $result;
        } catch (Throwable $e) {
            return self::fail($e->getMessage(), $from, $log, $file);
        }
    }

    /** @return list<array{name:string,path:string,bytes:int,mtime:int}> */
    public static function listBackups(): array {
        $dir = self::dir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/app-backup-*.zip') ?: [] as $path) {
            $out[] = [
                'name'  => basename($path),
                'path'  => $path,
                'bytes' => (int)filesize($path),
                'mtime' => (int)filemtime($path),
            ];
        }
        usort($out, static fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    public static function normalizeVersion(string $tag): string {
        $tag = trim($tag);
        if (preg_match('/(\d+\.\d+\.\d+)/', $tag, $m)) {
            return $m[1];
        }
        return ltrim($tag, 'vV');
    }

    // --- internals ---

    /** @param list<string> $log */
    private static function fail(string $message, string $from, array $log, ?string $backup = null, string $to = ''): array {
        return [
            'ok'      => false,
            'message' => $message,
            'from'    => $from,
            'to'      => $to,
            'backup'  => $backup,
            'log'     => $log,
            'at'      => date('c'),
        ];
    }

    private static function backupCurrent(string $version): string {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive required to take a backup');
        }
        $name = 'app-backup-' . preg_replace('/[^0-9a-z.-]/i', '', $version) . '-' . date('Ymd-His') . '.zip';
        $path = self::dir() . '/' . $name;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup zip');
        }
        foreach (self::rootFiles() as $rel) {
            $full = ROOT_DIR . '/' . $rel;
            if (is_file($full)) {
                $zip->addFile($full, $rel);
            }
        }
        foreach (self::rootDirs() as $dir) {
            self::zipAddDir($zip, ROOT_DIR . '/' . $dir, $dir);
        }
        foreach (self::toolFiles() as $rel) {
            $full = ROOT_DIR . '/tools/' . $rel;
            if (is_file($full)) {
                $zip->addFile($full, 'tools/' . $rel);
            }
        }
        $zip->close();
        self::pruneBackups();
        return $path;
    }

    private static function zipAddDir(ZipArchive $zip, string $abs, string $prefix): void {
        if (!is_dir($abs)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $rel = $prefix . '/' . substr($file->getPathname(), strlen($abs) + 1);
            $rel = str_replace('\\', '/', $rel);
            if (self::isDenied($rel)) {
                continue;
            }
            if ($file->isDir()) {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
        }
    }

    private static function restoreBackup(string $zipPath): void {
        $tmp = self::dir() . '/rollback-' . bin2hex(random_bytes(4));
        try {
            self::extractZip($zipPath, $tmp);
            $root = self::findReleaseRoot($tmp) ?? $tmp;
            self::copyAllowlist($root, ROOT_DIR);
        } finally {
            self::rmTree($tmp);
        }
    }

    private static function copyAllowlist(string $fromRoot, string $toRoot): void {
        foreach (self::rootFiles() as $rel) {
            $src = $fromRoot . '/' . $rel;
            if (is_file($src)) {
                self::copyFile($src, $toRoot . '/' . $rel);
            }
        }
        foreach (self::rootDirs() as $dir) {
            $src = $fromRoot . '/' . $dir;
            if (is_dir($src)) {
                self::copyDir($src, $toRoot . '/' . $dir, $dir);
            }
        }
        $toolsDest = $toRoot . '/tools';
        if (!is_dir($toolsDest)) {
            @mkdir($toolsDest, 0775, true);
        }
        foreach (self::toolFiles() as $rel) {
            $src = $fromRoot . '/tools/' . $rel;
            if (is_file($src)) {
                self::copyFile($src, $toolsDest . '/' . $rel);
            }
        }
    }

    private static function copyDir(string $src, string $dest, string $relPrefix): void {
        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new RuntimeException('Cannot create ' . $relPrefix);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $rel = $relPrefix . '/' . substr($file->getPathname(), strlen($src) + 1);
            $rel = str_replace('\\', '/', $rel);
            if (self::isDenied($rel)) {
                continue;
            }
            $target = $dest . '/' . substr($file->getPathname(), strlen($src) + 1);
            if ($file->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                    throw new RuntimeException('Cannot create ' . $rel);
                }
                continue;
            }
            self::copyFile($file->getPathname(), $target);
        }
    }

    private static function copyFile(string $src, string $dest): void {
        $dir = dirname($dest);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create ' . $dir);
        }
        $tmp = $dest . '.upd-' . bin2hex(random_bytes(3));
        if (!@copy($src, $tmp)) {
            @unlink($tmp);
            throw new RuntimeException('Copy failed: ' . basename($dest));
        }
        if (!@rename($tmp, $dest)) {
            @unlink($dest);
            if (!@rename($tmp, $dest)) {
                @unlink($tmp);
                throw new RuntimeException('Replace failed: ' . basename($dest));
            }
        }
    }

    private static function isDenied(string $rel): bool {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $parts = explode('/', $rel);
        $denyDirs = ['database', 'uploads', 'feeds', 'fallback', 'node_modules', '.git', '.scratch'];
        foreach ($parts as $p) {
            if (in_array($p, $denyDirs, true)) {
                return true;
            }
        }
        $base = basename($rel);
        if ($base === '.htaccess' || $base === '.env' || str_starts_with($base, '.env.')) {
            return true;
        }
        if ($base === 'LicenseHMACSecret.hex' || $base === 'config.local.php') {
            return true;
        }
        if ($base === 'watch-sites.txt' && str_starts_with($rel, 'tools/')) {
            return true;
        }
        if (str_ends_with($base, '.db') || str_ends_with($base, '.db-wal') || str_ends_with($base, '.db-shm')) {
            return true;
        }
        return false;
    }

    private static function extractZip(string $zipPath, string $dest): void {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive required');
        }
        if (!is_dir($dest) && !@mkdir($dest, 0775, true) && !is_dir($dest)) {
            throw new RuntimeException('Cannot create staging dir');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open zip');
        }
        $zip->extractTo($dest);
        $zip->close();
    }

    private static function findReleaseRoot(string $dir): ?string {
        if (is_file($dir . '/version.php')) {
            return $dir;
        }
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $child) {
            if (is_file($child . '/version.php')) {
                return $child;
            }
        }
        return null;
    }

    private static function versionFromFile(string $path): string {
        if (!is_file($path)) {
            return '';
        }
        $src = (string)file_get_contents($path);
        if (preg_match("/define\(\s*'FORMA_VERSION'\s*,\s*'([^']+)'/", $src, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function httpGet(string $url, array $extraHeaders = []): string {
        $tmp = self::dir() . '/http-' . bin2hex(random_bytes(4));
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        try {
            self::httpGetToFile($url, $tmp, $extraHeaders);
            return (string)file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private static function httpGetToFile(string $url, string $dest, array $extraHeaders = []): void {
        self::assertSafeUrl($url);
        $headers = array_merge([
            'User-Agent: Forma-CMS/' . self::installedVersion() . ' (+https://forma-cms.me)',
        ], $extraHeaders);

        $dir = dirname($dest);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $fh = fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write download file');
        }

        try {
            if (extension_loaded('curl')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new RuntimeException('curl_init failed');
                }
                curl_setopt_array($ch, [
                    CURLOPT_FILE           => $fh,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 5,
                    CURLOPT_TIMEOUT        => 90,
                    CURLOPT_CONNECTTIMEOUT => 20,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_PROTOCOLS      => defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
                    CURLOPT_REDIR_PROTOCOLS=> defined('CURLPROTO_HTTPS') ? CURLPROTO_HTTPS : 2,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                ]);
                $ok = curl_exec($ch);
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err = curl_error($ch);
                curl_close($ch);
                if ($ok === false) {
                    throw new RuntimeException($err !== '' ? $err : 'Download failed');
                }
                if ($code >= 400 || $code === 0) {
                    throw new RuntimeException('HTTP ' . $code . ' from GitHub');
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method'  => 'GET',
                        'header'  => implode("\r\n", $headers),
                        'timeout' => 90,
                        'follow_location' => 1,
                    ],
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                    ],
                ]);
                $in = fopen($url, 'rb', false, $ctx);
                if ($in === false) {
                    throw new RuntimeException('Download failed (fopen)');
                }
                $copied = 0;
                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    $copied += strlen($chunk);
                    if ($copied > self::MAX_ZIP_BYTES) {
                        fclose($in);
                        throw new RuntimeException('Download exceeded size limit');
                    }
                    fwrite($fh, $chunk);
                }
                fclose($in);
            }
        } finally {
            fclose($fh);
        }

        $size = filesize($dest);
        if ($size !== false && $size > self::MAX_ZIP_BYTES) {
            @unlink($dest);
            throw new RuntimeException('Download exceeded size limit');
        }
    }

    private static function assertSafeUrl(string $url): void {
        $p = parse_url($url);
        $host = strtolower((string)($p['host'] ?? ''));
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        $allow = [
            'github.com',
            'api.github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
        ];
        if ($scheme !== 'https' || !in_array($host, $allow, true)) {
            throw new RuntimeException('Refusing to download from ' . $host);
        }
    }

    private static function pruneBackups(): void {
        $list = self::listBackups();
        foreach (array_slice($list, self::KEEP_BACKUPS) as $row) {
            @unlink($row['path']);
        }
    }

    private static function rmTree(string $dir): void {
        if ($dir === '' || !is_dir($dir) || !str_contains($dir, '/updates/')) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    /** @return array<string,mixed>|null */
    private static function readLog(): ?array {
        $f = self::dir() . '/last.json';
        if (!is_file($f)) {
            return null;
        }
        $j = json_decode((string)file_get_contents($f), true);
        return is_array($j) ? $j : null;
    }

    /** @param array<string,mixed> $row */
    private static function writeLog(array $row): void {
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        @file_put_contents(
            self::dir() . '/last.json',
            json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function bytes(int $n): string {
        if ($n >= 1048576) {
            return round($n / 1048576, 1) . ' MB';
        }
        return round($n / 1024) . ' KB';
    }
}

<?php
/**
 * Forma site packages — versioned zip of database + uploads + manifest
 * so future app versions can restore / migrate older sites.
 *
 * Layout (format_version 1):
 *   manifest.json
 *   data.json
 *   database/forma.db
 *   uploads/…
 */
class SitePackage {
    /** How the zip is laid out. Bump only when paths/structure change. */
    public const FORMAT_VERSION = FORMA_EXPORT_FORMAT_VERSION;

    /** SQLite / logical schema generation. Bump when tables/columns change meaningfully. */
    public const SCHEMA_VERSION = FORMA_SCHEMA_VERSION;

    public const FORMAT = 'formax-site';

    public static function supportedFormatVersions(): array {
        return [1];
    }

    public static function supportedSchemaVersions(): array {
        return [1];
    }

    /** Build portable JSON (no binary). Safe for API; excludes users/tokens. */
    public static function buildDataJson(): array {
        $db = Database::get();
        RedirectRepo::ensureTable();
        return [
            'format'          => 'formax-export',
            'format_version'  => self::FORMAT_VERSION,
            'schema_version'  => self::SCHEMA_VERSION,
            'app_version'     => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'exported'        => date('c'),
            'settings'        => $db->getConfig(),
            'pages'           => $db->query('SELECT * FROM pages'),
            'blog_posts'      => $db->query('SELECT * FROM blog_posts'),
            'snippets'        => $db->query('SELECT * FROM snippets'),
            'podcast_episodes'=> $db->query('SELECT * FROM podcast_episodes'),
            'redirects'       => $db->query('SELECT * FROM redirects'),
        ];
    }

    public static function buildManifest(array $extra = []): array {
        $site = Database::get()->getSetting('site');
        $uploadStats = self::uploadsStats();
        return array_merge([
            'format'          => self::FORMAT,
            'format_version'  => self::FORMAT_VERSION,
            'schema_version'  => self::SCHEMA_VERSION,
            'app_version'     => defined('FORMA_VERSION') ? FORMA_VERSION : '0',
            'app_version_date'=> defined('FORMA_VERSION_DATE') ? FORMA_VERSION_DATE : '',
            'exported_at'     => date('c'),
            'site_url'        => $site['url'] ?? '',
            'site_title'      => $site['title'] ?? '',
            'php_version'     => PHP_VERSION,
            'contents'        => [
                'manifest'  => 'manifest.json',
                'data_json' => 'data.json',
                'database'  => 'database/forma.db',
                'uploads'   => 'uploads/',
            ],
            'counts'          => [
                'pages'            => (int)(Database::get()->queryOne('SELECT COUNT(*) AS c FROM pages')['c'] ?? 0),
                'blog_posts'       => (int)(Database::get()->queryOne('SELECT COUNT(*) AS c FROM blog_posts')['c'] ?? 0),
                'snippets'         => (int)(Database::get()->queryOne('SELECT COUNT(*) AS c FROM snippets')['c'] ?? 0),
                'podcast_episodes' => (int)(Database::get()->queryOne('SELECT COUNT(*) AS c FROM podcast_episodes')['c'] ?? 0),
                'redirects'        => (int)(Database::get()->queryOne('SELECT COUNT(*) AS c FROM redirects')['c'] ?? 0),
                'uploads_files'    => $uploadStats['files'],
                'uploads_bytes'    => $uploadStats['bytes'],
            ],
        ], $extra);
    }

    public static function uploadsStats(): array {
        $files = 0;
        $bytes = 0;
        $root = rtrim(UPLOADS_DIR, '/');
        if (is_dir($root)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $files++;
                    $bytes += $f->getSize();
                }
            }
        }
        return ['files' => $files, 'bytes' => $bytes];
    }

    /**
     * Create a temp .zip path. Caller must unlink when done (or use streamAndDelete).
     * @return array{path:string,filename:string,bytes:int,manifest:array}
     */
    public static function createZip(): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required for site packages');
        }
        if (!is_file(DB_FILE)) {
            throw new RuntimeException('Database file missing');
        }

        $manifest = self::buildManifest();
        $data = self::buildDataJson();
        $filename = 'formax-site-' . date('Ymd-His') . '.zip';
        $zipPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        // Consistent SQLite snapshot
        try {
            Database::get()->pdo()->exec('PRAGMA wal_checkpoint(FULL)');
        } catch (Throwable $e) {
            // non-fatal
        }

        $tmpDb = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'formax-db-' . uniqid('', true) . '.db';
        $copied = false;
        try {
            $safe = str_replace("'", "''", $tmpDb);
            Database::get()->pdo()->exec("VACUUM INTO '{$safe}'");
            $copied = is_file($tmpDb);
        } catch (Throwable $e) {
            $copied = @copy(DB_FILE, $tmpDb);
        }
        if (!$copied || !is_file($tmpDb)) {
            throw new RuntimeException('Could not snapshot database for export');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmpDb);
            throw new RuntimeException('Could not create zip: ' . $zipPath);
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->addFile($tmpDb, 'database/forma.db');

        $uploadsRoot = rtrim(UPLOADS_DIR, '/');
        if (is_dir($uploadsRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadsRoot, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $full = $file->getPathname();
                $rel = 'uploads/' . ltrim(str_replace('\\', '/', substr($full, strlen($uploadsRoot))), '/');
                // Skip junk
                if (str_contains($rel, '/.DS_Store') || str_ends_with($rel, '.DS_Store')) {
                    continue;
                }
                $zip->addFile($full, $rel);
            }
        }

        $zip->close();
        @unlink($tmpDb);

        if (!is_file($zipPath)) {
            throw new RuntimeException('Zip was not written');
        }

        return [
            'path'     => $zipPath,
            'filename' => $filename,
            'bytes'    => (int)filesize($zipPath),
            'manifest' => $manifest,
        ];
    }

    /** Stream zip to client and delete temp file. */
    public static function streamZipDownload(): void {
        $pkg = self::createZip();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $pkg['filename'] . '"');
        header('Content-Length: ' . $pkg['bytes']);
        header('X-Forma-Package-Format: ' . self::FORMAT . '/' . self::FORMAT_VERSION);
        header('X-Forma-Schema-Version: ' . self::SCHEMA_VERSION);
        header('Cache-Control: no-store');
        readfile($pkg['path']);
        @unlink($pkg['path']);
    }

    /**
     * Import a site package zip.
     * @return array{ok:bool,message:string,manifest?:array,stats?:array}
     */
    public static function importZip(string $zipPath, bool $replaceDatabase = true, bool $mergeUploads = true): array {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('PHP ZipArchive extension is required');
        }
        if (!is_file($zipPath)) {
            throw new RuntimeException('Package file not found');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open package zip');
        }

        $manifestRaw = $zip->getFromName('manifest.json');
        if ($manifestRaw === false) {
            $zip->close();
            throw new RuntimeException('Invalid package: missing manifest.json');
        }
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT) {
            $zip->close();
            throw new RuntimeException('Invalid package: not a formax-site archive');
        }

        $fmt = (int)($manifest['format_version'] ?? 0);
        $schema = (int)($manifest['schema_version'] ?? 0);
        if (!in_array($fmt, self::supportedFormatVersions(), true)) {
            $zip->close();
            throw new RuntimeException("Unsupported format_version {$fmt}. This Forma supports: " . implode(', ', self::supportedFormatVersions()));
        }
        if ($schema > self::SCHEMA_VERSION) {
            $zip->close();
            throw new RuntimeException("Package schema_version {$schema} is newer than this app (" . self::SCHEMA_VERSION . "). Upgrade Forma first.");
        }

        $extractDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'formax-import-' . uniqid('', true);
        if (!@mkdir($extractDir, 0755, true)) {
            $zip->close();
            throw new RuntimeException('Could not create temp extract dir');
        }
        $zip->extractTo($extractDir);
        $zip->close();

        $stats = ['database' => false, 'uploads' => 0, 'json_fallback' => false, 'backup' => ''];

        try {
            // Backup current DB
            if (is_file(DB_FILE)) {
                $bak = DB_FILE . '.bak-' . date('YmdHis');
                if (@copy(DB_FILE, $bak)) {
                    $stats['backup'] = basename($bak);
                }
            }

            $pkgDb = $extractDir . '/database/forma.db';
            if ($replaceDatabase && is_file($pkgDb)) {
                // Close PDO so file can be replaced on Windows/some hosts
                Database::get()->pdo()->exec('PRAGMA wal_checkpoint(FULL)');
                if (!@copy($pkgDb, DB_FILE)) {
                    throw new RuntimeException('Could not replace database/forma.db — check permissions');
                }
                @unlink(DB_FILE . '-wal');
                @unlink(DB_FILE . '-shm');
                $stats['database'] = true;
                // Re-init schema migrations on next get() — reset singleton
                self::resetDatabaseSingleton();
                Database::get(); // runs migrations
                self::migrateSchemaIfNeeded($schema);
            } elseif (!$replaceDatabase) {
                // JSON merge path
                $jsonPath = $extractDir . '/data.json';
                if (is_file($jsonPath)) {
                    $data = json_decode((string)file_get_contents($jsonPath), true);
                    if (is_array($data)) {
                        Importer::fromJson($data);
                        $stats['json_fallback'] = true;
                    }
                }
            } elseif (!is_file($pkgDb)) {
                // No db in package — use JSON
                $jsonPath = $extractDir . '/data.json';
                if (!is_file($jsonPath)) {
                    throw new RuntimeException('Package has neither database/forma.db nor data.json');
                }
                $data = json_decode((string)file_get_contents($jsonPath), true);
                if (!is_array($data)) {
                    throw new RuntimeException('data.json is invalid');
                }
                Importer::fromJson($data);
                $stats['json_fallback'] = true;
                self::migrateSchemaIfNeeded($schema);
            }

            if ($mergeUploads) {
                $srcUploads = $extractDir . '/uploads';
                if (is_dir($srcUploads)) {
                    $stats['uploads'] = self::copyTree($srcUploads, rtrim(UPLOADS_DIR, '/'));
                }
            }

            Database::get()->flushCache();
        } finally {
            self::rrmdir($extractDir);
        }

        return [
            'ok' => true,
            'message' => 'Site package imported',
            'manifest' => $manifest,
            'stats' => $stats,
        ];
    }

    /** Placeholder for future schema upgrades when importing older packages. */
    public static function migrateSchemaIfNeeded(int $fromSchema): void {
        if ($fromSchema >= self::SCHEMA_VERSION) {
            return;
        }
        // v1 → future: add migrators here in order
        // for ($v = $fromSchema; $v < self::SCHEMA_VERSION; $v++) { ... }
        RedirectRepo::ensureTable();
    }

    private static function resetDatabaseSingleton(): void {
        $ref = new ReflectionClass(Database::class);
        if ($ref->hasProperty('instance')) {
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }

    private static function copyTree(string $src, string $dst): int {
        $count = 0;
        if (!is_dir($dst)) {
            @mkdir($dst, 0755, true);
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $srcLen = strlen($src);
        foreach ($it as $item) {
            $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), $srcLen)), '/');
            $target = $dst . '/' . $rel;
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                if (@copy($item->getPathname(), $target)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}

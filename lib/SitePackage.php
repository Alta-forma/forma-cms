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
        // Every schema generation up to the running app's; newer ones are
        // rejected in importZip(). Older packages migrate via Database::migrateColumns().
        return range(1, (int)self::SCHEMA_VERSION);
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
        $publishedBefore = StaticFallback::publishedPaths();

        try {
            // Backup current DB (consistent snapshot, not a raw copy of a WAL-mode file)
            if (is_file(DB_FILE)) {
                $bak = DB_FILE . '.bak-' . date('YmdHis');
                try {
                    Database::get()->pdo()->exec('VACUUM INTO ' . Database::get()->pdo()->quote($bak));
                    $stats['backup'] = basename($bak);
                } catch (Throwable $e) {
                    if (@copy(DB_FILE, $bak)) {
                        $stats['backup'] = basename($bak);
                    }
                }
                self::pruneBackups(3);
            }

            $pkgDb = $extractDir . '/database/forma.db';
            if ($replaceDatabase && is_file($pkgDb)) {
                self::replaceContentFromSqlite($pkgDb);
                $stats['database'] = true;
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
            Render::forgetSiteContext();
            Render::forgetSnippetMap();
            StaticFallback::removePublishedPaths(array_diff($publishedBefore, StaticFallback::publishedPaths()));
            try {
                $stats['search'] = Search::reindexAll();
                Feed::maybeRegenerateBlog();
                Feed::maybeRegeneratePodcast();
                Htaccess::syncRedirectsBlock(RedirectRepo::list());
                $stats['fallback'] = StaticFallback::republishIfEnabled();
            } catch (Throwable $e) {
                StaticFallback::disable();
                $stats['rebuild_warning'] = 'Import completed, but derived output rebuild failed. Static HTML is safely disabled: ' . $e->getMessage();
            }
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
        // v1 → v2 is handled by Database::migrateColumns(): split destructive
        // content/media delete scopes while preserving legacy token behavior.
        RedirectRepo::ensureTable();
    }

    /**
     * Replace the live database's content with the package's, table by table,
     * inside one write transaction. Same strategy as AgentCheckpoint: the live
     * file/WAL inode never changes, so other PHP-FPM workers obey SQLite
     * locking instead of holding handles to a swapped-out file.
     *
     * Every real table present in both databases is copied via their shared
     * columns (so an older-schema package imports; new columns keep defaults).
     * Derived caches (page_cache, search_*) are cleared in the same transaction
     * and rebuilt by the caller.
     */
    private static function replaceContentFromSqlite(string $pkgDb): void {
        $pdo = Database::get()->pdo();
        $alias = 'fx_import';
        $attached = false;
        try {
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($pkgDb) . ' AS ' . $alias);
            $attached = true;

            $skip = ['page_cache', 'search_index', 'search_index_plain'];
            $pkgTables = $pdo->query(
                "SELECT name FROM {$alias}.sqlite_master
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%' AND name NOT LIKE 'search_index%'
                   AND sql NOT LIKE 'CREATE VIRTUAL%'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $mainTables = $pdo->query(
                "SELECT name FROM main.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];

            $pdo->exec('BEGIN IMMEDIATE');
            foreach ($pkgTables as $table) {
                if (in_array($table, $skip, true) || !in_array($table, $mainTables, true)) {
                    continue;
                }
                $quoted = '"' . str_replace('"', '""', $table) . '"';
                $mainCols = array_column($pdo->query("PRAGMA main.table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                $pkgCols = array_column($pdo->query("PRAGMA {$alias}.table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                $cols = array_values(array_intersect($mainCols, $pkgCols));
                if (!$cols) {
                    continue;
                }
                $colList = implode(', ', array_map(static fn ($c) => '"' . str_replace('"', '""', $c) . '"', $cols));
                $pdo->exec('DELETE FROM main.' . $quoted);
                $pdo->exec("INSERT INTO main.{$quoted} ({$colList}) SELECT {$colList} FROM {$alias}.{$quoted}");
            }
            foreach ($skip as $cache) {
                if (in_array($cache, $mainTables, true)) {
                    $pdo->exec('DELETE FROM main."' . $cache . '"');
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw new RuntimeException('Could not import the package database: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($attached) {
                try {
                    $pdo->exec('DETACH DATABASE ' . $alias);
                } catch (Throwable $ignored) {
                }
            }
        }
        // Package may be an older schema: make sure seeded system rows exist.
        Database::get()->initSchema();
    }

    /** Keep only the newest $keep pre-import backups next to the live DB. */
    private static function pruneBackups(int $keep): void {
        $files = glob(DB_FILE . '.bak-*') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        sort($files); // timestamped names sort chronologically
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
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

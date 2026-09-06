<?php
/**
 * Forma – one last-known-good rollback point for Agent API edits.
 *
 * This is intentionally not a revision system. The first mutating Agent API
 * request after an accepted state snapshots the live SQLite database. Further
 * agent writes keep that same snapshot. Restore puts it back; accept replaces
 * it with the current database and arms the next write to take a fresh point.
 *
 * Uploads are not copied wholesale. Files deleted by an agent while a
 * checkpoint is pending are moved into a small sidecar trash directory so a
 * restore can put them back. Newly uploaded but later-unused files are harmless
 * and remain in uploads/.
 */
class AgentCheckpoint {
    private const DB_BASENAME = 'agent-checkpoint.db';
    private const STATE_BASENAME = 'agent-checkpoint.json';
    private const LOCK_BASENAME = 'agent-checkpoint.lock';
    private const TRASH_BASENAME = 'agent-checkpoint-media';
    /** @var resource|null Held through the current Agent API write request. */
    private static $mutationLock = null;

    public static function status(): array {
        $state = self::readState();
        $exists = is_file(self::dbPath());
        return [
            'available' => $exists,
            'pending' => $exists && !empty($state['pending']),
            'created_at' => $exists ? (int)($state['created_at'] ?? @filemtime(self::dbPath()) ?: 0) : null,
            'accepted_at' => isset($state['accepted_at']) ? (int)$state['accepted_at'] : null,
            'started_at' => isset($state['started_at']) ? (int)$state['started_at'] : null,
            'started_by' => (string)($state['started_by'] ?? ''),
            'bytes' => $exists ? (int)@filesize(self::dbPath()) : 0,
            'message' => !$exists
                ? 'No rollback point yet. Forma will create one before the next agent edit.'
                : (!empty($state['pending'])
                    ? 'Agent edits are in progress. Put it back to restore the last known good site, or mark it good to keep the current site.'
                    : 'The current site is marked good. Forma will protect it before the next agent edit.'),
        ];
    }

    /**
     * Capture the live DB once, immediately before the first write in an edit
     * session. Later writes do not move the rollback point.
     */
    public static function beforeMutation(array $token): array {
        if (is_resource(self::$mutationLock)) {
            return self::beforeMutationUnlocked($token);
        }
        $fh = @fopen(self::lockPath(), 'c+');
        if ($fh === false || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) {
                fclose($fh);
            }
            throw new RuntimeException('Could not lock the rollback point');
        }
        self::$mutationLock = $fh;
        register_shutdown_function([self::class, 'finishMutation']);
        try {
            return self::beforeMutationUnlocked($token);
        } catch (Throwable $e) {
            self::finishMutation();
            throw $e;
        }
    }

    /** Release the request-wide mutation lock (normally called at shutdown). */
    public static function finishMutation(): void {
        if (is_resource(self::$mutationLock)) {
            flock(self::$mutationLock, LOCK_UN);
            fclose(self::$mutationLock);
        }
        self::$mutationLock = null;
    }

    /**
     * Move the rollback point to the current live site. Call only after the
     * human says the site looks right.
     */
    public static function accept(string $by = 'Admin'): array {
        self::finishMutation();
        return self::locked(function () use ($by): array {
            $snapshot = self::createSnapshotFile();
            try {
                self::clearMediaTrash();
                self::installSnapshotFile($snapshot);
            } catch (Throwable $e) {
                @unlink($snapshot);
                throw $e;
            }
            $now = time();
            self::writeState([
                'pending' => false,
                'created_at' => $now,
                'accepted_at' => $now,
                'started_at' => null,
                'started_by' => '',
                'accepted_by' => $by,
            ]);
            return self::status();
        });
    }

    /**
     * Restore the last-known-good DB, restore agent-deleted media, then rebuild
     * all derived output. The checkpoint remains available and becomes the
     * accepted current state.
     */
    public static function restore(string $by = 'Admin'): array {
        self::finishMutation();
        return self::locked(function () use ($by): array {
            if (!is_file(self::dbPath())) {
                throw new RuntimeException('No rollback point is available');
            }

            $obsoletePaths = self::restoreDatabaseInPlace();
            $warnings = [];
            $rebuilt = [];
            try {
                self::restoreMediaTrash();
                if (class_exists('StaticFallback')) {
                    StaticFallback::removePublishedPaths($obsoletePaths);
                }
                $rebuilt = self::rebuildDerivedOutput();
            } catch (Throwable $e) {
                if (class_exists('StaticFallback')) {
                    StaticFallback::disable();
                }
                $warnings[] = 'The site content was restored, but derived output needs a retry. Static HTML is safely disabled: ' . $e->getMessage();
            }
            $now = time();
            self::writeState([
                'pending' => false,
                'created_at' => (int)(@filemtime(self::dbPath()) ?: $now),
                'accepted_at' => $now,
                'started_at' => null,
                'started_by' => '',
                'restored_at' => $now,
                'restored_by' => $by,
            ]);

            if (!empty($rebuilt['fallback']['enabled']) && empty($rebuilt['fallback']['complete'])) {
                $warnings[] = 'The site content was restored, but static HTML was left safely disabled because its rebuild was incomplete. Visitors are using live PHP pages.';
            }
            return [
                'success' => true,
                'checkpoint' => self::status(),
                'rebuilt' => $rebuilt,
                'warnings' => $warnings,
            ];
        });
    }

    /**
     * Preserve a media file before an Agent API deletion. No-op unless an edit
     * session is pending. The original delete proceeds only after this returns.
     */
    public static function preserveDeletedMedia(string $filename): void {
        $state = self::status();
        if (empty($state['pending'])) {
            return;
        }
        $safe = basename($filename);
        $src = UPLOADS_DIR . '/' . $safe;
        if (!is_file($src)) {
            return;
        }
        $dir = self::trashPath();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create rollback media storage');
        }
        $dest = $dir . '/' . $safe;
        if (!is_file($dest) && !@copy($src, $dest)) {
            throw new RuntimeException('Could not preserve media for rollback: ' . $safe);
        }
        @chmod($dest, 0640);
    }

    private static function beforeMutationUnlocked(array $token): array {
        $state = self::readState();
        if (is_file(self::dbPath()) && !empty($state['pending'])) {
            return self::status();
        }

        self::clearMediaTrash();
        self::snapshotDatabase();
        $now = time();
        self::writeState([
            'pending' => true,
            'created_at' => $now,
            'started_at' => $now,
            'accepted_at' => $state['accepted_at'] ?? null,
            'started_by' => (string)($token['name'] ?? 'Agent'),
            'token_id' => isset($token['id']) ? (int)$token['id'] : null,
        ]);
        return self::status();
    }

    /**
     * Restore source-of-truth site tables inside one SQLite write transaction.
     * This keeps the live DB inode/WAL intact so other PHP-FPM workers obey
     * SQLite locking instead of retaining handles to a replaced file.
     *
     * Users, API tokens, login attempts, and api_audit are intentionally not
     * rolled back: recovery changes the website, not its access history.
     */
    private static function restoreDatabaseInPlace(): array {
        $pdo = Database::get()->pdo();
        $alias = 'fx_checkpoint';
        $tables = [
            'pages',
            'blog_posts',
            'podcast_episodes',
            'snippets',
            'settings',
            'license',
            'redirects',
        ];
        $attached = false;
        $obsoletePaths = [];
        try {
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote(self::dbPath()) . ' AS ' . $alias);
            $attached = true;
            $pdo->exec('BEGIN IMMEDIATE');
            $obsoletePaths = array_values(array_diff(
                self::publishedPaths($pdo, 'main'),
                self::publishedPaths($pdo, $alias)
            ));
            foreach ($tables as $table) {
                $exists = $pdo->query(
                    "SELECT 1 FROM {$alias}.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($table)
                )->fetchColumn();
                if (!$exists) {
                    throw new RuntimeException('Rollback point is missing table: ' . $table);
                }
                $quoted = '"' . str_replace('"', '""', $table) . '"';
                $pdo->exec('DELETE FROM main.' . $quoted);
                $pdo->exec('INSERT INTO main.' . $quoted . ' SELECT * FROM ' . $alias . '.' . $quoted);
            }
            // Derived request caches must become unavailable in the same
            // transaction as source content, so another worker cannot serve
            // agent-era cache rows after COMMIT.
            $pdo->exec('DELETE FROM main.page_cache');
            foreach (['search_index', 'search_index_plain'] as $indexTable) {
                $exists = $pdo->query(
                    "SELECT 1 FROM main.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($indexTable)
                )->fetchColumn();
                if ($exists) {
                    $quoted = '"' . $indexTable . '"';
                    $pdo->exec('DELETE FROM main.' . $quoted);
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->exec('ROLLBACK');
            }
            throw new RuntimeException('Could not restore the rollback point: ' . $e->getMessage(), 0, $e);
        } finally {
            if ($attached) {
                try {
                    $pdo->exec('DETACH DATABASE ' . $alias);
                } catch (Throwable $ignored) {
                }
            }
        }
        return $obsoletePaths;
    }

    /** Public routes that may have generated fallback HTML in one DB schema. */
    private static function publishedPaths(PDO $pdo, string $schema): array {
        $paths = [];
        foreach ($pdo->query('SELECT filename, slug FROM ' . $schema . '.pages')->fetchAll() as $row) {
            $filename = (string)($row['filename'] ?? '');
            $slug = trim((string)($row['slug'] ?? ''));
            if ($filename === 'home') {
                $paths[] = '/';
            } elseif ($slug !== '') {
                $paths[] = $slug;
            }
        }
        foreach ($pdo->query('SELECT slug FROM ' . $schema . '.blog_posts')->fetchAll() as $row) {
            $slug = trim((string)($row['slug'] ?? ''), '/');
            if ($slug !== '') {
                $paths[] = '/blog/' . $slug;
            }
        }
        foreach ($pdo->query('SELECT episode_id FROM ' . $schema . '.podcast_episodes')->fetchAll() as $row) {
            $id = trim((string)($row['episode_id'] ?? ''));
            if ($id !== '') {
                $paths[] = '/podcast/' . $id;
            }
        }
        return array_values(array_unique($paths));
    }

    private static function snapshotDatabase(): void {
        self::installSnapshotFile(self::createSnapshotFile());
    }

    /** Build a verified consistent snapshot without moving the current point. */
    private static function createSnapshotFile(): string {
        $dir = dirname(DB_FILE);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('Database folder is not writable; agent edit was not started');
        }

        $tmp = self::dbPath() . '.tmp-' . bin2hex(random_bytes(4));
        @unlink($tmp);
        $pdo = Database::get()->pdo();
        $pdo->exec('PRAGMA wal_checkpoint(FULL)');
        try {
            $pdo->exec('VACUUM main INTO ' . $pdo->quote($tmp));
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new RuntimeException('Could not create the rollback point: ' . $e->getMessage(), 0, $e);
        }
        if (!is_file($tmp) || (int)@filesize($tmp) < 1) {
            @unlink($tmp);
            throw new RuntimeException('Rollback point was empty; agent edit was stopped');
        }
        @chmod($tmp, 0640);
        return $tmp;
    }

    private static function installSnapshotFile(string $tmp): void {
        if (!@rename($tmp, self::dbPath())) {
            @unlink($tmp);
            throw new RuntimeException('Could not save the rollback point');
        }
    }

    private static function rebuildDerivedOutput(): array {
        $db = Database::get();
        $db->flushCache();
        if (class_exists('Render')) {
            Render::forgetSiteContext();
            Render::forgetSnippetMap();
        }

        $search = class_exists('Search') ? Search::reindexAll() : [];
        $fallback = class_exists('StaticFallback')
            ? (StaticFallback::enabled() ? StaticFallback::publishAll() : ['enabled' => false])
            : [];
        $feeds = ['blog' => false, 'podcast' => false];
        if (class_exists('Feed')) {
            Feed::maybeRegenerateBlog();
            Feed::maybeRegeneratePodcast();
            $feeds = ['blog' => true, 'podcast' => true];
        }
        if (class_exists('RedirectRepo') && class_exists('Htaccess')) {
            Htaccess::syncRedirectsBlock(RedirectRepo::list());
        }
        return ['search' => $search, 'fallback' => $fallback, 'feeds' => $feeds];
    }

    private static function restoreMediaTrash(): void {
        $dir = self::trashPath();
        if (!is_dir($dir)) {
            return;
        }
        if (!is_dir(UPLOADS_DIR) && !@mkdir(UPLOADS_DIR, 0755, true) && !is_dir(UPLOADS_DIR)) {
            throw new RuntimeException('Could not restore deleted media: uploads folder is unavailable');
        }
        foreach (glob($dir . '/*') ?: [] as $src) {
            if (!is_file($src)) {
                continue;
            }
            $dest = UPLOADS_DIR . '/' . basename($src);
            if (!@copy($src, $dest)) {
                throw new RuntimeException('Could not restore media: ' . basename($src));
            }
            @chmod($dest, 0644);
        }
        self::clearMediaTrash();
    }

    private static function clearMediaTrash(): void {
        $dir = self::trashPath();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && !@unlink($file)) {
                throw new RuntimeException('Could not clear old rollback media: ' . basename($file));
            }
        }
        if (!@rmdir($dir) && is_dir($dir)) {
            throw new RuntimeException('Could not clear old rollback media storage');
        }
    }

    private static function locked(callable $callback): mixed {
        $fh = @fopen(self::lockPath(), 'c+');
        if ($fh === false) {
            throw new RuntimeException('Could not lock the rollback point');
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new RuntimeException('Could not lock the rollback point');
            }
            return $callback();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function readState(): array {
        $raw = @file_get_contents(self::statePath());
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $state = json_decode($raw, true);
        return is_array($state) ? $state : [];
    }

    private static function writeState(array $state): void {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = self::statePath() . '.tmp-' . bin2hex(random_bytes(3));
        if ($json === false || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            throw new RuntimeException('Could not write rollback state');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, self::statePath())) {
            @unlink($tmp);
            throw new RuntimeException('Could not save rollback state');
        }
    }

    private static function dbPath(): string {
        return dirname(DB_FILE) . '/' . self::DB_BASENAME;
    }

    private static function statePath(): string {
        return dirname(DB_FILE) . '/' . self::STATE_BASENAME;
    }

    private static function lockPath(): string {
        return dirname(DB_FILE) . '/' . self::LOCK_BASENAME;
    }

    private static function trashPath(): string {
        return dirname(DB_FILE) . '/' . self::TRASH_BASENAME;
    }
}

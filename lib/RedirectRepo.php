<?php
/**
 * Forma – URL redirects (301/302) stored in SQLite, applied in the front controller.
 */
class RedirectRepo {
    public static function ensureTable(): void {
        Database::get()->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS redirects (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                from_path  TEXT    NOT NULL UNIQUE,
                to_url     TEXT    NOT NULL,
                status     INTEGER NOT NULL DEFAULT 301,
                enabled    INTEGER NOT NULL DEFAULT 1,
                note       TEXT    NOT NULL DEFAULT "",
                created_at INTEGER NOT NULL DEFAULT (strftime("%s","now")),
                updated_at INTEGER NOT NULL DEFAULT (strftime("%s","now"))
            )'
        );
    }

    public static function list(): array {
        self::ensureTable();
        return Database::get()->query(
            'SELECT * FROM redirects ORDER BY from_path COLLATE NOCASE ASC'
        );
    }

    public static function normalizeFrom(string $from): string {
        $from = trim($from);
        if ($from === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $from)) {
            $path = parse_url($from, PHP_URL_PATH);
            $from = is_string($path) ? $path : '/';
        }
        $from = '/' . ltrim(str_replace('\\', '/', $from), '/');
        if ($from !== '/') {
            $from = rtrim($from, '/');
        }
        return $from === '' ? '/' : $from;
    }

    public static function match(string $path): ?array {
        self::ensureTable();
        $path = self::normalizeFrom($path);
        $row = Database::get()->queryOne(
            'SELECT * FROM redirects WHERE enabled = 1 AND from_path = ? LIMIT 1',
            [$path]
        );
        if ($row) {
            return $row;
        }
        // normalizeFrom() strips trailing slashes, but rows that arrived via
        // import/migration may still carry one — try that exact variant.
        $alt = $path === '/' ? '/' : $path . '/';
        if ($alt !== $path) {
            $row = Database::get()->queryOne(
                'SELECT * FROM redirects WHERE enabled = 1 AND from_path = ? LIMIT 1',
                [$alt]
            );
            if ($row) {
                return $row;
            }
        }
        return null;
    }

    public static function save(array $data): array {
        self::ensureTable();
        $id = (int)($data['id'] ?? 0);
        $from = self::normalizeFrom((string)($data['from_path'] ?? ''));
        $to = trim((string)($data['to_url'] ?? ''));
        $status = (int)($data['status'] ?? 301);
        if (!in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }
        // Admin form always sends the key (checkbox → bool). API/MCP callers that
        // omit it mean "on" — nobody creates a redirect they want disabled by default.
        $enabled = array_key_exists('enabled', $data) ? (!empty($data['enabled']) ? 1 : 0) : 1;
        $note = trim((string)($data['note'] ?? ''));
        if ($from === '' || $to === '') {
            throw new InvalidArgumentException('From path and destination are required');
        }
        if ($from === '/admin' || str_starts_with($from, '/admin/') || str_starts_with($from, '/api/')) {
            throw new InvalidArgumentException('Cannot redirect /admin or /api paths');
        }
        // A redirect that lands on its own source path loops forever.
        $toPath = $to;
        if (preg_match('#^https?://#i', $to)) {
            $siteUrl = rtrim((string)(Database::get()->getSetting('site')['url'] ?? ''), '/');
            $toHost = strtolower((string)parse_url($to, PHP_URL_HOST));
            $ownHosts = array_filter([
                strtolower((string)parse_url($siteUrl, PHP_URL_HOST)),
                strtolower((string)parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)),
            ]);
            $toPath = ($toHost !== '' && in_array($toHost, $ownHosts, true)) ? (string)(parse_url($to, PHP_URL_PATH) ?: '/') : '';
        }
        if ($toPath !== '' && self::normalizeFrom($toPath) === $from) {
            throw new InvalidArgumentException('Destination is the same as the source path — that would loop');
        }
        $db = Database::get();
        $now = time();
        if ($id > 0) {
            $db->execute(
                'UPDATE redirects SET from_path=?, to_url=?, status=?, enabled=?, note=?, updated_at=? WHERE id=?',
                [$from, $to, $status, $enabled, $note, $now, $id]
            );
        } else {
            $db->execute(
                'INSERT INTO redirects (from_path, to_url, status, enabled, note, created_at, updated_at) VALUES (?,?,?,?,?,?,?)',
                [$from, $to, $status, $enabled, $note, $now, $now]
            );
            $id = (int)$db->pdo()->lastInsertId();
        }
        $row = $db->queryOne('SELECT * FROM redirects WHERE id = ?', [$id]);
        if (class_exists('Htaccess')) {
            Htaccess::syncRedirectsBlock(self::list());
        }
        return $row ?: [];
    }

    public static function delete(int $id): bool {
        self::ensureTable();
        Database::get()->execute('DELETE FROM redirects WHERE id = ?', [$id]);
        if (class_exists('Htaccess')) {
            Htaccess::syncRedirectsBlock(self::list());
        }
        return true;
    }
}

<?php
/**
 * Forma – Site search.
 *
 * Indexes pages, published blog posts, and (if licensed) published podcast
 * episodes into a SQLite FTS5 virtual table. Falls back to a plain table +
 * LIKE queries on hosts that ship SQLite without the FTS5 extension.
 *
 * Snippets are never indexed — they're building blocks, not content.
 */
class Search {
    private static ?bool $fts5 = null;

    public static function fts5Available(): bool {
        if (self::$fts5 !== null) {
            return self::$fts5;
        }
        try {
            $pdo = Database::get()->pdo();
            $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS __fx_fts5_probe USING fts5(x)");
            $pdo->exec("DROP TABLE IF EXISTS __fx_fts5_probe");
            self::$fts5 = true;
        } catch (Throwable $e) {
            self::$fts5 = false;
        }
        return self::$fts5;
    }

    public static function engine(): string {
        return self::fts5Available() ? 'fts5' : 'like';
    }

    public static function ensureIndex(): void {
        $pdo = Database::get()->pdo();
        if (self::fts5Available()) {
            $pdo->exec(
                "CREATE VIRTUAL TABLE IF NOT EXISTS search_index USING fts5(
                    type UNINDEXED, ref_id UNINDEXED, title, body, url UNINDEXED, date_label UNINDEXED,
                    tokenize='unicode61 remove_diacritics 1'
                )"
            );
        } else {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS search_index_plain (
                    type TEXT NOT NULL,
                    ref_id TEXT NOT NULL,
                    title TEXT NOT NULL DEFAULT '',
                    body TEXT NOT NULL DEFAULT '',
                    url TEXT NOT NULL DEFAULT '',
                    date_label TEXT NOT NULL DEFAULT '',
                    PRIMARY KEY (type, ref_id)
                )"
            );
        }
    }

    /** Strip tags, Twig-ish syntax, and [[shortcodes]] down to plain text worth indexing. */
    private static function plainText(string $html): string {
        $html = (string)preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = (string)preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        $html = (string)preg_replace('/\[\[.*?\]\]/', ' ', $html);
        $html = (string)preg_replace('/\{[%{#].*?[%}#]\}/s', ' ', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    private static function table(): string {
        return self::fts5Available() ? 'search_index' : 'search_index_plain';
    }

    public static function indexDocument(string $type, string $refId, string $title, string $bodyHtml, string $url, string $dateLabel = ''): void {
        if ($refId === '' || $url === '') {
            return;
        }
        self::ensureIndex();
        $body = self::plainText($bodyHtml);
        $pdo = Database::get()->pdo();
        if (self::fts5Available()) {
            $pdo->prepare('DELETE FROM search_index WHERE type = ? AND ref_id = ?')->execute([$type, $refId]);
            $pdo->prepare('INSERT INTO search_index (type, ref_id, title, body, url, date_label) VALUES (?,?,?,?,?,?)')
                ->execute([$type, $refId, $title, $body, $url, $dateLabel]);
        } else {
            $pdo->prepare(
                'INSERT INTO search_index_plain (type, ref_id, title, body, url, date_label) VALUES (?,?,?,?,?,?)
                 ON CONFLICT(type, ref_id) DO UPDATE SET
                   title = excluded.title, body = excluded.body, url = excluded.url, date_label = excluded.date_label'
            )->execute([$type, $refId, $title, $body, $url, $dateLabel]);
        }
    }

    public static function removeDocument(string $type, string $refId): void {
        if ($refId === '') {
            return;
        }
        self::ensureIndex();
        $pdo = Database::get()->pdo();
        $pdo->prepare('DELETE FROM ' . self::table() . ' WHERE type = ? AND ref_id = ?')->execute([$type, $refId]);
    }

    /** Page filenames that are internal templates/system pages, never searchable. */
    private const NON_SEARCHABLE_PAGES = [
        '_404', '_403', '_500',
        'blog-archive', 'blog-single', 'podcast-archive', 'podcast-single',
        'search-results', 'search-page',
    ];

    public static function indexPage(array $page): void {
        $filename = (string)($page['filename'] ?? '');
        if (in_array($filename, self::NON_SEARCHABLE_PAGES, true)) {
            return;
        }
        $meta = PageRepo::extractMeta($page['content'] ?? '');
        $robots = strtolower((string)($meta['robots'] ?? ''));
        if (($meta['noindex_search'] ?? '') === 'true' || str_contains($robots, 'noindex')) {
            self::removeDocument('page', $filename);
            return;
        }
        $slug = PageRepo::slugFromContent($page['content'] ?? '', $filename);
        $title = $meta['title'] ?? $filename;
        self::indexDocument('page', $filename, $title, PageRepo::stripMeta($page['content'] ?? ''), $slug);
    }

    public static function removePage(string $filename): void {
        self::removeDocument('page', $filename);
    }

    public static function indexPost(array $post): void {
        $filename = (string)($post['filename'] ?? '');
        if (!BlogRepo::isPubliclyVisible($post)) {
            self::removeDocument('post', $filename);
            return;
        }
        $url = '/blog/' . trim((string)($post['slug'] ?? $filename), '/');
        $dateLabel = !empty($post['published_at']) ? date('M j, Y', (int)$post['published_at']) : '';
        $body = trim(($post['description'] ?? '') . ' ' . ($post['body'] ?? ''));
        self::indexDocument('post', $filename, (string)($post['title'] ?? $filename), $body, $url, $dateLabel);
    }

    public static function removePost(string $filename): void {
        self::removeDocument('post', $filename);
    }

    public static function indexEpisode(array $ep): void {
        $episodeId = (string)($ep['episode_id'] ?? '');
        $isPublic = class_exists('License') && License::isPodcastLicensed()
            && !empty($ep['published_at']) && (int)$ep['published_at'] <= time();
        if (!$isPublic) {
            self::removeDocument('podcast', $episodeId);
            return;
        }
        $url = '/podcast/' . $episodeId;
        $dateLabel = date('M j, Y', (int)$ep['published_at']);
        $body = trim(($ep['description'] ?? '') . ' ' . ($ep['show_notes'] ?? ''));
        self::indexDocument('podcast', $episodeId, (string)($ep['title'] ?? $episodeId), $body, $url, $dateLabel);
    }

    public static function removeEpisode(string $episodeId): void {
        self::removeDocument('podcast', $episodeId);
    }

    /** Full reindex — used by "Rebuild HTML cache" / manual maintenance. */
    public static function reindexAll(): array {
        self::ensureIndex();
        Database::get()->pdo()->exec('DELETE FROM ' . self::table());
        $counts = ['page' => 0, 'post' => 0, 'podcast' => 0];
        foreach (PageRepo::list() as $row) {
            $full = PageRepo::get($row['filename']);
            if ($full) {
                self::indexPage($full);
                $counts['page']++;
            }
        }
        foreach (BlogRepo::list(true) as $row) {
            $full = BlogRepo::get($row['filename']);
            if ($full) {
                self::indexPost($full);
                $counts['post']++;
            }
        }
        if (class_exists('License') && License::isPodcastLicensed()) {
            foreach (PodcastRepo::list() as $ep) {
                self::indexEpisode($ep);
                $counts['podcast']++;
            }
        }
        return $counts;
    }

    public static function count(): int {
        try {
            self::ensureIndex();
            $row = Database::get()->queryOne('SELECT COUNT(*) AS c FROM ' . self::table());
            return (int)($row['c'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @return array<int, array{type:string,title:string,url:string,date_label:string,excerpt:string}> */
    public static function query(string $q, int $limit = 20): array {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        self::ensureIndex();
        $limit = max(1, min(50, $limit));
        $pdo = Database::get()->pdo();

        if (self::fts5Available()) {
            $match = self::fts5MatchExpr($q);
            if ($match === '') {
                return [];
            }
            try {
                $stmt = $pdo->prepare(
                    "SELECT type, title, url, date_label,
                            snippet(search_index, 2, '<mark>', '</mark>', '…', 12) AS excerpt
                     FROM search_index
                     WHERE search_index MATCH :match
                     ORDER BY bm25(search_index)
                     LIMIT :limit"
                );
                $stmt->bindValue(':match', $match);
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                // Malformed MATCH syntax slipping through the quoting above — fail soft, no results.
                error_log('Forma search FTS5 query error: ' . $e->getMessage());
                return [];
            }
        }

        $like = '%' . addcslashes($q, '%_\\') . '%';
        $stmt = $pdo->prepare(
            "SELECT type, title, url, date_label, body
             FROM search_index_plain
             WHERE title LIKE :like ESCAPE '\\' OR body LIKE :like ESCAPE '\\'
             ORDER BY (title LIKE :like2 ESCAPE '\\') DESC, title
             LIMIT :limit"
        );
        $stmt->bindValue(':like', $like);
        $stmt->bindValue(':like2', $like);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['excerpt'] = self::plainExcerpt((string)($r['body'] ?? ''), $q);
            unset($r['body']);
        }
        return $rows;
    }

    /** Quote each token so user punctuation/operators can't break FTS5 MATCH syntax. */
    private static function fts5MatchExpr(string $q): string {
        $terms = preg_split('/\s+/u', trim($q)) ?: [];
        $terms = array_filter(array_map(static function ($t) {
            $t = preg_replace('/[^\p{L}\p{N}_]/u', '', $t) ?? '';
            return $t === '' ? '' : '"' . $t . '"*';
        }, $terms));
        return implode(' ', $terms);
    }

    private static function plainExcerpt(string $text, string $q, int $len = 160): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $pos = mb_stripos($text, $q);
        if ($pos === false) {
            $excerpt = mb_substr($text, 0, $len);
            return htmlspecialchars($excerpt) . (mb_strlen($text) > $len ? '…' : '');
        }
        $start = max(0, $pos - (int)($len / 2));
        $excerpt = mb_substr($text, $start, $len);
        $needle = preg_quote(htmlspecialchars($q, ENT_QUOTES), '/');
        $excerpt = htmlspecialchars($excerpt);
        $excerpt = (string)preg_replace('/(' . $needle . ')/i', '<mark>$1</mark>', $excerpt);
        return ($start > 0 ? '…' : '') . $excerpt . (mb_strlen($text) > $start + $len ? '…' : '');
    }
}

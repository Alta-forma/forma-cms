<?php
/**
 * Forma – Pages repository (shared by admin UI + Agent API).
 */
class PageRepo {
    public static function list(): array {
        return Database::get()->query(
            'SELECT filename, content_type, slug, updated_at FROM pages ORDER BY filename'
        );
    }

    public static function get(string $filename): ?array {
        $filename = self::sanitizeFilename($filename);
        return Database::get()->queryOne('SELECT * FROM pages WHERE filename = ?', [$filename]);
    }

    public static function getBySlug(string $slug): ?array {
        return Database::get()->queryOne('SELECT * FROM pages WHERE slug = ?', [$slug]);
    }

    public static function extractMeta(string $content): array {
        $meta = [];
        if (preg_match('/^\s*<!--META\s*(.*?)\s*-->/s', $content, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^\s*([^:]+):\s*(.*)$/', $line, $kv)) {
                    $meta[trim($kv[1])] = trim($kv[2]);
                }
            }
        }
        return $meta;
    }

    public static function stripMeta(string $content): string {
        return (string)preg_replace('/^\s*<!--META\s*.*?\s*-->\s*/s', '', $content);
    }

    public static function slugFromContent(string $content, string $filename): string {
        $meta = self::extractMeta($content);
        if (!empty($meta['slug'])) {
            $s = '/' . ltrim($meta['slug'], '/');
            return $s === '//' ? '/' : $s;
        }
        return '/' . $filename;
    }

    public static function withMeta(string $content, array $fields): string {
        $body = self::stripMeta($content);
        $meta = self::extractMeta($content);
        foreach ($fields as $k => $v) {
            if ($v === null) {
                continue;
            }
            $v = trim((string)$v);
            if ($v === '') {
                unset($meta[$k]);
            } else {
                $meta[$k] = $v;
            }
        }
        if (!$meta) {
            return $body;
        }
        $metaBlock = "<!--META\n";
        foreach ($meta as $k => $v) {
            $metaBlock .= $k . ': ' . $v . "\n";
        }
        $metaBlock .= "-->\n";
        return $metaBlock . $body;
    }

    public static function save(string $filename, string $content, string $contentType = 'html', ?string $slugField = null, array $extraMeta = []): array {
        $filename = self::sanitizeFilename($filename);
        if ($filename === '') {
            throw new InvalidArgumentException('Filename is required');
        }
        if (!in_array($contentType, ['html', 'md'], true)) {
            $contentType = 'html';
        }

        $metaPatch = $extraMeta;
        if ($slugField !== null && $slugField !== '') {
            $slugNorm = '/' . ltrim($slugField, '/');
            if ($slugNorm === '//') {
                $slugNorm = '/';
            }
            $metaPatch['slug'] = $slugNorm;
        }
        if ($metaPatch) {
            $meta = self::extractMeta($content);
            if (empty($meta['title']) && empty($metaPatch['title'])) {
                $metaPatch['title'] = $filename;
            }
            $content = self::withMeta($content, $metaPatch);
        }

        $slug = self::slugFromContent($content, $filename);
        $db = Database::get();
        $db->execute(
            'INSERT INTO pages (filename, content_type, slug, content)
             VALUES (?, ?, ?, ?)
             ON CONFLICT(filename) DO UPDATE SET
               content_type = excluded.content_type,
               slug         = excluded.slug,
               content      = excluded.content,
               updated_at   = strftime(\'%s\',\'now\')',
            [$filename, $contentType, $slug, $content]
        );
        $db->flushCache();
        return self::get($filename) ?? [];
    }

    public static function delete(string $filename): void {
        $filename = self::sanitizeFilename($filename);
        if (in_array($filename, ['home', '_404', '_403', '_500'], true)) {
            throw new RuntimeException('System pages cannot be deleted');
        }
        $db = Database::get();
        $row = $db->queryOne('SELECT id FROM pages WHERE filename = ?', [$filename]);
        if (!$row) {
            throw new RuntimeException('Page not found');
        }
        $db->execute('DELETE FROM pages WHERE filename = ?', [$filename]);
        $db->flushCache();
    }

    public static function sanitizeFilename(string $filename): string {
        $filename = preg_replace('/\.(html?|md)$/i', '', trim($filename)) ?? '';
        return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename) ?? '';
    }
}

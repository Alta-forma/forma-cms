<?php
class SnippetRepo {
    public static function list(): array {
        return Database::get()->query(
            'SELECT filename, shortcode, updated_at FROM snippets ORDER BY filename'
        );
    }

    public static function get(string $filename): ?array {
        $filename = PageRepo::sanitizeFilename($filename);
        return Database::get()->queryOne('SELECT * FROM snippets WHERE filename = ?', [$filename]);
    }

    public static function save(string $filename, string $shortcode, string $content): array {
        $filename = PageRepo::sanitizeFilename($filename);
        $shortcode = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($shortcode)) ?? '';
        if ($filename === '' || $shortcode === '') {
            throw new InvalidArgumentException('Filename and shortcode are required');
        }
        Database::get()->execute(
            'INSERT INTO snippets (filename, shortcode, content) VALUES (?, ?, ?)
             ON CONFLICT(filename) DO UPDATE SET
               shortcode = excluded.shortcode,
               content = excluded.content,
               updated_at = strftime(\'%s\',\'now\')',
            [$filename, $shortcode, $content]
        );
        Database::get()->flushCache();
        if (class_exists('StaticFallback')) {
            StaticFallback::republishIfEnabled();
        }
        return self::get($filename) ?? [];
    }

    public static function delete(string $filename): void {
        $filename = PageRepo::sanitizeFilename($filename);
        Database::get()->execute('DELETE FROM snippets WHERE filename = ?', [$filename]);
        Database::get()->flushCache();
        if (class_exists('StaticFallback')) {
            StaticFallback::republishIfEnabled();
        }
    }
}

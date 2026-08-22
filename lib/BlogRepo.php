<?php
/**
 * Forma – Blog posts repository.
 */
class BlogRepo {
    public static function list(?bool $publishedOnly = false): array {
        $db = Database::get();
        if ($publishedOnly) {
            return $db->query(
                'SELECT filename, slug, title, description, author, published_at, categories, tags, updated_at
                   FROM blog_posts
                  WHERE published_at IS NOT NULL AND published_at <= ?
                  ORDER BY published_at DESC',
                [time()]
            );
        }
        return $db->query(
            'SELECT filename, slug, title, description, author, published_at, categories, tags, updated_at
               FROM blog_posts ORDER BY COALESCE(published_at, updated_at) DESC'
        );
    }

    public static function get(string $filename): ?array {
        $filename = PageRepo::sanitizeFilename($filename);
        return Database::get()->queryOne('SELECT * FROM blog_posts WHERE filename = ?', [$filename]);
    }

    public static function getBySlug(string $slug): ?array {
        $slug = trim($slug, '/');
        return Database::get()->queryOne('SELECT * FROM blog_posts WHERE slug = ?', [$slug]);
    }

    public static function slugify(string $title): string {
        $slug = strtolower($title);
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $slug) ?? '';
        $slug = preg_replace('/\s+/', '-', $slug) ?? '';
        $slug = preg_replace('/-+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'post';
    }

    public static function save(array $data): array {
        $filename = PageRepo::sanitizeFilename($data['filename'] ?? '');
        if ($filename === '') {
            throw new InvalidArgumentException('Filename is required');
        }
        $existing = Database::get()->queryOne('SELECT * FROM blog_posts WHERE filename = ?', [$filename]);
        $title = trim($data['title'] ?? ($existing['title'] ?? $filename));
        if (array_key_exists('slug', $data) && trim((string)$data['slug']) !== '') {
            $slug = PageRepo::sanitizeFilename(trim((string)$data['slug'])) ?: self::slugify($title);
        } elseif ($existing && !empty($existing['slug'])) {
            $slug = $existing['slug'];
        } else {
            $slug = self::slugify($title);
        }
        $body  = array_key_exists('body', $data) ? ($data['body'] ?? '') : ($existing['body'] ?? '');
        $description = array_key_exists('description', $data) ? ($data['description'] ?? '') : ($existing['description'] ?? '');
        $author = array_key_exists('author', $data) ? ($data['author'] ?? '') : ($existing['author'] ?? '');
        $categories = $data['categories'] ?? (json_decode($existing['categories'] ?? '[]', true) ?: []);
        $tags = $data['tags'] ?? (json_decode($existing['tags'] ?? '[]', true) ?: []);
        if (is_string($categories)) {
            $categories = array_values(array_filter(array_map('trim', explode(',', $categories))));
        }
        if (is_string($tags)) {
            $tags = array_values(array_filter(array_map('trim', explode(',', $tags))));
        }
        $publishedAt = null;
        if (array_key_exists('published_at', $data) || array_key_exists('date', $data)) {
            if (!empty($data['published_at'])) {
                if (is_numeric($data['published_at'])) {
                    $publishedAt = (int)$data['published_at'];
                } else {
                    $ts = strtotime((string)$data['published_at']);
                    $publishedAt = $ts !== false ? $ts : null;
                }
            } elseif (!empty($data['date'])) {
                $ts = strtotime((string)$data['date']);
                $publishedAt = $ts !== false ? $ts : null;
            } else {
                $publishedAt = null; // explicit unpublish / draft
            }
        } elseif ($existing) {
            $publishedAt = $existing['published_at'] !== null ? (int)$existing['published_at'] : null;
        }

        $seoJson = json_decode($existing['seo_json'] ?? '{}', true) ?: [];
        if (isset($data['seo']) && is_array($data['seo'])) {
            $seoJson = array_merge($seoJson, Seo::parseSeoPostFields(['seo' => $data['seo']]));
        } else {
            $seoJson = array_merge($seoJson, Seo::parseSeoPostFields($data));
        }
        // Allow clearing keys by sending empty string in seo object
        if (isset($data['seo']) && is_array($data['seo'])) {
            foreach (Seo::PAGE_META_KEYS as $k) {
                if (array_key_exists($k, $data['seo']) && trim((string)$data['seo'][$k]) === '') {
                    unset($seoJson[$k]);
                }
            }
        }

        $oldSlug = $existing['slug'] ?? null;

        $db = Database::get();
        $db->execute(
            'INSERT INTO blog_posts (filename, slug, title, body, description, author, categories, tags, published_at, seo_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(filename) DO UPDATE SET
               slug = excluded.slug,
               title = excluded.title,
               body = excluded.body,
               description = excluded.description,
               author = excluded.author,
               categories = excluded.categories,
               tags = excluded.tags,
               published_at = excluded.published_at,
               seo_json = excluded.seo_json,
               updated_at = strftime(\'%s\',\'now\')',
            [
                $filename, $slug, $title, $body, $description, $author,
                json_encode(array_values($categories)),
                json_encode(array_values($tags)),
                $publishedAt,
                json_encode($seoJson),
            ]
        );
        $db->flushCache();
        if (class_exists('Feed')) {
            Feed::maybeRegenerateBlog();
        }
        $saved = self::get($filename) ?? [];

        if ($oldSlug !== null && $oldSlug !== $slug && class_exists('StaticFallback')) {
            StaticFallback::unpublishPost($oldSlug);
        }
        if ($saved && class_exists('StaticFallback')) {
            StaticFallback::publishPost($saved);
            StaticFallback::publishBlogArchive();
        }
        if ($saved && class_exists('Search')) {
            Search::indexPost($saved);
        }

        return $saved;
    }

    public static function delete(string $filename): void {
        $filename = PageRepo::sanitizeFilename($filename);
        $db = Database::get();
        $row = $db->queryOne('SELECT * FROM blog_posts WHERE filename = ?', [$filename]);
        if (!$row) {
            throw new RuntimeException('Post not found');
        }
        $db->execute('DELETE FROM blog_posts WHERE filename = ?', [$filename]);
        $db->flushCache();
        if (class_exists('Feed')) {
            Feed::maybeRegenerateBlog();
        }

        if (class_exists('StaticFallback')) {
            StaticFallback::unpublishPost($row['slug'] ?? $filename);
            StaticFallback::publishBlogArchive();
        }
        if (class_exists('Search')) {
            Search::removePost($filename);
        }
    }

    public static function toPublicPost(array $row, Parsedown $parsedown): array {
        return array_merge($row, [
            'date'       => $row['published_at'] ? date('Y-m-d', (int)$row['published_at']) : '',
            'categories' => json_decode($row['categories'] ?: '[]', true) ?: [],
            'tags'       => json_decode($row['tags'] ?: '[]', true) ?: [],
            'seo'        => json_decode($row['seo_json'] ?? '{}', true) ?: [],
            'content'    => $parsedown->text($row['body'] ?? ''),
        ]);
    }

    public static function isPubliclyVisible(array $row): bool {
        if (empty($row['published_at'])) {
            return false;
        }
        return (int)$row['published_at'] <= time();
    }
}

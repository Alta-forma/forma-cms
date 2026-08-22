<?php
/**
 * Import a Forma JSON export (or compatible formax-export) into this install.
 */
class Importer {
    public static function fromJson(array $data): array {
        $db = Database::get();
        $stats = ['pages' => 0, 'posts' => 0, 'snippets' => 0, 'episodes' => 0, 'settings' => 0];

        if (!empty($data['settings']) && is_array($data['settings'])) {
            foreach ($data['settings'] as $section => $value) {
                if (is_array($value)) {
                    $db->saveSetting($section, $value);
                    $stats['settings']++;
                }
            }
        }

        foreach ($data['pages'] ?? [] as $p) {
            if (empty($p['filename'])) {
                continue;
            }
            PageRepo::save(
                $p['filename'],
                $p['content'] ?? '',
                $p['content_type'] ?? 'html',
                $p['slug'] ?? null
            );
            $stats['pages']++;
        }

        foreach ($data['blog_posts'] ?? [] as $p) {
            if (empty($p['filename'])) {
                continue;
            }
            BlogRepo::save([
                'filename'    => $p['filename'],
                'title'       => $p['title'] ?? '',
                'slug'        => $p['slug'] ?? '',
                'body'        => $p['body'] ?? ($p['content'] ?? ''),
                'description' => $p['description'] ?? '',
                'author'      => $p['author'] ?? '',
                'categories'  => json_decode($p['categories'] ?? '[]', true) ?: [],
                'tags'        => json_decode($p['tags'] ?? '[]', true) ?: [],
                'published_at'=> $p['published_at'] ?? null,
            ]);
            $stats['posts']++;
        }

        foreach ($data['snippets'] ?? [] as $s) {
            if (empty($s['filename']) || empty($s['shortcode'])) {
                continue;
            }
            SnippetRepo::save($s['filename'], $s['shortcode'], $s['content'] ?? '');
            $stats['snippets']++;
        }

        foreach ($data['podcast_episodes'] ?? [] as $e) {
            if (empty($e['episode_id'])) {
                continue;
            }
            try {
                // Allow import even if locked — store rows; editing still gated
                $db->execute(
                    'INSERT INTO podcast_episodes (
                        episode_id, title, description, show_notes, audio_file, duration,
                        episode_number, season_number, episode_type, explicit, keywords, episode_art, published_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT(episode_id) DO UPDATE SET
                       title=excluded.title, description=excluded.description, show_notes=excluded.show_notes,
                       audio_file=excluded.audio_file, duration=excluded.duration,
                       episode_number=excluded.episode_number, season_number=excluded.season_number,
                       episode_type=excluded.episode_type, explicit=excluded.explicit,
                       keywords=excluded.keywords, episode_art=excluded.episode_art,
                       published_at=excluded.published_at, updated_at=strftime(\'%s\',\'now\')',
                    [
                        $e['episode_id'], $e['title'] ?? '', $e['description'] ?? '', $e['show_notes'] ?? '',
                        $e['audio_file'] ?? '', $e['duration'] ?? '00:00:00',
                        (int)($e['episode_number'] ?? 0), (int)($e['season_number'] ?? 1),
                        $e['episode_type'] ?? 'full', (int)($e['explicit'] ?? 0),
                        $e['keywords'] ?? '', $e['episode_art'] ?? '', $e['published_at'] ?? null,
                    ]
                );
                $stats['episodes']++;
            } catch (Throwable $ex) {
                error_log('Episode import skip: ' . $ex->getMessage());
            }
        }

        $stats['redirects'] = 0;
        foreach ($data['redirects'] ?? [] as $r) {
            if (empty($r['from_path']) || empty($r['to_url'])) {
                continue;
            }
            try {
                RedirectRepo::save([
                    'from_path' => $r['from_path'],
                    'to_url'    => $r['to_url'],
                    'status'    => (int)($r['status'] ?? 301),
                    'enabled'   => !array_key_exists('enabled', $r) || !empty($r['enabled']),
                    'note'      => $r['note'] ?? '',
                ]);
                $stats['redirects']++;
            } catch (Throwable $ex) {
                error_log('Redirect import skip: ' . $ex->getMessage());
            }
        }

        Feed::maybeRegenerateBlog();
        Feed::maybeRegeneratePodcast();
        $db->flushCache();
        return $stats;
    }

    public static function fromSqliteFile(string $path): array {
        if (!is_file($path)) {
            throw new RuntimeException('DB file not found: ' . $path);
        }
        $src = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $data = ['settings' => [], 'pages' => [], 'blog_posts' => [], 'snippets' => [], 'podcast_episodes' => []];

        try {
            foreach ($src->query('SELECT section, value FROM settings') as $row) {
                $data['settings'][$row['section']] = json_decode($row['value'], true) ?? [];
            }
        } catch (Throwable $e) { /* ignore */ }

        foreach (['pages', 'blog_posts', 'snippets', 'podcast_episodes'] as $table) {
            try {
                $data[$table] = $src->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { /* ignore missing tables */ }
        }
        return self::fromJson($data);
    }
}

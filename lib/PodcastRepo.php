<?php
class PodcastRepo {
    public static function list(): array {
        return Database::get()->query(
            'SELECT * FROM podcast_episodes ORDER BY COALESCE(published_at, created_at) DESC'
        );
    }

    public static function get(string $episodeId): ?array {
        $episodeId = preg_replace('/[^a-zA-Z0-9._-]/', '', $episodeId) ?? '';
        return Database::get()->queryOne('SELECT * FROM podcast_episodes WHERE episode_id = ?', [$episodeId]);
    }

    public static function save(array $data): array {
        if (!License::isPodcastLicensed()) {
            throw new RuntimeException('Podcast feature is locked');
        }
        $id = preg_replace('/[^a-zA-Z0-9._-]/', '', $data['episode_id'] ?? '') ?? '';
        if ($id === '') {
            $id = 'ep-' . bin2hex(random_bytes(4));
        }
        $publishedAt = null;
        if (!empty($data['published_at'])) {
            $publishedAt = is_numeric($data['published_at'])
                ? (int)$data['published_at']
                : (strtotime((string)$data['published_at']) ?: null);
        } elseif (!empty($data['date'])) {
            $publishedAt = strtotime((string)$data['date']) ?: null;
        }

        Database::get()->execute(
            'INSERT INTO podcast_episodes (
                episode_id, title, description, show_notes, audio_file, duration,
                episode_number, season_number, episode_type, explicit, keywords, episode_art, published_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(episode_id) DO UPDATE SET
               title = excluded.title,
               description = excluded.description,
               show_notes = excluded.show_notes,
               audio_file = excluded.audio_file,
               duration = excluded.duration,
               episode_number = excluded.episode_number,
               season_number = excluded.season_number,
               episode_type = excluded.episode_type,
               explicit = excluded.explicit,
               keywords = excluded.keywords,
               episode_art = excluded.episode_art,
               published_at = excluded.published_at,
               updated_at = strftime(\'%s\',\'now\')',
            [
                $id,
                trim($data['title'] ?? ''),
                $data['description'] ?? '',
                $data['show_notes'] ?? '',
                $data['audio_file'] ?? '',
                $data['duration'] ?? '00:00:00',
                (int)($data['episode_number'] ?? 0),
                (int)($data['season_number'] ?? 1),
                $data['episode_type'] ?? 'full',
                !empty($data['explicit']) ? 1 : 0,
                $data['keywords'] ?? '',
                $data['episode_art'] ?? '',
                $publishedAt,
            ]
        );
        Database::get()->flushCache();
        Feed::maybeRegeneratePodcast();
        return self::get($id) ?? [];
    }

    public static function delete(string $episodeId): void {
        if (!License::isPodcastLicensed()) {
            throw new RuntimeException('Podcast feature is locked');
        }
        $episodeId = preg_replace('/[^a-zA-Z0-9._-]/', '', $episodeId) ?? '';
        Database::get()->execute('DELETE FROM podcast_episodes WHERE episode_id = ?', [$episodeId]);
        Database::get()->flushCache();
        Feed::maybeRegeneratePodcast();
    }
}

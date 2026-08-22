<?php
/**
 * Forma – RSS / JSON Feed writers + dynamic output.
 */
class Feed {
    public static function maybeRegenerateBlog(): void {
        $blog = Database::get()->getSetting('blog');
        if (($blog['auto_regen_feed'] ?? true) === false) {
            return;
        }
        if (($blog['blog_feed_rss'] ?? true) !== false) {
            self::writeBlogRss();
        }
        if (($blog['blog_feed_json'] ?? true) !== false) {
            self::writeBlogJson();
        }
    }

    public static function maybeRegeneratePodcast(): void {
        $p = Database::get()->getSetting('podcast');
        if (($p['auto_regen_feed'] ?? true) === false) {
            return;
        }
        if (($p['podcast_feed_rss'] ?? true) !== false) {
            self::writePodcastRss();
        }
    }

    public static function writeBlogRss(): bool {
        try {
            if (!is_dir(FEEDS_DIR)) {
                mkdir(FEEDS_DIR, 0755, true);
            }
            return file_put_contents(FEEDS_DIR . '/blog.xml', self::blogRssXml()) !== false;
        } catch (Throwable $e) {
            error_log('Blog RSS error: ' . $e->getMessage());
            return false;
        }
    }

    public static function writeBlogJson(): bool {
        try {
            if (!is_dir(FEEDS_DIR)) {
                mkdir(FEEDS_DIR, 0755, true);
            }
            return file_put_contents(
                FEEDS_DIR . '/blog.json',
                json_encode(self::blogJsonFeed(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ) !== false;
        } catch (Throwable $e) {
            error_log('Blog JSON Feed error: ' . $e->getMessage());
            return false;
        }
    }

    public static function blogRssXml(): string {
        require_once ROOT_DIR . '/lib/Parsedown.php';
        $parsedown = new Parsedown();
        $config = Database::get()->getConfig();
        $site   = $config['site'] ?? [];
        $blog   = $config['blog'] ?? [];
        $siteUrl   = rtrim($site['url'] ?? '', '/');
        $siteTitle = $site['title'] ?? 'Blog';
        $siteDesc  = $site['description'] ?? '';
        $feedPosts = (int)($blog['feed_posts'] ?? 20);
        $excerptLen = (int)($blog['excerpt_length'] ?? 250);

        $posts = Database::get()->query(
            'SELECT * FROM blog_posts
              WHERE published_at IS NOT NULL AND published_at <= ?
              ORDER BY published_at DESC LIMIT ?',
            [time(), $feedPosts]
        );

        $rss  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rss .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"'
              . ' xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $rss .= "<channel>\n";
        $rss .= '<title>' . htmlspecialchars($siteTitle) . "</title>\n";
        $rss .= '<link>' . htmlspecialchars($siteUrl ?: '/') . "</link>\n";
        $rss .= '<description>' . htmlspecialchars($siteDesc) . "</description>\n";
        $rss .= '<language>' . htmlspecialchars($site['language'] ?? 'en') . "</language>\n";
        $rss .= '<lastBuildDate>' . date('r') . "</lastBuildDate>\n";
        $feedUrl = ($siteUrl ?: '') . '/feed.xml';
        if ($siteUrl) {
            $rss .= '<atom:link href="' . htmlspecialchars($feedUrl)
                  . '" rel="self" type="application/rss+xml" />' . "\n";
        }

        foreach ($posts as $post) {
            $postUrl = ($siteUrl ?: '') . '/blog/' . $post['slug'];
            $html    = $parsedown->text($post['body']);
            $excerpt = substr(strip_tags($post['body']), 0, $excerptLen);
            if (strlen($post['body']) > $excerptLen) {
                $excerpt .= '…';
            }
            $rss .= "<item>\n";
            $rss .= '  <title>' . htmlspecialchars($post['title']) . "</title>\n";
            $rss .= '  <link>' . htmlspecialchars($postUrl) . "</link>\n";
            $rss .= '  <guid isPermaLink="true">' . htmlspecialchars($postUrl) . "</guid>\n";
            $rss .= '  <pubDate>' . date('r', (int)$post['published_at']) . "</pubDate>\n";
            $desc = $post['description'] ?: $excerpt;
            $rss .= '  <description>' . htmlspecialchars($desc) . "</description>\n";
            $rss .= '  <content:encoded><![CDATA[' . $html . "]]></content:encoded>\n";
            if ($post['author']) {
                $rss .= '  <author>' . htmlspecialchars($post['author']) . "</author>\n";
            }
            foreach (json_decode($post['categories'] ?: '[]', true) ?: [] as $cat) {
                $rss .= '  <category>' . htmlspecialchars($cat) . "</category>\n";
            }
            $rss .= "</item>\n";
        }
        $rss .= "</channel>\n</rss>";
        return $rss;
    }

    public static function blogJsonFeed(): array {
        require_once ROOT_DIR . '/lib/Parsedown.php';
        $parsedown = new Parsedown();
        $config = Database::get()->getConfig();
        $site   = $config['site'] ?? [];
        $blog   = $config['blog'] ?? [];
        $siteUrl = rtrim($site['url'] ?? '', '/');
        $feedPosts = (int)($blog['feed_posts'] ?? 20);

        $posts = Database::get()->query(
            'SELECT * FROM blog_posts
              WHERE published_at IS NOT NULL AND published_at <= ?
              ORDER BY published_at DESC LIMIT ?',
            [time(), $feedPosts]
        );

        $items = [];
        foreach ($posts as $post) {
            $postUrl = ($siteUrl ?: '') . '/blog/' . $post['slug'];
            $item = [
                'id'             => $postUrl,
                'url'            => $postUrl,
                'title'          => $post['title'],
                'date_published' => date('c', (int)$post['published_at']),
                'content_html'   => $parsedown->text($post['body']),
            ];
            if ($post['description']) {
                $item['summary'] = $post['description'];
            }
            if ($post['author']) {
                $item['authors'] = [['name' => $post['author']]];
            }
            $allTags = array_merge(
                json_decode($post['categories'] ?: '[]', true) ?: [],
                json_decode($post['tags'] ?: '[]', true) ?: []
            );
            if ($allTags) {
                $item['tags'] = $allTags;
            }
            $items[] = $item;
        }

        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $site['title'] ?? 'Blog',
            'home_page_url' => $siteUrl ?: '/',
            'feed_url'      => ($siteUrl ?: '') . '/feed.json',
            'language'      => $site['language'] ?? 'en',
            'items'         => $items,
        ];
        if (!empty($site['description'])) {
            $feed['description'] = $site['description'];
        }
        return $feed;
    }

    public static function writePodcastRss(): bool {
        try {
            if (!is_dir(FEEDS_DIR)) {
                mkdir(FEEDS_DIR, 0755, true);
            }
            return file_put_contents(FEEDS_DIR . '/podcast.xml', self::podcastRssXml()) !== false;
        } catch (Throwable $e) {
            error_log('Podcast RSS error: ' . $e->getMessage());
            return false;
        }
    }

    public static function podcastRssXml(): string {
        require_once ROOT_DIR . '/lib/Parsedown.php';
        $parsedown = new Parsedown();
        $config = Database::get()->getConfig();
        $site = $config['site'] ?? [];
        $p = $config['podcast'] ?? [];
        $siteUrl = rtrim($site['url'] ?? '', '/');

        $episodes = Database::get()->query(
            'SELECT * FROM podcast_episodes
              WHERE published_at IS NOT NULL AND published_at <= ?
              ORDER BY published_at DESC',
            [time()]
        );

        $rss  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $rss .= '<rss version="2.0" xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"'
              . ' xmlns:content="http://purl.org/rss/1.0/modules/content/"'
              . ' xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
        $rss .= "<channel>\n";
        $rss .= '<title>' . htmlspecialchars($p['title'] ?? '') . "</title>\n";
        $rss .= '<link>' . htmlspecialchars($siteUrl ?: '/') . "</link>\n";
        $rss .= '<language>' . htmlspecialchars($p['language'] ?? 'en-us') . "</language>\n";
        $rss .= '<description>' . htmlspecialchars($p['description'] ?? '') . "</description>\n";
        $rss .= '<itunes:author>' . htmlspecialchars($p['author'] ?? '') . "</itunes:author>\n";
        $rss .= '<itunes:summary>' . htmlspecialchars($p['description'] ?? '') . "</itunes:summary>\n";
        $rss .= '<itunes:explicit>' . (($p['explicit'] ?? 'no') === 'yes' ? 'yes' : 'no') . "</itunes:explicit>\n";
        $rss .= "<itunes:owner>\n";
        $rss .= '  <itunes:name>' . htmlspecialchars($p['author'] ?? '') . "</itunes:name>\n";
        $rss .= '  <itunes:email>' . htmlspecialchars($p['email'] ?? '') . "</itunes:email>\n";
        $rss .= "</itunes:owner>\n";
        if (!empty($p['image'])) {
            $cover = str_starts_with($p['image'], 'http') ? $p['image'] : ($siteUrl . '/' . ltrim($p['image'], '/'));
            $rss .= '<itunes:image href="' . htmlspecialchars($cover) . '" />' . "\n";
        }
        if (!empty($p['category'])) {
            $rss .= '<itunes:category text="' . htmlspecialchars($p['category']) . '">';
            if (!empty($p['subcategory'])) {
                $rss .= '<itunes:category text="' . htmlspecialchars($p['subcategory']) . '" />';
            }
            $rss .= "</itunes:category>\n";
        }
        $rss .= '<atom:link href="' . htmlspecialchars(($siteUrl ?: '') . '/feeds/podcast.xml')
              . '" rel="self" type="application/rss+xml" />' . "\n";

        foreach ($episodes as $ep) {
            $audio = basename($ep['audio_file'] ?? '');
            $audioPath = UPLOADS_DIR . '/' . $audio;
            $audioUrl = ($siteUrl ?: '') . '/uploads/' . rawurlencode($audio);
            $fileSize = (is_file($audioPath) ? (string)filesize($audioPath) : '0');
            $mime = 'audio/mpeg';
            $ext = strtolower(pathinfo($audio, PATHINFO_EXTENSION));
            if ($ext === 'm4a') {
                $mime = 'audio/mp4';
            } elseif ($ext === 'ogg') {
                $mime = 'audio/ogg';
            } elseif ($ext === 'wav') {
                $mime = 'audio/wav';
            }
            $showNotesHtml = !empty($ep['show_notes']) ? $parsedown->text($ep['show_notes']) : '';

            $rss .= "<item>\n";
            $rss .= '  <title>' . htmlspecialchars($ep['title']) . "</title>\n";
            $rss .= '  <itunes:title>' . htmlspecialchars($ep['title']) . "</itunes:title>\n";
            $rss .= '  <description>' . htmlspecialchars($ep['description']) . "</description>\n";
            $rss .= '  <itunes:summary>' . htmlspecialchars($ep['description']) . "</itunes:summary>\n";
            if ($showNotesHtml) {
                $rss .= '  <content:encoded><![CDATA[' . $showNotesHtml . "]]></content:encoded>\n";
            }
            $rss .= '  <enclosure url="' . htmlspecialchars($audioUrl) . '" length="' . $fileSize . '" type="' . $mime . '" />' . "\n";
            $rss .= '  <guid isPermaLink="false">' . htmlspecialchars($ep['episode_id']) . "</guid>\n";
            $rss .= '  <pubDate>' . date('r', (int)$ep['published_at']) . "</pubDate>\n";
            $rss .= '  <itunes:duration>' . htmlspecialchars($ep['duration']) . "</itunes:duration>\n";
            $rss .= '  <itunes:explicit>' . ($ep['explicit'] ? 'yes' : 'no') . "</itunes:explicit>\n";
            if (!empty($ep['episode_number'])) {
                $rss .= '  <itunes:episode>' . (int)$ep['episode_number'] . "</itunes:episode>\n";
            }
            if (!empty($ep['season_number'])) {
                $rss .= '  <itunes:season>' . (int)$ep['season_number'] . "</itunes:season>\n";
            }
            if (!empty($ep['episode_type'])) {
                $rss .= '  <itunes:episodeType>' . htmlspecialchars($ep['episode_type']) . "</itunes:episodeType>\n";
            }
            if (!empty($ep['keywords'])) {
                $rss .= '  <itunes:keywords>' . htmlspecialchars($ep['keywords']) . "</itunes:keywords>\n";
            }
            $rss .= "</item>\n";
        }
        $rss .= "</channel>\n</rss>";
        return $rss;
    }
}

<?php
/**
 * Forma – SQLite database layer
 */
class Database {
    private static ?self $instance = null;
    private PDO $pdo;

    private const SCHEMA = <<<'SQL'
        CREATE TABLE IF NOT EXISTS pages (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            filename     TEXT    NOT NULL UNIQUE,
            content_type TEXT    NOT NULL DEFAULT 'html',
            slug         TEXT,
            content      TEXT    NOT NULL DEFAULT '',
            created_at   INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at   INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
        CREATE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug);

        CREATE TABLE IF NOT EXISTS blog_posts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            filename     TEXT    NOT NULL UNIQUE,
            slug         TEXT    NOT NULL DEFAULT '',
            title        TEXT    NOT NULL DEFAULT '',
            body         TEXT    NOT NULL DEFAULT '',
            description  TEXT    NOT NULL DEFAULT '',
            author       TEXT    NOT NULL DEFAULT '',
            categories   TEXT    NOT NULL DEFAULT '[]',
            tags         TEXT    NOT NULL DEFAULT '[]',
            published_at INTEGER,
            created_at   INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at   INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
        CREATE INDEX IF NOT EXISTS idx_blog_slug      ON blog_posts(slug);
        CREATE INDEX IF NOT EXISTS idx_blog_published ON blog_posts(published_at);

        CREATE TABLE IF NOT EXISTS podcast_episodes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            episode_id     TEXT    NOT NULL UNIQUE,
            title          TEXT    NOT NULL DEFAULT '',
            description    TEXT    NOT NULL DEFAULT '',
            show_notes     TEXT    NOT NULL DEFAULT '',
            audio_file     TEXT    NOT NULL DEFAULT '',
            duration       TEXT    NOT NULL DEFAULT '00:00:00',
            episode_number INTEGER NOT NULL DEFAULT 0,
            season_number  INTEGER NOT NULL DEFAULT 1,
            episode_type   TEXT    NOT NULL DEFAULT 'full',
            explicit       INTEGER NOT NULL DEFAULT 0,
            keywords       TEXT    NOT NULL DEFAULT '',
            episode_art    TEXT    NOT NULL DEFAULT '',
            published_at   INTEGER,
            created_at     INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at     INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
        CREATE INDEX IF NOT EXISTS idx_episodes_published ON podcast_episodes(published_at);

        CREATE TABLE IF NOT EXISTS snippets (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            filename    TEXT    NOT NULL UNIQUE,
            shortcode   TEXT    NOT NULL UNIQUE,
            content     TEXT    NOT NULL DEFAULT '',
            created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at  INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE TABLE IF NOT EXISTS settings (
            section TEXT PRIMARY KEY,
            value   TEXT NOT NULL DEFAULT '{}'
        );

        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            username      TEXT    NOT NULL UNIQUE,
            password_hash TEXT    NOT NULL,
            created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE TABLE IF NOT EXISTS page_cache (
            path       TEXT    PRIMARY KEY,
            content    TEXT    NOT NULL,
            expires_at INTEGER NOT NULL,
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE TABLE IF NOT EXISTS license (
            id             INTEGER PRIMARY KEY CHECK (id = 1),
            license_key    TEXT    NOT NULL DEFAULT '',
            license_type   TEXT    NOT NULL DEFAULT '',
            licensed_to    TEXT    NOT NULL DEFAULT '',
            valid_until    INTEGER,
            last_checked   INTEGER,
            status         TEXT    NOT NULL DEFAULT 'inactive'
        );
        INSERT OR IGNORE INTO license (id) VALUES (1);

        CREATE TABLE IF NOT EXISTS api_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            token_hash TEXT    NOT NULL UNIQUE,
            scopes     TEXT    NOT NULL DEFAULT '[]',
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            last_used  INTEGER,
            revoked_at INTEGER
        );

        CREATE TABLE IF NOT EXISTS api_audit (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token_id   INTEGER,
            action     TEXT    NOT NULL,
            path       TEXT    NOT NULL DEFAULT '',
            ip         TEXT    NOT NULL DEFAULT '',
            summary    TEXT    NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );

        CREATE TABLE IF NOT EXISTS login_attempts (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            ip           TEXT    NOT NULL,
            attempted_at INTEGER NOT NULL
        );

        CREATE TABLE IF NOT EXISTS redirects (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            from_path  TEXT    NOT NULL UNIQUE,
            to_url     TEXT    NOT NULL,
            status     INTEGER NOT NULL DEFAULT 301,
            enabled    INTEGER NOT NULL DEFAULT 1,
            note       TEXT    NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
    SQL;

    private const DEFAULT_SETTINGS = [
        'site' => [
            'title'          => 'My Website',
            'description'    => '',
            'url'            => '',
            'timezone'       => 'UTC',
            'language'       => 'en',
            'default_author' => 'Admin',
        ],
        'blog' => [
            'posts_per_page'  => 20,
            'excerpt_length'  => 250,
            'feed_posts'      => 20,
            'default_author'  => '',
            'blog_feed_rss'   => true,
            'blog_feed_json'  => true,
            'auto_regen_feed' => true,
        ],
        'podcast' => [
            'title'       => '',
            'description' => '',
            'author'      => '',
            'email'       => '',
            'category'    => '',
            'subcategory' => '',
            'explicit'    => 'no',
            'language'    => 'en-us',
            'image'       => '',
            'feed_url'    => '',
            'podcast_feed_rss' => true,
            'auto_regen_feed'  => true,
        ],
        'security' => [
            'session_lifetime'     => 3600,
            'allowed_upload_types' => [
                'jpg','jpeg','png','gif','svg','webp','ico',
                'pdf','doc','docx','txt','rtf','md',
                'mp3','m4a','wav','ogg',
                'mp4','webm','mov','avi',
                'css','js','json','xml','csv','zip',
            ],
            'max_upload_size' => 52428800,
            'agent_https_only' => true,
        ],
        'cache' => [
            'enabled'         => false,
            'ttl'             => 3600,
            'excluded_paths'  => ['/admin', '/api'],
            'static_fallback' => true,
        ],
        'server' => [
            'rewrite_base'          => '/',
            'htaccess_auto_created' => false,
            'setup_complete'        => false,
        ],
        'seo' => [
            'robots_auto'              => true,
            'robots_manual'            => '',
            'robots_index'             => true,
            'robots_follow'            => true,
            'robots_extra'             => '',
            'sitemap_auto'             => true,
            'sitemap_manual'           => '',
            'sitemap_enabled'          => true,
            'sitemap_include_pages'    => true,
            'sitemap_include_posts'    => true,
            'sitemap_include_podcast'  => true,
            'sitemap_include_images'   => true,
            'llms_auto'                => true,
            'llms_manual'              => '',
            'llms_enabled'             => true,
            'title_separator'          => ' — ',
            'title_suffix'             => true,
            'favicon'                  => '',
            'apple_touch_icon'         => '',
            'default_og_image'         => '',
            'twitter_site'             => '',
            'twitter_card'             => 'summary_large_image',
            'google_site_verification' => '',
            'bing_site_verification'   => '',
            'google_analytics'         => '',
            'json_ld_website'          => true,
            'schema_type'              => 'person',
            'organization_name'        => '',
            'organization_logo'        => '',
            'same_as'                  => '',
            'schema_email'             => '',
            'schema_phone'             => '',
            'schema_address'           => '',
            'schema_city'              => '',
            'schema_region'            => '',
            'schema_postal'             => '',
            'schema_country'           => 'US',
            'schema_hours'             => '',
            'schema_price_range'       => '',
            'place_id'                 => '',
            'gbp_url'                  => '',
            'review_url'               => '',
            'maps_embed_url'           => '',
            'noindex_paths'            => '/admin,/api,/old',
        ],
    ];

    private function __construct() {
        $dir = dirname(DB_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
        $this->initSchema();
    }

    public static function get(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): PDO {
        return $this->pdo;
    }

    private function initSchema(): void {
        $this->pdo->exec(self::SCHEMA);
        $this->migrateColumns();
        foreach (self::DEFAULT_SETTINGS as $section => $defaults) {
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO settings (section, value) VALUES (?, ?)'
            )->execute([$section, json_encode($defaults)]);
        }
        $this->seedDefaultAdmin();
        $this->seedSystemContent();
    }

    private function migrateColumns(): void {
        $cols = $this->pdo->query('PRAGMA table_info(blog_posts)')->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('seo_json', $names, true)) {
            $this->pdo->exec("ALTER TABLE blog_posts ADD COLUMN seo_json TEXT NOT NULL DEFAULT '{}'");
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS redirects (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                from_path  TEXT    NOT NULL UNIQUE,
                to_url     TEXT    NOT NULL,
                status     INTEGER NOT NULL DEFAULT 301,
                enabled    INTEGER NOT NULL DEFAULT 1,
                note       TEXT    NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL DEFAULT (strftime(\'%s\',\'now\')),
                updated_at INTEGER NOT NULL DEFAULT (strftime(\'%s\',\'now\'))
            )'
        );
    }

    private function seedDefaultAdmin(): void {
        if ($this->queryOne('SELECT 1 FROM users LIMIT 1')) {
            return;
        }
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $this->execute(
            'INSERT INTO users (username, password_hash) VALUES (?, ?)',
            ['admin', $hash]
        );
    }

    private function seedSystemContent(): void {
        if (!class_exists('Render', false)) {
            require_once ROOT_DIR . '/lib/Render.php';
        }
        foreach ([
            '_404' => [404, '/404', 'Page not found'],
            '_403' => [403, '/403', 'Forbidden'],
            '_500' => [500, '/500', 'Server error'],
        ] as $filename => [$code, $slug, $title]) {
            if ($this->queryOne('SELECT 1 FROM pages WHERE filename = ?', [$filename])) {
                continue;
            }
            $copy = Render::errorPageCopy($code);
            $meta = "<!--META\nslug: {$slug}\ntitle: {$title}\nseo_title: {$title} | Forma\nseo_description: {$copy['lede']}\nrobots: noindex,nofollow\n-->\n";
            $this->execute(
                'INSERT INTO pages (filename, content_type, slug, content) VALUES (?, ?, ?, ?)',
                [$filename, 'html', $slug, $meta . Render::staticError($code)]
            );
        }

        if (!$this->queryOne("SELECT 1 FROM pages WHERE filename = 'home'")) {
            $home = <<<'HTML'
<!--META
slug: /
title: Home
-->
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>My Site — Powered by Forma</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,sans-serif;color:#1a1a1a;line-height:1.6}
.hero{min-height:60vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:3rem 1.5rem;background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);color:#f8fafc}
.hero h1{font-size:clamp(2rem,5vw,3.5rem);font-weight:800;margin-bottom:.75rem}
.hero p{font-size:1.15rem;color:#94a3b8;max-width:36rem;margin:0 auto}
.content{max-width:42rem;margin:3rem auto;padding:0 1.5rem}
footer{text-align:center;padding:2rem 1rem;font-size:.85rem;color:#94a3b8}
</style>
</head>
<body>
<section class="hero"><div>
  <h1>Welcome to Forma</h1>
  <p>Edit this page in Admin → Pages → home. One SQLite file. Markdown-friendly. Agent-ready.</p>
</div></section>
<main class="content">
  <h2>Getting started</h2>
  <p>Open <code>/admin</code> (default login <code>admin</code> / <code>admin</code>). Change the password immediately.</p>
</main>
<footer>&copy; 2026 · Powered by Forma</footer>
</body>
</html>
HTML;
            $this->execute(
                'INSERT INTO pages (filename, content_type, slug, content) VALUES (?, ?, ?, ?)',
                ['home', 'html', '/', $home]
            );
        }

        $tplDir = dirname(__DIR__) . '/templates';
        $blogArchive = is_file($tplDir . '/blog-archive.twig')
            ? (string)file_get_contents($tplDir . '/blog-archive.twig')
            : '<!DOCTYPE html><html><body><h1>Blog</h1>{% for post in posts %}<h2><a href="/blog/{{ post.slug }}">{{ post.title }}</a></h2>{% endfor %}</body></html>';
        $blogSingle = is_file($tplDir . '/blog-single.twig')
            ? (string)file_get_contents($tplDir . '/blog-single.twig')
            : '<!DOCTYPE html><html><body><h1>{{ post.title }}</h1><div>{{ post.content|raw }}</div><p><a href="/blog">Blog</a></p></body></html>';

        $podcastArchive = <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ podcast.title }} — {{ site.title }}</title>
<style>body{font-family:system-ui,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1.5rem;line-height:1.6}</style>
</head>
<body>
<h1>{{ podcast.title }}</h1>
<p>{{ podcast.description }}</p>
{% for episode in episodes %}
  <article style="margin:1.25rem 0">
    <h2><a href="/podcast/{{ episode.id }}">{{ episode.title }}</a></h2>
    <p>{{ episode.publish_date }}</p>
  </article>
{% else %}
  <p>No episodes yet.</p>
{% endfor %}
</body>
</html>
TWIG;
        $podcastSingle = <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ episode.title }} — {{ podcast.title }}</title>
<style>body{font-family:system-ui,sans-serif;max-width:42rem;margin:2rem auto;padding:0 1.5rem;line-height:1.6}</style>
</head>
<body>
<article>
  <h1>{{ episode.title }}</h1>
  <p>{{ episode.publish_date }}</p>
  <p>{{ episode.description }}</p>
  {% if episode.audio_url %}<audio controls src="{{ episode.audio_url }}" style="width:100%"></audio>{% endif %}
  {% if episode.show_notes_html %}<div>{{ episode.show_notes_html|raw }}</div>{% endif %}
</article>
<p><a href="/podcast">← Episodes</a> · <a href="{{ podcast_feed }}">RSS</a></p>
</body>
</html>
TWIG;

        foreach ([
            'blog-archive' => $blogArchive,
            'blog-single' => $blogSingle,
            'podcast-archive' => $podcastArchive,
            'podcast-single' => $podcastSingle,
            'search-results' => Render::defaultSearchResultsTemplate(),
            'search-page' => Render::defaultSearchPageTemplate(),
        ] as $fn => $content) {
            if (!$this->queryOne('SELECT 1 FROM pages WHERE filename = ?', [$fn])) {
                $this->execute(
                    'INSERT INTO pages (filename, content_type, slug, content) VALUES (?, ?, ?, ?)',
                    [$fn, 'html', null, $content]
                );
            }
        }

        if (!$this->queryOne("SELECT 1 FROM snippets WHERE filename = 'search'")) {
            $searchSnippet = <<<'HTML'
[[search-ui]]
<form class="fx-search-box" role="search" action="/search" method="get"
      hx-get="/search" hx-target="#fx-search-results" hx-push-url="true"
      hx-trigger="submit, keyup changed delay:280ms from:input[name='q']">
  <label class="fx-search-label" for="fx-search-q">Search</label>
  <div class="fx-search-row">
    <input id="fx-search-q" type="search" name="q" value="{{ query|default('') }}" placeholder="Search pages, posts, episodes…" autocomplete="off" aria-label="Search" enterkeyhint="search">
    <button type="submit">Search</button>
  </div>
</form>
<div id="fx-search-results">{{ results_html|default('')|raw }}</div>
HTML;
            $this->execute(
                'INSERT INTO snippets (filename, shortcode, content) VALUES (?, ?, ?)',
                ['search', 'search', $searchSnippet]
            );
        }

        if (!$this->queryOne("SELECT 1 FROM snippets WHERE filename = 'error-ui'")) {
            if (!class_exists('Render', false)) {
                require_once ROOT_DIR . '/lib/Render.php';
            }
            $this->execute(
                'INSERT INTO snippets (filename, shortcode, content) VALUES (?, ?, ?)',
                ['error-ui', 'error-ui', Render::defaultErrorUiSnippet()]
            );
        }

        if (!$this->queryOne("SELECT 1 FROM snippets WHERE filename = 'search-ui'")) {
            if (!class_exists('Render', false)) {
                require_once ROOT_DIR . '/lib/Render.php';
            }
            $this->execute(
                'INSERT INTO snippets (filename, shortcode, content) VALUES (?, ?, ?)',
                ['search-ui', 'search-ui', Render::defaultSearchUiSnippet()]
            );
        }

        if (!$this->queryOne('SELECT 1 FROM blog_posts WHERE filename = ?', ['welcome'])) {
            $body = "## Welcome to Forma\n\nThis is a **sample post**. Edit or delete it in **Admin → Blog**.\n";
            $this->execute(
                'INSERT INTO blog_posts (filename, slug, title, body, description, author, categories, tags, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                ['welcome', 'welcome', 'Welcome to Forma', $body, 'Sample markdown post', 'Admin', '[]', '[]', time()]
            );
        }

        if (!$this->queryOne('SELECT 1 FROM snippets WHERE shortcode = ?', ['sample'])) {
            $this->execute(
                'INSERT INTO snippets (filename, shortcode, content) VALUES (?, ?, ?)',
                ['sample-welcome', 'sample', '<p class="formax-sample">This is a <strong>sample snippet</strong>. Use <code>[[sample]]</code> in your pages.</p>']
            );
        }
    }

    public function query(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function queryOne(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    public function execute(string $sql, array $params = []): void {
        $this->pdo->prepare($sql)->execute($params);
    }

    public function lastInsertId(): int {
        return (int) $this->pdo->lastInsertId();
    }

    public function flushCache(): void {
        $this->execute('DELETE FROM page_cache');
    }

    public function getConfig(): array {
        $config = [];
        foreach ($this->query('SELECT section, value FROM settings') as $row) {
            $config[$row['section']] = json_decode($row['value'], true) ?? [];
        }
        return $config;
    }

    public function getSetting(string $section): array {
        $row = $this->queryOne('SELECT value FROM settings WHERE section = ?', [$section]);
        $stored = json_decode($row['value'] ?? '{}', true) ?? [];
        $defaults = self::DEFAULT_SETTINGS[$section] ?? [];
        return array_merge($defaults, is_array($stored) ? $stored : []);
    }

    public function saveSetting(string $section, array $value): void {
        $this->execute(
            'INSERT INTO settings (section, value) VALUES (?, ?)
             ON CONFLICT(section) DO UPDATE SET value = excluded.value',
            [$section, json_encode($value)]
        );
    }
}

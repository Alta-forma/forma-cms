<?php
/**
 * Forma – Shared public/admin render pipeline.
 */
class Render {
    private static ?Parsedown $parsedown = null;
    private static $twig = null;
    private static ?array $siteContextCache = null;
    private static ?array $snippetMap = null;

    public static function parsedown(): Parsedown {
        if (!self::$parsedown) {
            require_once ROOT_DIR . '/lib/Parsedown.php';
            self::$parsedown = new Parsedown();
        }
        return self::$parsedown;
    }

    public static function twig() {
        if (!self::$twig) {
            $init = ROOT_DIR . '/lib/Twig/init.php';
            if (!is_file($init)) {
                throw new RuntimeException(
                    'Twig missing: upload Forma lib/Twig/ to ' . $init
                    . ' (check you did not nest lib/lib/Twig by dragging the folder into itself).'
                );
            }
            require_once $init;
            self::$twig = formax_twig();
            self::$twig->addFilter(new \Twig\TwigFilter('markdown', fn($t) => self::parsedown()->text($t)));
        }
        return self::$twig;
    }

    public static function siteContext(): array {
        if (self::$siteContextCache !== null) {
            return self::$siteContextCache;
        }
        $config = Database::get()->getConfig();
        // Placeholder first so nested shortcode/Twig work cannot re-enter forever.
        self::$siteContextCache = [
            'site' => [
                'title'       => $config['site']['title'] ?? 'My Site',
                'description' => $config['site']['description'] ?? '',
                'url'         => $config['site']['url'] ?? '',
            ],
            'config' => $config,
            'posts' => [],
            'featured_posts' => [],
        ];
        $posts = self::publicPostCards(BlogRepo::list(true));
        self::$siteContextCache['posts'] = $posts;
        self::$siteContextCache['featured_posts'] = array_slice($posts, 0, 3);
        return self::$siteContextCache;
    }

    /** Card-shaped post rows for Twig (archive, home featured, etc.). */
    public static function publicPostCards(array $rows): array {
        return array_map(static function ($r) {
            $seo = json_decode($r['seo_json'] ?? '{}', true) ?: [];
            $image = trim((string)($seo['featured_image'] ?? ''));
            if ($image === '') {
                $image = trim((string)($seo['og_image'] ?? ''));
            }
            $cats = json_decode($r['categories'] ?: '[]', true) ?: [];
            $tags = json_decode($r['tags'] ?: '[]', true) ?: [];
            return [
                'filename'    => $r['filename'],
                'slug'        => $r['slug'],
                'title'       => $r['title'],
                'description' => self::neutralizeShortcodes((string)($r['description'] ?? '')),
                'author'      => $r['author'],
                'date'        => $r['published_at'] ? date('Y-m-d', (int)$r['published_at']) : '',
                'date_label'  => $r['published_at'] ? date('M j, Y', (int)$r['published_at']) : '',
                'categories'  => $cats,
                'tags'        => $tags,
                'image'       => $image,
                'featured_image' => $image,
                'seo'         => $seo,
            ];
        }, $rows);
    }

    /**
     * Expand [[shortcode]] snippets.
     *
     * Literal output (does not expand):
     *   - [[name]] inside <pre>, <code>, <textarea>, <script>, <style>
     *   - [[!name]]  →  [[name]]
     *   - \[[name]]  →  [[name]]
     *
     * Extra Twig context (query, results_html, …) is available to snippets
     * that contain {{ }} / {% %} so the same [[search]] box can prefill.
     *
     * Descriptions, titles, and other plain-text fields should be passed
     * through neutralizeShortcodes() so [[search]] never becomes a widget.
     */
    public static function neutralizeShortcodes(string $text): string {
        return preg_replace('/\[\[(!?)([a-zA-Z0-9_-]+)\]\]/', '[[!$2]]', $text) ?? $text;
    }

    public static function expandShortcodes(string $content, array $extra = []): string {
        if (!str_contains($content, '[[')) {
            return $content;
        }
        if (self::$snippetMap === null) {
            self::$snippetMap = [];
            foreach (Database::get()->query('SELECT shortcode, content FROM snippets') as $row) {
                self::$snippetMap[$row['shortcode']] = $row['content'];
            }
        }
        $map = self::$snippetMap;
        $config = Database::get()->getConfig();
        $protected = [];
        $stash = static function (string $raw) use (&$protected): string {
            $key = "\x00FXSC" . count($protected) . "\x00";
            $protected[$key] = $raw;
            return $key;
        };
        $protect = static function (string $html) use ($stash): string {
            $html = (string)preg_replace_callback(
                '/<(pre|code|textarea|script|style|title)\b[^>]*>.*?<\/\1>/is',
                static fn(array $m): string => $stash($m[0]),
                $html
            );
            $html = (string)preg_replace_callback(
                '/<meta\b[^>]*>/i',
                static fn(array $m): string => $stash($m[0]),
                $html
            );
            return (string)preg_replace_callback(
                '/\s[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*=\s*("[^"]*"|\'[^\']*\')/',
                static fn(array $m): string => $stash($m[0]),
                $html
            );
        };

        $expandPass = function (string $html) use ($map, $config, $extra, $stash): string {
            return (string)preg_replace_callback(
                '/\\\\\[\[([a-zA-Z0-9_-]+)\]\]|\[\[(!?)([a-zA-Z0-9_-]+)\]\]/',
                function (array $m) use ($map, $config, $extra, $stash): string {
                    $escaped = ($m[0][0] === '\\') || (($m[2] ?? '') === '!');
                    $code = ($m[1] ?? '') !== '' ? $m[1] : ($m[3] ?? '');
                    if ($escaped) {
                        return $stash('[[' . $code . ']]');
                    }
                    if (!isset($map[$code])) {
                        return $m[0];
                    }
                    $snippet = $map[$code];
                    if (str_contains($snippet, '{%') || str_contains($snippet, '{{')) {
                        try {
                            $snippet = self::twig()->createTemplate($snippet)->render(array_merge(
                                self::siteContext(),
                                $extra,
                                ['config' => $config]
                            ));
                        } catch (Exception $e) {
                            error_log('Shortcode Twig error [' . $code . ']: ' . $e->getMessage());
                        }
                    }
                    return $snippet;
                },
                $html
            );
        };

        // Protect code-like regions, expand (nested snippets up to 4 passes), then restore.
        $content = $protect($content);
        for ($i = 0; $i < 4 && str_contains($content, '[['); $i++) {
            $content = $protect($expandPass($content));
        }
        if ($protected) {
            $content = str_replace(array_keys($protected), array_values($protected), $content);
        }
        return $content;
    }

    public static function forgetSnippetMap(): void {
        self::$snippetMap = null;
    }

    public static function forgetSiteContext(): void {
        self::$siteContextCache = null;
    }

    public static function injectGenerator(string $html): string {
        if (stripos($html, 'name="generator"') !== false) {
            return $html;
        }
        $meta = '<meta name="generator" content="Forma">';
        if (preg_match('/<head[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . $meta . substr($html, $pos);
        }
        return $meta . $html;
    }

    public static function renderTwig(string $template, array $context): string {
        return self::twig()->createTemplate($template)->render($context);
    }

    public static function renderPageRow(array $page): string {
        $content = PageRepo::stripMeta($page['content']);
        if (($page['content_type'] ?? 'html') === 'md') {
            if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $m)) {
                $content = $m[2];
            }
            $content = self::parsedown()->text($content);
        }

        $isFullDoc = (bool)preg_match('/<(!DOCTYPE|html)[\s>]/i', $content);
        if ($isFullDoc) {
            // Only run Twig when the page looks like a real Twig template (tags with spaces/pipes),
            // not when raw HTML/JS happens to contain "{{" (common in frontend code).
            if (preg_match('/\{%\s*[a-z]|{{\s*[a-z_][a-z0-9_]*\s*[}|.]/i', $content)) {
                try {
                    $content = self::renderTwig($content, self::siteContext());
                } catch (Throwable $e) {
                    error_log('Twig render error: ' . $e->getMessage());
                }
            }
            $html = self::injectGenerator(self::expandShortcodes($content));
            return Seo::applyToHtml($html, Seo::forPage($page));
        }

        $ctx = self::siteContext();
        $title = htmlspecialchars($ctx['site']['title']);
        $desc  = htmlspecialchars($ctx['site']['description']);
        $html = <<<HTML
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<meta name="description" content="{$desc}">
<style>body{font-family:system-ui,sans-serif;line-height:1.6;max-width:800px;margin:0 auto;padding:2rem}</style>
</head><body>{$content}</body></html>
HTML;
        $html = self::injectGenerator(self::expandShortcodes($html));
        return Seo::applyToHtml($html, Seo::forPage($page));
    }

    public static function renderBlogArchive(): string {
        $posts = self::publicPostCards(BlogRepo::list(true));
        $tpl = Database::get()->queryOne("SELECT content FROM pages WHERE filename = 'blog-archive'");
        if (!$tpl) {
            throw new RuntimeException('blog-archive template missing');
        }
        $html = self::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(self::siteContext(), [
            'posts' => $posts,
        ]));
        $html = self::injectGenerator(self::expandShortcodes($html));
        return Seo::applyToHtml($html, Seo::forBlogArchive());
    }

    public static function renderBlogPost(array $row): string {
        $post = BlogRepo::toPublicPost($row, self::parsedown());
        $seo = $post['seo'] ?? [];
        $image = trim((string)($seo['featured_image'] ?? ''));
        if ($image === '') {
            $image = trim((string)($seo['og_image'] ?? ''));
        }
        $post['image'] = $image;
        $post['featured_image'] = $image;
        $post['description'] = self::neutralizeShortcodes((string)($post['description'] ?? ''));
        $post['date_label'] = !empty($row['published_at'])
            ? date('M j, Y', (int)$row['published_at'])
            : ($post['date'] ?? '');
        $tpl = Database::get()->queryOne("SELECT content FROM pages WHERE filename = 'blog-single'");
        if (!$tpl) {
            throw new RuntimeException('blog-single template missing');
        }
        $html = self::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(self::siteContext(), [
            'post' => $post,
        ]));
        $html = self::injectGenerator(self::expandShortcodes($html));
        return Seo::applyToHtml($html, Seo::forPost($row));
    }

    // ---- Podcast (public archive + episode pages) ------------------------

    public static function renderPodcastArchive(): string {
        $config = Database::get()->getConfig();
        $podcastConfig = $config['podcast'] ?? [];
        $podcastCtx = [
            'title'       => $podcastConfig['title'] ?: ($config['site']['title'] ?? 'Podcast'),
            'description' => $podcastConfig['description'] ?? '',
            'cover_art'   => $podcastConfig['image'] ?? '',
        ];
        $episodes = [];
        foreach (PodcastRepo::list() as $row) {
            if (empty($row['published_at']) || (int)$row['published_at'] > time()) {
                continue;
            }
            $ep = $row;
            $ep['id'] = $row['episode_id'];
            $ep['publish_date'] = date('Y-m-d', (int)$row['published_at']);
            $episodes[] = $ep;
        }
        $tpl = PageRepo::get('podcast-archive');
        if (!$tpl) {
            self::sendError(404);
        }
        $html = self::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(self::siteContext(), [
            'episodes' => $episodes,
            'podcast'  => $podcastCtx,
        ]));
        if (!License::isPodcastLicensed()) {
            $html .= '<div style="text-align:center;padding:1rem;opacity:.55;font-size:.8rem;">Powered by Forma Podcast</div>';
        }
        $html = self::injectGenerator(self::expandShortcodes($html));
        return Seo::applyToHtml($html, Seo::forSimple(
            '/podcast',
            $podcastCtx['title'],
            $podcastCtx['description'],
            $podcastCtx['cover_art'] ?? ''
        ));
    }

    public static function renderPodcastEpisode(array $row): string {
        $config = Database::get()->getConfig();
        $podcastConfig = $config['podcast'] ?? [];
        $podcastCtx = [
            'title'       => $podcastConfig['title'] ?: ($config['site']['title'] ?? 'Podcast'),
            'description' => $podcastConfig['description'] ?? '',
            'cover_art'   => $podcastConfig['image'] ?? '',
        ];
        $episode = $row;
        $episode['id'] = $row['episode_id'];
        $episode['publish_date'] = $row['published_at'] ? date('Y-m-d', (int)$row['published_at']) : '';
        $episode['audio_url'] = forma_uploads_web_prefix() . basename($row['audio_file']);
        if (!empty($row['show_notes'])) {
            $episode['show_notes_html'] = self::parsedown()->text($row['show_notes']);
        }
        $tpl = PageRepo::get('podcast-single');
        if (!$tpl) {
            self::sendError(404);
        }
        $html = self::renderTwig(PageRepo::stripMeta($tpl['content']), array_merge(self::siteContext(), [
            'episode'      => $episode,
            'podcast'      => $podcastCtx,
            'podcast_feed' => '/feeds/podcast.xml',
        ]));
        if (!License::isPodcastLicensed()) {
            $html .= '<div style="text-align:center;padding:1rem;opacity:.55;font-size:.8rem;">Powered by Forma Podcast</div>';
        }
        $html = self::injectGenerator(self::expandShortcodes($html));
        return Seo::applyToHtml($html, Seo::forSimple(
            '/podcast/' . $row['episode_id'],
            $row['title'] ?: $row['episode_id'],
            $row['description'] ?? '',
            ($row['episode_art'] ?: ($podcastCtx['cover_art'] ?? ''))
        ));
    }

    // ---- Search ------------------------------------------------------------

    public static function renderSearchResultsFragment(array $results, string $query): string {
        $tpl = Database::get()->queryOne("SELECT content FROM pages WHERE filename = 'search-results'");
        $content = $tpl ? PageRepo::stripMeta($tpl['content']) : self::defaultSearchResultsTemplate();
        $html = self::renderTwig($content, array_merge(self::siteContext(), [
            'results'      => $results,
            'query'        => $query,
            'result_count' => count($results),
        ]));
        return self::expandShortcodes($html, [
            'results'      => $results,
            'query'        => $query,
            'result_count' => count($results),
        ]);
    }

    public static function renderSearchPage(array $results, string $query): string {
        $fragment = self::renderSearchResultsFragment($results, $query);
        $tpl = Database::get()->queryOne("SELECT content FROM pages WHERE filename = 'search-page'");
        $content = $tpl ? PageRepo::stripMeta($tpl['content']) : self::defaultSearchPageTemplate();
        $ctx = [
            'results_html' => $fragment,
            'query'        => $query,
            'result_count' => count($results),
        ];
        $html = self::renderTwig($content, array_merge(self::siteContext(), $ctx));
        $html = self::injectGenerator(self::expandShortcodes($html, $ctx));
        $doc = Seo::forSimple('/search', 'Search' . ($query !== '' ? ' — ' . $query : ''));
        $doc['robots'] = 'noindex,follow';
        return Seo::applyToHtml($html, $doc);
    }

    public static function defaultSearchUiSnippet(): string {
        return <<<'HTML'
<script src="https://unpkg.com/htmx.org@1.9.12" defer></script>
<style id="forma-search-ui">
.forma-search{background:
  radial-gradient(900px 420px at 12% -10%,rgba(252,190,52,.16),transparent 58%),
  radial-gradient(700px 380px at 110% 0%,rgba(252,190,52,.08),transparent 50%),
  var(--bg,#0a0a0b)}
.search-shell{padding:calc(var(--nav-h,3.6rem) + 3.5rem) 0 2rem}
.search-kicker{margin:0 0 .55rem;color:var(--gold,#fcbe34);font-family:var(--font-brand,"Chakra Petch",system-ui,sans-serif);font-size:.78rem;font-weight:600;letter-spacing:.18em;text-transform:uppercase}
.search-shell h1{margin:0 0 .7rem;font-family:var(--font-brand,"Chakra Petch",system-ui,sans-serif);font-size:clamp(2.4rem,7vw,4.4rem);line-height:.95;letter-spacing:-.03em}
.search-lede{margin:0 0 1.8rem;max-width:36rem;color:var(--muted,rgba(245,245,247,.62));font-size:1.08rem}
.fx-search-box{margin:0 0 2rem}
.fx-search-label{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
.fx-search-row{display:flex;gap:.75rem;align-items:stretch}
.fx-search-box input[type="search"]{
  flex:1;min-height:4.15rem;padding:0 1.35rem;border-radius:1.15rem;
  border:1px solid var(--stroke-gold,rgba(252,190,52,.35));
  background:rgba(255,255,255,.04);color:var(--text,#f5f5f7);
  font:600 1.2rem/1.2 var(--font,-apple-system,BlinkMacSystemFont,system-ui,sans-serif);
  box-shadow:0 0 0 6px rgba(252,190,52,.05), inset 0 1px 0 rgba(255,255,255,.04);
  outline:none;transition:border-color .2s,box-shadow .2s,background .2s
}
.fx-search-box input[type="search"]::placeholder{color:rgba(245,245,247,.38);font-weight:500}
.fx-search-box input[type="search"]:focus{
  border-color:var(--gold,#fcbe34);
  background:rgba(252,190,52,.06);
  box-shadow:0 0 0 6px rgba(252,190,52,.12)
}
.fx-search-box button{
  min-height:4.15rem;padding:0 1.45rem;border:0;border-radius:1.15rem;cursor:pointer;
  background:linear-gradient(180deg,#ffd060,#fcbe34 55%,#e6a912);
  color:#14110a;font:700 1rem/1 var(--font-brand,"Chakra Petch",system-ui,sans-serif);
  letter-spacing:.04em
}
.fx-search-box button:hover{filter:brightness(1.06)}
.fx-search-results{list-style:none;margin:0;padding:0;display:grid;gap:.85rem}
.fx-search-result a{
  display:block;padding:1.15rem 1.25rem 1.2rem;border-radius:1.15rem;
  border:1px solid var(--stroke,rgba(255,255,255,.1));
  background:linear-gradient(180deg,rgba(255,255,255,.045),rgba(255,255,255,.02));
  color:inherit;text-decoration:none;transition:border-color .2s,transform .2s,background .2s
}
.fx-search-result a:hover{border-color:var(--stroke-gold,rgba(252,190,52,.35));background:rgba(252,190,52,.06);transform:translateY(-1px);color:inherit}
.fx-search-type{display:inline-block;margin:0 .55rem .35rem 0;padding:.18rem .5rem;border-radius:999px;border:1px solid var(--stroke-gold,rgba(252,190,52,.35));color:var(--gold,#fcbe34);font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
.fx-search-title{display:block;margin:.15rem 0 .25rem;color:#fff;font-family:var(--font-brand,"Chakra Petch",system-ui,sans-serif);font-size:1.28rem;font-weight:700;letter-spacing:-.02em}
.fx-search-date{color:var(--muted,rgba(245,245,247,.62));font-size:.82rem}
.fx-search-excerpt{margin:.4rem 0 0;color:rgba(245,245,247,.72);font-size:.95rem}
.fx-search-excerpt mark{background:rgba(252,190,52,.28);color:#fff;padding:0 .15em;border-radius:.2em}
.fx-search-empty{padding:1.4rem 1.2rem;border:1px dashed var(--stroke,rgba(255,255,255,.1));border-radius:1.1rem;color:var(--muted,rgba(245,245,247,.62))}
@media(max-width:640px){
  .fx-search-row{flex-direction:column}
  .fx-search-box input[type="search"],.fx-search-box button{min-height:3.5rem;width:100%}
}
</style>
HTML;
    }

    public static function defaultSearchResultsTemplate(): string {
        return <<<'TWIG'
{% if results %}
<ul class="fx-search-results">
  {% for r in results %}
    <li class="fx-search-result">
      <a href="{{ r.url }}">
        <span class="fx-search-type">{{ r.type }}</span>
        <strong class="fx-search-title">{{ r.title }}</strong>
        {% if r.date_label %}<span class="fx-search-date">{{ r.date_label }}</span>{% endif %}
        {% if r.excerpt %}<p class="fx-search-excerpt">{{ r.excerpt|raw }}</p>{% endif %}
      </a>
    </li>
  {% endfor %}
</ul>
{% elseif query %}
<p class="fx-search-empty">No results for &ldquo;{{ query }}&rdquo;. Try another word.</p>
{% else %}
<p class="fx-search-empty">Type a word. Pages, posts, and episodes show up here.</p>
{% endif %}
TWIG;
    }

    public static function defaultSearchPageTemplate(): string {
        return <<<'TWIG'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search — {{ site.title }}</title>
[[site-head]]
</head>
<body class="forma-chrome forma-search">
  [[site-header]]
  <main class="search-shell">
    <div class="forma-wrap">
      <p class="search-kicker">Find it</p>
      <h1>Search {{ site.title }}</h1>
      <p class="search-lede">{% if query %}{{ result_count }} result{% if result_count != 1 %}s{% endif %} for “{{ query }}”{% else %}Pages, posts, and episodes — one box.{% endif %}</p>
      [[search]]
    </div>
  </main>
  [[site-footer]]
</body>
</html>
TWIG;
    }

    public static function staticError(int $code): string {
        $titles = [403 => 'Forbidden', 404 => 'Not Found', 500 => 'Server Error'];
        $t = $titles[$code] ?? 'Error';
        return '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . htmlspecialchars($t) . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:4rem auto;padding:0 1rem;line-height:1.5}</style></head><body>'
            . '<h1>' . htmlspecialchars($t) . '</h1><p>Something went wrong. Please try again later.</p></body></html>';
    }

    public static function sendError(int $code): void {
        $map = [403 => '_403', 404 => '_404', 500 => '_500'];
        $fn = $map[$code] ?? '_404';
        $page = PageRepo::get($fn);
        http_response_code($code);
        if ($page) {
            echo self::renderPageRow($page);
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo self::staticError($code);
        exit;
    }
}

<?php
/**
 * Forma – Shared public/admin render pipeline.
 */
class Render {
    private static ?Parsedown $parsedown = null;
    private static $twig = null;
    private static ?array $siteContextCache = null;

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
                'description' => $r['description'],
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

    public static function expandShortcodes(string $content): string {
        // Fast path: no shortcodes → never touch Twig (keeps public pages up if Twig upload is broken)
        if (!str_contains($content, '[[')) {
            return $content;
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (Database::get()->query('SELECT shortcode, content FROM snippets') as $row) {
                $map[$row['shortcode']] = $row['content'];
            }
        }
        $config = Database::get()->getConfig();
        return (string)preg_replace_callback('/\[\[(.*?)\]\]/', function ($m) use ($map, $config) {
            $code = trim($m[1]);
            if (!isset($map[$code])) {
                return $m[0];
            }
            $snippet = $map[$code];
            if (str_contains($snippet, '{%') || str_contains($snippet, '{{')) {
                try {
                    $snippet = self::twig()->createTemplate($snippet)->render(array_merge(
                        self::siteContext(),
                        ['config' => $config]
                    ));
                } catch (Exception $e) {
                    error_log('Shortcode Twig error [' . $code . ']: ' . $e->getMessage());
                }
            }
            return $snippet;
        }, $content);
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

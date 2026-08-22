<?php
/**
 * Forma SEO — sitewide settings, per-document meta, robots.txt, sitemap.xml, health.
 */
class Seo {
    public const PAGE_META_KEYS = [
        'seo_title', 'seo_description', 'og_title', 'og_description',
        'og_image', 'featured_image', 'canonical', 'robots', 'twitter_card', 'schema_type',
    ];

    public static function defaults(): array {
        return [
            'robots_auto'             => true,
            'robots_manual'           => '',
            'robots_index'            => true,
            'robots_follow'           => true,
            'robots_extra'            => '',
            'sitemap_auto'            => true,
            'sitemap_manual'          => '',
            'sitemap_enabled'         => true,
            'sitemap_include_pages'   => true,
            'sitemap_include_posts'   => true,
            'sitemap_include_podcast' => true,
            'sitemap_include_images'  => true,
            'title_separator'         => ' — ',
            'title_suffix'            => true,
            'favicon'                 => '',
            'apple_touch_icon'        => '',
            'default_og_image'        => '',
            'twitter_site'            => '',
            'twitter_card'            => 'summary_large_image',
            'google_site_verification'=> '',
            'bing_site_verification'  => '',
            'json_ld_website'         => true,
            'schema_type'             => 'person', // none|person|organization|local_business
            'organization_name'       => '',
            'organization_logo'       => '',
            'same_as'                 => '',
            'schema_email'            => '',
            'schema_phone'            => '',
            'schema_address'          => '',
            'schema_city'             => '',
            'schema_region'           => '',
            'schema_postal'            => '',
            'schema_country'          => 'US',
            'place_id'                => '',
            'gbp_url'                 => '',
            'maps_embed_url'          => '',
            'noindex_paths'           => '/admin,/api,/old',
        ];
    }

    public static function normalizeSettings(array $seo): array {
        $seo = array_merge(self::defaults(), $seo);
        $seo['robots_auto'] = !empty($seo['robots_auto']);
        $seo['sitemap_auto'] = !empty($seo['sitemap_auto']);
        $seo['sitemap_enabled'] = !empty($seo['sitemap_enabled']);
        $seo['sitemap_include_images'] = !empty($seo['sitemap_include_images']);
        $allowed = ['none', 'person', 'organization', 'local_business'];
        if (!in_array((string)($seo['schema_type'] ?? ''), $allowed, true)) {
            // Back-compat: old json_ld_organization toggle
            $seo['schema_type'] = !empty($seo['json_ld_organization']) ? 'organization' : 'person';
        }
        if (!$seo['robots_auto'] && trim((string)($seo['robots_manual'] ?? '')) === '') {
            $seo['robots_manual'] = self::buildRobotsTxt($seo);
        }
        if (!$seo['sitemap_auto'] && trim((string)($seo['sitemap_manual'] ?? '')) === '') {
            $seo['sitemap_manual'] = self::buildSitemapXml($seo);
        }
        return $seo;
    }

    public static function settings(): array {
        $seo = array_merge(self::defaults(), Database::get()->getSetting('seo'));
        // Transparent fallbacks: pick common upload filenames if settings left blank
        if (trim((string)($seo['favicon'] ?? '')) === '') {
            $seo['favicon'] = self::detectUpload('uploads/favicon.ico', 'uploads/favicon.png', 'uploads/favicon.svg', 'uploads/icon.png');
        }
        if (trim((string)($seo['apple_touch_icon'] ?? '')) === '') {
            $seo['apple_touch_icon'] = self::detectUpload('uploads/apple-touch-icon.png', 'uploads/apple-touch-icon.jpg');
        }
        if (trim((string)($seo['default_og_image'] ?? '')) === '') {
            $seo['default_og_image'] = self::detectUpload(
                'uploads/og-default.jpg', 'uploads/og-default.png', 'uploads/og.jpg', 'uploads/social.jpg', 'uploads/share.png'
            );
        }
        if (trim((string)($seo['organization_name'] ?? '')) === '') {
            $seo['organization_name'] = (string)(Database::get()->getSetting('site')['title'] ?? '');
        }
        return $seo;
    }

    /** Return first existing /uploads/… path (with leading slash) or ''. */
    public static function detectUpload(string ...$candidates): string {
        foreach ($candidates as $rel) {
            $rel = '/' . ltrim(str_replace('\\', '/', $rel), '/');
            if (defined('ROOT_DIR') && is_file(ROOT_DIR . $rel)) {
                return $rel;
            }
        }
        return '';
    }

    public static function siteUrl(): string {
        $url = rtrim((string)(Database::get()->getSetting('site')['url'] ?? ''), '/');
        if ($url === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $url = ($https ? 'https://' : 'http://') . $host;
        }
        return $url;
    }

    public static function absoluteUrl(string $pathOrUrl): string {
        $pathOrUrl = trim($pathOrUrl);
        if ($pathOrUrl === '') {
            return self::siteUrl() . '/';
        }
        if (preg_match('#^https?://#i', $pathOrUrl)) {
            return $pathOrUrl;
        }
        if (str_starts_with($pathOrUrl, '/uploads/') || str_starts_with($pathOrUrl, 'uploads/')) {
            $pathOrUrl = '/' . ltrim($pathOrUrl, '/');
            return self::siteUrl() . $pathOrUrl;
        }
        if (!str_starts_with($pathOrUrl, '/')) {
            $pathOrUrl = '/' . $pathOrUrl;
        }
        return self::siteUrl() . $pathOrUrl;
    }

    /** Featured image: featured_image wins, else og_image. */
    public static function resolveImage(array $metaOrSeo, array $siteSeo = []): string {
        $img = trim((string)($metaOrSeo['featured_image'] ?? ''));
        if ($img === '') {
            $img = trim((string)($metaOrSeo['og_image'] ?? ''));
        }
        if ($img === '' && $siteSeo) {
            $img = trim((string)($siteSeo['default_og_image'] ?? ''));
        }
        return $img;
    }

    public static function forPage(array $page): array {
        $meta = PageRepo::extractMeta($page['content'] ?? '');
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $slug = $page['slug'] ?? ($meta['slug'] ?? '/' . ($page['filename'] ?? ''));
        $path = $slug === '/' || $slug === '' ? '/' : '/' . ltrim((string)$slug, '/');
        $titleFallback = $meta['title'] ?? ($site['title'] ?? 'Forma');
        $image = self::resolveImage($meta, $seo);
        return self::normalize([
            'title'       => $meta['seo_title'] ?? $titleFallback,
            'description' => $meta['seo_description'] ?? ($meta['description'] ?? ($site['description'] ?? '')),
            'og_title'    => $meta['og_title'] ?? ($meta['seo_title'] ?? $titleFallback),
            'og_description' => $meta['og_description'] ?? ($meta['seo_description'] ?? ($meta['description'] ?? ($site['description'] ?? ''))),
            'og_image'    => $image,
            'canonical'   => $meta['canonical'] ?? $path,
            'robots'      => $meta['robots'] ?? self::defaultRobotsDirective($seo),
            'twitter_card'=> $meta['twitter_card'] ?? ($seo['twitter_card'] ?? 'summary_large_image'),
            'schema_type' => $meta['schema_type'] ?? '',
            'type'        => 'website',
            'path'        => $path,
            'filename'    => $page['filename'] ?? '',
        ], $seo, $site);
    }

    public static function forPost(array $row): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $seoJson = json_decode($row['seo_json'] ?? '{}', true) ?: [];
        $path = '/blog/' . ltrim((string)($row['slug'] ?? ''), '/');
        $titleFallback = $row['title'] ?? 'Post';
        $image = self::resolveImage($seoJson, $seo);
        return self::normalize([
            'title'       => $seoJson['seo_title'] ?? $titleFallback,
            'description' => $seoJson['seo_description'] ?? ($row['description'] ?? ''),
            'og_title'    => $seoJson['og_title'] ?? ($seoJson['seo_title'] ?? $titleFallback),
            'og_description' => $seoJson['og_description'] ?? ($seoJson['seo_description'] ?? ($row['description'] ?? '')),
            'og_image'    => $image,
            'canonical'   => $seoJson['canonical'] ?? $path,
            'robots'      => $seoJson['robots'] ?? self::defaultRobotsDirective($seo),
            'twitter_card'=> $seoJson['twitter_card'] ?? ($seo['twitter_card'] ?? 'summary_large_image'),
            'schema_type' => $seoJson['schema_type'] ?? 'article',
            'type'        => 'article',
            'path'        => $path,
            'filename'    => $row['filename'] ?? '',
            'published_at'=> $row['published_at'] ?? null,
            'updated_at'  => $row['updated_at'] ?? null,
            'author'      => $row['author'] ?? '',
        ], $seo, $site);
    }

    public static function forSimple(string $path, string $title, string $description = '', string $ogImage = '', string $type = 'website'): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        return self::normalize([
            'title' => $title,
            'description' => $description !== '' ? $description : ($site['description'] ?? ''),
            'og_title' => $title,
            'og_description' => $description !== '' ? $description : ($site['description'] ?? ''),
            'og_image' => $ogImage !== '' ? $ogImage : ($seo['default_og_image'] ?? ''),
            'canonical' => $path,
            'robots' => self::defaultRobotsDirective($seo),
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'type' => $type,
            'path' => $path,
        ], $seo, $site);
    }

    public static function forBlogArchive(): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $title = ($site['title'] ?? 'Blog') . ' Blog';
        return self::normalize([
            'title'       => $title,
            'description' => $site['description'] ?? '',
            'og_title'    => $title,
            'og_description' => $site['description'] ?? '',
            'og_image'    => $seo['default_og_image'] ?? '',
            'canonical'   => '/blog',
            'robots'      => self::defaultRobotsDirective($seo),
            'twitter_card'=> $seo['twitter_card'] ?? 'summary_large_image',
            'type'        => 'website',
            'path'        => '/blog',
        ], $seo, $site);
    }

    private static function defaultRobotsDirective(array $seo): string {
        $index = !empty($seo['robots_index']) ? 'index' : 'noindex';
        $follow = !empty($seo['robots_follow']) ? 'follow' : 'nofollow';
        return $index . ',' . $follow;
    }

    private static function normalize(array $doc, array $seo, array $site): array {
        $sep = (string)($seo['title_separator'] ?? ' — ');
        $siteTitle = (string)($site['title'] ?? '');
        $displayTitle = (string)$doc['title'];
        if (!empty($seo['title_suffix']) && $siteTitle !== ''
            && !str_contains($displayTitle, $siteTitle)
        ) {
            $displayTitle = $displayTitle . $sep . $siteTitle;
        }
        $canonical = (string)$doc['canonical'];
        if (!preg_match('#^https?://#i', $canonical)) {
            $canonical = self::absoluteUrl($canonical === '' ? ($doc['path'] ?? '/') : $canonical);
        }
        $ogImage = (string)($doc['og_image'] ?? '');
        if ($ogImage !== '') {
            $ogImage = self::absoluteUrl($ogImage);
        }
        $doc['display_title'] = $displayTitle;
        $doc['canonical'] = $canonical;
        $doc['og_image'] = $ogImage;
        $doc['site_title'] = $siteTitle;
        $doc['twitter_site'] = (string)($seo['twitter_site'] ?? '');
        $doc['google_site_verification'] = (string)($seo['google_site_verification'] ?? '');
        $doc['bing_site_verification'] = (string)($seo['bing_site_verification'] ?? '');
        $doc['json_ld_website'] = !empty($seo['json_ld_website']);
        $doc['favicon'] = (string)($seo['favicon'] ?? '');
        $doc['apple_touch_icon'] = (string)($seo['apple_touch_icon'] ?? '');
        $doc['site_seo'] = $seo;
        return $doc;
    }

    public static function headHtml(array $doc): string {
        $esc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $tags = [];
        $seo = $doc['site_seo'] ?? self::settings();

        $favicon = trim((string)($doc['favicon'] ?? ($seo['favicon'] ?? '')));
        if ($favicon !== '') {
            $href = $esc(self::absoluteUrl($favicon));
            $tags[] = '<link rel="icon" href="' . $href . '">';
        }
        $apple = trim((string)($doc['apple_touch_icon'] ?? ($seo['apple_touch_icon'] ?? '')));
        if ($apple !== '') {
            $tags[] = '<link rel="apple-touch-icon" href="' . $esc(self::absoluteUrl($apple)) . '">';
        }

        $tags[] = '<title>' . $esc($doc['display_title'] ?? $doc['title'] ?? '') . '</title>';
        if (!empty($doc['description'])) {
            $tags[] = '<meta name="description" content="' . $esc($doc['description']) . '">';
        }
        if (!empty($doc['robots'])) {
            $tags[] = '<meta name="robots" content="' . $esc($doc['robots']) . '">';
        }
        $tags[] = '<link rel="canonical" href="' . $esc($doc['canonical'] ?? '') . '">';

        $tags[] = '<meta property="og:type" content="' . $esc($doc['type'] ?? 'website') . '">';
        $tags[] = '<meta property="og:title" content="' . $esc($doc['og_title'] ?? $doc['title'] ?? '') . '">';
        if (!empty($doc['og_description'])) {
            $tags[] = '<meta property="og:description" content="' . $esc($doc['og_description']) . '">';
        }
        $tags[] = '<meta property="og:url" content="' . $esc($doc['canonical'] ?? '') . '">';
        if (!empty($doc['site_title'])) {
            $tags[] = '<meta property="og:site_name" content="' . $esc($doc['site_title']) . '">';
        }
        if (!empty($doc['og_image'])) {
            $tags[] = '<meta property="og:image" content="' . $esc($doc['og_image']) . '">';
        }

        $tags[] = '<meta name="twitter:card" content="' . $esc($doc['twitter_card'] ?? 'summary_large_image') . '">';
        $tags[] = '<meta name="twitter:title" content="' . $esc($doc['og_title'] ?? $doc['title'] ?? '') . '">';
        if (!empty($doc['og_description'])) {
            $tags[] = '<meta name="twitter:description" content="' . $esc($doc['og_description']) . '">';
        }
        if (!empty($doc['og_image'])) {
            $tags[] = '<meta name="twitter:image" content="' . $esc($doc['og_image']) . '">';
        }
        if (!empty($doc['twitter_site'])) {
            $handle = ltrim((string)$doc['twitter_site'], '@');
            $tags[] = '<meta name="twitter:site" content="@' . $esc($handle) . '">';
        }

        if (!empty($doc['google_site_verification'])) {
            $tags[] = '<meta name="google-site-verification" content="' . $esc($doc['google_site_verification']) . '">';
        }
        if (!empty($doc['bing_site_verification'])) {
            $tags[] = '<meta name="msvalidate.01" content="' . $esc($doc['bing_site_verification']) . '">';
        }

        $ld = self::jsonLd($doc);
        if ($ld) {
            $tags[] = '<script type="application/ld+json">' . $ld . '</script>';
        }

        return implode("\n", $tags);
    }

    private static function sameAsList(array $seo): array {
        $raw = trim((string)($seo['same_as'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('#^https?://#i', $p)) {
                $out[] = $p;
            }
        }
        $gbp = trim((string)($seo['gbp_url'] ?? ''));
        if ($gbp !== '' && preg_match('#^https?://#i', $gbp) && !in_array($gbp, $out, true)) {
            $out[] = $gbp;
        }
        return $out;
    }

    private static function jsonLd(array $doc): string {
        $seo = $doc['site_seo'] ?? self::settings();
        $graph = [];

        if (!empty($doc['json_ld_website'])) {
            $graph[] = [
                '@type' => 'WebSite',
                'name'  => $doc['site_title'] ?? '',
                'url'   => self::siteUrl() . '/',
                'description' => Database::get()->getSetting('site')['description'] ?? ($doc['description'] ?? ''),
            ];
        }

        $entityType = (string)($doc['schema_type'] ?? '');
        if ($entityType === '' || $entityType === 'default') {
            $entityType = (string)($seo['schema_type'] ?? 'person');
        }
        if ($entityType === 'article') {
            $entityType = (string)($seo['schema_type'] ?? 'person'); // page-level article handled below
        }

        $name = trim((string)($seo['organization_name'] ?? '')) ?: (string)($doc['site_title'] ?? '');
        $sameAs = self::sameAsList($seo);
        $logo = trim((string)($seo['organization_logo'] ?? ''));

        if ($entityType === 'person') {
            $person = [
                '@type' => 'Person',
                'name'  => $name,
                'url'   => self::siteUrl() . '/',
            ];
            if ($logo !== '') {
                $person['image'] = self::absoluteUrl($logo);
            }
            if ($sameAs) {
                $person['sameAs'] = $sameAs;
            }
            if (!empty($seo['schema_email'])) {
                $person['email'] = $seo['schema_email'];
            }
            $graph[] = $person;
        } elseif ($entityType === 'organization') {
            $org = [
                '@type' => 'Organization',
                'name'  => $name,
                'url'   => self::siteUrl() . '/',
            ];
            if ($logo !== '') {
                $org['logo'] = self::absoluteUrl($logo);
            }
            if ($sameAs) {
                $org['sameAs'] = $sameAs;
            }
            $graph[] = $org;
        } elseif ($entityType === 'local_business') {
            $biz = [
                '@type' => 'LocalBusiness',
                'name'  => $name,
                'url'   => self::siteUrl() . '/',
            ];
            if ($logo !== '') {
                $biz['image'] = self::absoluteUrl($logo);
            }
            if (!empty($seo['schema_phone'])) {
                $biz['telephone'] = $seo['schema_phone'];
            }
            if (!empty($seo['schema_email'])) {
                $biz['email'] = $seo['schema_email'];
            }
            $addrParts = array_filter([
                'streetAddress'   => trim((string)($seo['schema_address'] ?? '')),
                'addressLocality' => trim((string)($seo['schema_city'] ?? '')),
                'addressRegion'   => trim((string)($seo['schema_region'] ?? '')),
                'postalCode'      => trim((string)($seo['schema_postal'] ?? '')),
                'addressCountry'  => trim((string)($seo['schema_country'] ?? 'US')),
            ], static fn($v) => $v !== '');
            if ($addrParts) {
                $biz['address'] = array_merge(['@type' => 'PostalAddress'], $addrParts);
            }
            if ($sameAs) {
                $biz['sameAs'] = $sameAs;
            }
            if (!empty($seo['place_id'])) {
                $biz['hasMap'] = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode((string)$seo['place_id']);
            } elseif (!empty($seo['maps_embed_url'])) {
                $biz['hasMap'] = $seo['maps_embed_url'];
            }
            $graph[] = $biz;
        }

        $pageSchema = (string)($doc['schema_type'] ?? '');
        if (($doc['type'] ?? '') === 'article' || $pageSchema === 'article') {
            $article = [
                '@type' => 'BlogPosting',
                'headline' => $doc['title'] ?? '',
                'description' => $doc['description'] ?? '',
                'url' => $doc['canonical'] ?? '',
                'mainEntityOfPage' => $doc['canonical'] ?? '',
            ];
            if (!empty($doc['og_image'])) {
                $article['image'] = $doc['og_image'];
            }
            if (!empty($doc['author'])) {
                $article['author'] = ['@type' => 'Person', 'name' => $doc['author']];
            }
            if (!empty($doc['published_at'])) {
                $article['datePublished'] = date('c', (int)$doc['published_at']);
            }
            if (!empty($doc['updated_at'])) {
                $article['dateModified'] = date('c', (int)$doc['updated_at']);
            }
            $graph[] = $article;
        }

        if (!$graph) {
            return '';
        }
        $payload = count($graph) === 1
            ? array_merge(['@context' => 'https://schema.org'], $graph[0])
            : ['@context' => 'https://schema.org', '@graph' => $graph];
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function applyToHtml(string $html, array $doc): string {
        $block = self::headHtml($doc);
        $patterns = [
            '/<title\b[^>]*>.*?<\/title>/is',
            '/<meta\s+name=["\']description["\'][^>]*>/i',
            '/<meta\s+name=["\']robots["\'][^>]*>/i',
            '/<link\s+rel=["\']canonical["\'][^>]*>/i',
            '/<link\s+rel=["\']icon["\'][^>]*>/i',
            '/<link\s+rel=["\']apple-touch-icon["\'][^>]*>/i',
            '/<meta\s+property=["\']og:[^"\']+["\'][^>]*>/i',
            '/<meta\s+name=["\']twitter:[^"\']+["\'][^>]*>/i',
            '/<meta\s+name=["\']google-site-verification["\'][^>]*>/i',
            '/<meta\s+name=["\']msvalidate\.01["\'][^>]*>/i',
            '/<script\s+type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is',
        ];
        foreach ($patterns as $p) {
            $html = (string)preg_replace($p, '', $html);
        }
        if (preg_match('/<head([^>]*)>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $pos) . "\n" . $block . "\n" . substr($html, $pos);
        }
        return "<!DOCTYPE html><html><head>\n{$block}\n</head><body>{$html}</body></html>";
    }

    public static function robotsTxt(?array $seo = null): string {
        $seo = $seo ?? self::settings();
        if (empty($seo['robots_auto'])) {
            $manual = trim((string)($seo['robots_manual'] ?? ''));
            if ($manual !== '') {
                return rtrim($manual) . "\n";
            }
        }
        return self::buildRobotsTxt($seo);
    }

    public static function sitemapXml(?array $seo = null): string {
        $seo = $seo ?? self::settings();
        if (empty($seo['sitemap_enabled'])) {
            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        }
        if (empty($seo['sitemap_auto'])) {
            $manual = trim((string)($seo['sitemap_manual'] ?? ''));
            if ($manual !== '') {
                return rtrim($manual) . "\n";
            }
        }
        return self::buildSitemapXml($seo);
    }

    public static function buildRobotsTxt(array $seo): string {
        $site = self::siteUrl();
        $lines = ['User-agent: *'];
        if (empty($seo['robots_index'])) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Allow: /';
            $noindex = array_filter(array_map('trim', explode(',', (string)($seo['noindex_paths'] ?? '/admin,/api,/old'))));
            foreach ($noindex as $p) {
                if ($p === '') {
                    continue;
                }
                if (!str_starts_with($p, '/')) {
                    $p = '/' . $p;
                }
                $lines[] = 'Disallow: ' . $p;
            }
        }
        $extra = trim((string)($seo['robots_extra'] ?? ''));
        if ($extra !== '') {
            $lines[] = '';
            foreach (preg_split('/\r\n|\r|\n/', $extra) ?: [] as $line) {
                $lines[] = $line;
            }
        }
        if (!empty($seo['sitemap_enabled'])) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $site . '/sitemap.xml';
        }
        return implode("\n", $lines) . "\n";
    }

    public static function buildSitemapXml(array $seo): string {
        if (empty($seo['sitemap_enabled'])) {
            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
        }
        $urls = [];
        $now = time();
        $withImages = !empty($seo['sitemap_include_images']);

        if (!empty($seo['sitemap_include_pages'])) {
            foreach (PageRepo::list() as $p) {
                $fn = $p['filename'] ?? '';
                if (str_starts_with($fn, '_') || in_array($fn, ['blog-archive', 'blog-single', 'podcast-archive', 'podcast-single'], true)) {
                    continue;
                }
                $full = PageRepo::get($fn);
                if (!$full) {
                    continue;
                }
                $meta = PageRepo::extractMeta($full['content'] ?? '');
                $robots = strtolower((string)($meta['robots'] ?? ''));
                if (str_contains($robots, 'noindex')) {
                    continue;
                }
                $slug = $p['slug'] ?? ('/' . $fn);
                $loc = self::absoluteUrl($slug === '/' || $slug === '' ? '/' : $slug);
                $entry = [
                    'loc' => $loc,
                    'lastmod' => !empty($p['updated_at']) ? date('c', (int)$p['updated_at']) : null,
                    'changefreq' => $slug === '/' || $slug === '' ? 'weekly' : 'monthly',
                    'priority' => ($slug === '/' || $slug === '') ? '1.0' : '0.7',
                ];
                if ($withImages) {
                    $img = self::resolveImage($meta, $seo);
                    if ($img !== '') {
                        $entry['images'] = [self::absoluteUrl($img)];
                    }
                }
                $urls[] = $entry;
            }
        }

        if (!empty($seo['sitemap_include_posts'])) {
            foreach (BlogRepo::list(true) as $post) {
                $seoJson = [];
                $full = BlogRepo::get($post['filename']);
                if ($full) {
                    $seoJson = json_decode($full['seo_json'] ?? '{}', true) ?: [];
                }
                $robots = strtolower((string)($seoJson['robots'] ?? ''));
                if (str_contains($robots, 'noindex')) {
                    continue;
                }
                $entry = [
                    'loc' => self::absoluteUrl('/blog/' . $post['slug']),
                    'lastmod' => !empty($post['updated_at']) ? date('c', (int)$post['updated_at']) : (
                        !empty($post['published_at']) ? date('c', (int)$post['published_at']) : null
                    ),
                    'changefreq' => 'monthly',
                    'priority' => '0.6',
                ];
                if ($withImages) {
                    $img = self::resolveImage($seoJson, $seo);
                    if ($img !== '') {
                        $entry['images'] = [self::absoluteUrl($img)];
                    }
                }
                $urls[] = $entry;
            }
            $urls[] = [
                'loc' => self::absoluteUrl('/blog'),
                'lastmod' => date('c', $now),
                'changefreq' => 'daily',
                'priority' => '0.8',
            ];
        }

        if (!empty($seo['sitemap_include_podcast']) && License::isPodcastLicensed()) {
            $urls[] = [
                'loc' => self::absoluteUrl('/podcast'),
                'lastmod' => date('c', $now),
                'changefreq' => 'weekly',
                'priority' => '0.5',
            ];
            foreach (PodcastRepo::list() as $ep) {
                if (empty($ep['published_at']) || (int)$ep['published_at'] > $now) {
                    continue;
                }
                $entry = [
                    'loc' => self::absoluteUrl('/podcast/' . $ep['episode_id']),
                    'lastmod' => date('c', (int)$ep['published_at']),
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ];
                if ($withImages && !empty($ep['episode_art'])) {
                    $entry['images'] = [self::absoluteUrl($ep['episode_art'])];
                }
                $urls[] = $entry;
            }
        }

        $seen = [];
        $useImageNs = $withImages && array_filter($urls, static fn($u) => !empty($u['images']));
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
            . ($useImageNs ? ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"' : '')
            . ">\n";
        foreach ($urls as $u) {
            $loc = $u['loc'];
            if (isset($seen[$loc])) {
                continue;
            }
            $seen[$loc] = true;
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
            if (!empty($u['lastmod'])) {
                $xml .= '    <lastmod>' . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
            }
            if (!empty($u['changefreq'])) {
                $xml .= '    <changefreq>' . htmlspecialchars($u['changefreq'], ENT_XML1) . "</changefreq>\n";
            }
            if (!empty($u['priority'])) {
                $xml .= '    <priority>' . htmlspecialchars($u['priority'], ENT_XML1) . "</priority>\n";
            }
            foreach ($u['images'] ?? [] as $img) {
                $xml .= "    <image:image>\n";
                $xml .= '      <image:loc>' . htmlspecialchars($img, ENT_XML1) . "</image:loc>\n";
                $xml .= "    </image:image>\n";
            }
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    /**
     * Sitewide SEO health audit for the admin dashboard.
     * @return array{score:int,counts:array,issues:list<array>}
     */
    public static function healthReport(): array {
        $seo = self::settings();
        $site = Database::get()->getSetting('site');
        $issues = [];
        $add = static function (string $severity, string $level, string $message, string $fix = '', string $href = '') use (&$issues): void {
            $issues[] = compact('severity', 'level', 'message', 'fix', 'href');
        };

        if (trim((string)($site['url'] ?? '')) === '') {
            $add('site_url', 'fail', 'Site URL is empty', 'Set it under Settings → General', 'index.php?section=settings&sub=general');
        }
        if (trim((string)($seo['favicon'] ?? '')) === '') {
            $add('favicon', 'warn', 'No favicon set', 'Add one under Branding & social', '');
        }
        if (trim((string)($seo['default_og_image'] ?? '')) === '') {
            $add('default_og', 'warn', 'No default social / OG image', 'Upload a 1200×630 image for shares', '');
        }
        if (empty($seo['sitemap_enabled'])) {
            $add('sitemap', 'warn', 'Sitemap is disabled', 'Enable sitemap.xml for discovery', '');
        }
        $faviconPath = trim((string)($seo['favicon'] ?? ''));
        if ($faviconPath !== '' && str_starts_with($faviconPath, '/uploads/')) {
            $abs = ROOT_DIR . $faviconPath;
            if (!is_file($abs)) {
                $add('favicon_missing', 'fail', 'Favicon path does not exist on disk', $faviconPath, '');
            }
        }

        $titles = [];
        $descs = [];
        $skip = ['_404', '_403', '_500', 'blog-archive', 'blog-single', 'podcast-archive', 'podcast-single'];

        foreach (PageRepo::list() as $p) {
            $fn = $p['filename'] ?? '';
            if (str_starts_with($fn, '_') || in_array($fn, $skip, true)) {
                continue;
            }
            $full = PageRepo::get($fn);
            if (!$full) {
                continue;
            }
            $meta = PageRepo::extractMeta($full['content'] ?? '');
            $title = trim((string)($meta['seo_title'] ?? $meta['title'] ?? ''));
            $desc = trim((string)($meta['seo_description'] ?? $meta['description'] ?? ''));
            $img = self::resolveImage($meta, $seo);
            $robots = strtolower((string)($meta['robots'] ?? ''));
            $href = 'index.php?section=pages&file=' . rawurlencode($fn);
            $label = $fn;

            if ($title === '') {
                $add('title_missing', 'fail', "Page “{$label}” has no title", 'Set title or SEO title', $href);
            } else {
                $len = mb_strlen($title);
                if ($len > 60) {
                    $add('title_long', 'warn', "Page “{$label}” title is {$len} chars (aim ≤60)", $title, $href);
                }
                $key = mb_strtolower($title);
                if (isset($titles[$key])) {
                    $add('title_dupe', 'fail', "Duplicate title: “{$title}” ({$titles[$key]} & {$label})", '', $href);
                }
                $titles[$key] = $label;
            }
            if ($desc === '') {
                $add('desc_missing', 'warn', "Page “{$label}” has no meta description", '', $href);
            } else {
                $len = mb_strlen($desc);
                if ($len > 160) {
                    $add('desc_long', 'warn', "Page “{$label}” description is {$len} chars (aim ≤160)", '', $href);
                } elseif ($len < 50) {
                    $add('desc_short', 'info', "Page “{$label}” description is short ({$len} chars)", '', $href);
                }
                $dkey = mb_strtolower($desc);
                if (isset($descs[$dkey])) {
                    $add('desc_dupe', 'warn', "Duplicate description on “{$label}” (also {$descs[$dkey]})", '', $href);
                }
                $descs[$dkey] = $label;
            }
            if ($img === '' && trim((string)($seo['default_og_image'] ?? '')) === '') {
                $add('image_missing', 'warn', "Page “{$label}” has no featured image (and no site default)", 'Set a default social image once in SEO settings', $href);
            }
            if (str_contains($robots, 'noindex') && empty($seo['sitemap_enabled']) === false) {
                // Fine — sitemap builder skips noindex; just note
            }
        }

        foreach (BlogRepo::list(false) as $post) {
            $full = BlogRepo::get($post['filename']);
            if (!$full) {
                continue;
            }
            $seoJson = json_decode($full['seo_json'] ?? '{}', true) ?: [];
            $title = trim((string)($seoJson['seo_title'] ?? $full['title'] ?? ''));
            $desc = trim((string)($seoJson['seo_description'] ?? $full['description'] ?? ''));
            $img = self::resolveImage($seoJson, $seo);
            $href = 'index.php?section=blog&file=' . rawurlencode($post['filename']);
            $label = 'post:' . ($post['slug'] ?: $post['filename']);
            $pub = !empty($full['published_at']) && (int)$full['published_at'] <= time();

            if (!$pub) {
                continue; // draft — skip health noise
            }
            if ($title === '') {
                $add('title_missing', 'fail', "Post “{$label}” has no title", '', $href);
            }
            if ($desc === '') {
                $add('desc_missing', 'warn', "Post “{$label}” has no description", '', $href);
            }
            if ($img === '' && trim((string)($seo['default_og_image'] ?? '')) === '') {
                $add('image_missing', 'warn', "Post “{$label}” has no featured image (and no site default)", '', $href);
            }
        }

        $fail = count(array_filter($issues, static fn($i) => $i['level'] === 'fail'));
        $warn = count(array_filter($issues, static fn($i) => $i['level'] === 'warn'));
        $info = count(array_filter($issues, static fn($i) => $i['level'] === 'info'));
        $score = max(0, min(100, 100 - ($fail * 12) - ($warn * 4) - ($info * 1)));

        return [
            'score' => $score,
            'counts' => ['fail' => $fail, 'warn' => $warn, 'info' => $info, 'total' => count($issues)],
            'issues' => $issues,
        ];
    }

    public static function parseSeoPostFields(array $src): array {
        $out = [];
        foreach (self::PAGE_META_KEYS as $k) {
            if (array_key_exists($k, $src)) {
                $out[$k] = trim((string)$src[$k]);
            }
        }
        if (isset($src['seo']) && is_array($src['seo'])) {
            foreach (self::PAGE_META_KEYS as $k) {
                if (array_key_exists($k, $src['seo'])) {
                    $out[$k] = trim((string)$src['seo'][$k]);
                }
            }
        }
        // featured_image aliases to og_image when og empty
        if (!empty($out['featured_image']) && empty($out['og_image'])) {
            $out['og_image'] = $out['featured_image'];
        }
        if (!empty($out['og_image']) && empty($out['featured_image'])) {
            $out['featured_image'] = $out['og_image'];
        }
        return array_filter($out, static fn($v) => $v !== '');
    }
}

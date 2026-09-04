<?php
/**
 * Forma SEO — sitewide settings, per-document meta, robots.txt, sitemap.xml, llms.txt, health.
 */
class Seo {
    /** Reserved shortcode — not a Snippets row. PHP replaces it with headHtml(). */
    public const SLOT = 'seo';
    public const SLOT_TOKEN = '[[seo]]';

    public const PAGE_META_KEYS = [
        'seo_title', 'seo_description', 'og_title', 'og_description',
        'og_image', 'featured_image', 'canonical', 'robots', 'twitter_card', 'schema_type',
    ];

    /** Template / system pages — never public content, never in sitemap or llms.txt. */
    public const TEMPLATE_FILENAMES = [
        '_404', '_403', '_500',
        'blog-archive', 'blog-single', 'podcast-archive', 'podcast-single',
        'search-page', 'search-results',
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
            'llms_auto'               => true,
            'llms_manual'             => '',
            'llms_enabled'            => true,
            'title_separator'         => ' — ',
            'title_suffix'            => true,
            'favicon'                 => '',
            'apple_touch_icon'        => '',
            'default_og_image'        => '',
            'twitter_site'            => '',
            'twitter_card'            => 'summary_large_image',
            'google_site_verification'=> '',
            'bing_site_verification'  => '',
            'google_analytics'        => '',
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
            'schema_hours'            => '',
            'schema_price_range'      => '',
            'place_id'                => '',
            'gbp_url'                 => '',
            'review_url'              => '',
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
        $seo['llms_auto'] = !empty($seo['llms_auto']);
        $seo['llms_enabled'] = !empty($seo['llms_enabled']);
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
        if (!$seo['llms_auto'] && trim((string)($seo['llms_manual'] ?? '')) === '') {
            $seo['llms_manual'] = self::buildLlmsTxt($seo);
        }
        $seo['google_site_verification'] = self::parseVerificationToken((string)($seo['google_site_verification'] ?? ''));
        $seo['bing_site_verification'] = self::parseVerificationToken((string)($seo['bing_site_verification'] ?? ''));
        $seo['google_analytics'] = self::parseAnalyticsId((string)($seo['google_analytics'] ?? ''));
        return $seo;
    }

    /** Pull a token out of a raw paste (full meta tag or the value itself). */
    public static function parseVerificationToken(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $raw, $m)) {
            return trim($m[1]);
        }
        return trim($raw);
    }

    /** Accept G- / GTM- / UA- or a pasted snippet containing one. */
    public static function parseAnalyticsId(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/\b(GTM-[A-Z0-9]+)\b/i', $raw, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(G-[A-Z0-9]+)\b/i', $raw, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(UA-\d+-\d+)\b/i', $raw, $m)) {
            return strtoupper($m[1]);
        }
        return $raw;
    }

    public static function analyticsHeadHtml(array $seo): string {
        $id = self::parseAnalyticsId((string)($seo['google_analytics'] ?? ''));
        if ($id === '') {
            return '';
        }
        $esc = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        if (str_starts_with($id, 'GTM-')) {
            return '<script data-fx-analytics="gtm">(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({\'gtm.start\':Date.now(),event:\'gtm.js\'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!=\'dataLayer\'?\'&l=\'+l:\'\';j.async=true;j.src=\'https://www.googletagmanager.com/gtm.js?id=\'+i+dl;f.parentNode.insertBefore(j,f);})(window,document,\'script\',\'dataLayer\',\'' . $esc . '\');</script>';
        }
        return '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $esc . '" data-fx-analytics="ga4"></script>' . "\n"
            . '<script data-fx-analytics="ga4">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag(\'js\',new Date());gtag(\'config\',\'' . $esc . '\');</script>';
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
            'seo_head'    => self::normalizeHeadMode($meta['seo_head'] ?? 'auto'),
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
            'seo_head'    => self::templateHeadMode('blog-single'),
        ], $seo, $site);
    }

    public static function forPodcastArchive(array $podcastCtx): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $title = (string)($podcastCtx['title'] ?: ($site['title'] ?? 'Podcast'));
        $desc = (string)($podcastCtx['description'] ?? '');
        $image = trim((string)($podcastCtx['cover_art'] ?? ''));
        return self::normalize([
            'title' => $title,
            'description' => $desc !== '' ? $desc : (string)($site['description'] ?? ''),
            'og_title' => $title,
            'og_description' => $desc,
            'og_image' => $image !== '' ? $image : ($seo['default_og_image'] ?? ''),
            'canonical' => '/podcast',
            'robots' => self::defaultRobotsDirective($seo),
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'type' => 'website',
            'path' => '/podcast',
            'seo_head' => self::templateHeadMode('podcast-archive'),
            'podcast_series' => [
                'name' => $title,
                'description' => $desc,
                'image' => $image !== '' ? self::absoluteUrl($image) : '',
                'url' => self::absoluteUrl('/podcast'),
            ],
        ], $seo, $site);
    }

    public static function forPodcastEpisode(array $episode, array $podcastCtx): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $episodeId = (string)($episode['episode_id'] ?? '');
        $path = '/podcast/' . $episodeId;
        $title = (string)($episode['title'] ?: ($episodeId ?: 'Episode'));
        $desc = (string)($episode['description'] ?? '');
        $image = trim((string)($episode['episode_art'] ?? '')) ?: trim((string)($podcastCtx['cover_art'] ?? ''));
        $audioFile = trim((string)($episode['audio_file'] ?? ''));
        $seriesTitle = (string)($podcastCtx['title'] ?: ($site['title'] ?? 'Podcast'));
        return self::normalize([
            'title' => $title,
            'description' => $desc !== '' ? $desc : (string)($site['description'] ?? ''),
            'og_title' => $title,
            'og_description' => $desc,
            'og_image' => $image,
            'canonical' => $path,
            'robots' => self::defaultRobotsDirective($seo),
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'type' => 'website',
            'path' => $path,
            'seo_head' => self::templateHeadMode('podcast-single'),
            'podcast_episode' => [
                'name' => $title,
                'description' => $desc,
                'url' => self::absoluteUrl($path),
                'image' => $image !== '' ? self::absoluteUrl($image) : '',
                'audio_url' => $audioFile !== '' && function_exists('forma_uploads_web_prefix')
                    ? self::absoluteUrl(forma_uploads_web_prefix() . basename($audioFile))
                    : '',
                'duration_iso' => self::isoDuration((string)($episode['duration'] ?? '')),
                'episode_number' => (int)($episode['episode_number'] ?? 0),
                'season_number' => (int)($episode['season_number'] ?? 0),
                'published_at' => $episode['published_at'] ?? null,
                'series_name' => $seriesTitle,
                'series_url' => self::absoluteUrl('/podcast'),
            ],
        ], $seo, $site);
    }

    /** "HH:MM:SS" / "MM:SS" / "SS" -> ISO-8601 duration (schema.org AudioObject.duration). */
    private static function isoDuration(string $hms): string {
        $hms = trim($hms);
        if ($hms === '' || !preg_match('/^\d{1,3}(:\d{1,2}){0,2}$/', $hms)) {
            return '';
        }
        $parts = array_map('intval', explode(':', $hms));
        $h = 0;
        $m = 0;
        $s = 0;
        if (count($parts) === 3) {
            [$h, $m, $s] = $parts;
        } elseif (count($parts) === 2) {
            [$m, $s] = $parts;
        } else {
            $s = $parts[0];
        }
        if (!$h && !$m && !$s) {
            return '';
        }
        $out = 'PT';
        if ($h) {
            $out .= $h . 'H';
        }
        if ($m) {
            $out .= $m . 'M';
        }
        if ($s || (!$h && !$m)) {
            $out .= $s . 'S';
        }
        return $out;
    }

    public static function forSimple(string $path, string $title, string $description = '', string $ogImage = '', string $type = 'website', string $headTemplate = ''): array {
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
            'seo_head' => $headTemplate !== '' ? self::templateHeadMode($headTemplate) : 'auto',
        ], $seo, $site);
    }

    public static function forBlogArchive(): array {
        $site = Database::get()->getSetting('site');
        $seo  = self::settings();
        $tpl = Database::get()->queryOne("SELECT content FROM pages WHERE filename = 'blog-archive'");
        $meta = PageRepo::extractMeta($tpl['content'] ?? '');
        $title = $meta['seo_title'] ?? (($site['title'] ?? 'Blog') . ' Blog');
        $desc = $meta['seo_description'] ?? ($site['description'] ?? '');
        $image = trim((string)($meta['og_image'] ?? $meta['featured_image'] ?? ''));
        if ($image === '') {
            $image = $seo['default_og_image'] ?? '';
        }
        return self::normalize([
            'title'       => $title,
            'description' => $desc,
            'og_title'    => $meta['og_title'] ?? $title,
            'og_description' => $meta['og_description'] ?? $desc,
            'og_image'    => $image,
            'canonical'   => $meta['canonical'] ?? '/blog',
            'robots'      => $meta['robots'] ?? self::defaultRobotsDirective($seo),
            'twitter_card'=> $meta['twitter_card'] ?? ($seo['twitter_card'] ?? 'summary_large_image'),
            'type'        => 'website',
            'path'        => '/blog',
            'seo_head'    => self::templateHeadMode('blog-archive'),
        ], $seo, $site);
    }

    public static function htmlHasSlot(string $html): bool {
        return str_contains($html, self::SLOT_TOKEN);
    }

    public static function normalizeHeadMode(string $mode): string {
        $mode = strtolower(trim($mode));
        return in_array($mode, ['auto', 'slot', 'off'], true) ? $mode : 'auto';
    }

    /** seo_head META on a template page (blog-single, search-page, …). */
    public static function templateHeadMode(string $filename): string {
        if (!class_exists('PageRepo')) {
            return 'auto';
        }
        $row = PageRepo::get($filename);
        if (!$row) {
            return 'auto';
        }
        $meta = PageRepo::extractMeta($row['content'] ?? '');
        return self::normalizeHeadMode((string)($meta['seo_head'] ?? 'auto'));
    }

    /**
     * Sticky head mode from the stored file.
     * auto  — never had [[seo]]; still inject after <head> (legacy)
     * slot  — token is in the file; emit only there
     * off   — had a slot and it was removed; do not inject
     *
     * @param array<string,string> $metaPatch
     * @return list<string>
     */
    public static function syncHeadSlot(string $oldContent, string $newContent, array &$metaPatch): array {
        $warnings = [];
        $oldMeta = class_exists('PageRepo') ? PageRepo::extractMeta($oldContent) : [];
        $prev = self::normalizeHeadMode((string)($oldMeta['seo_head'] ?? 'auto'));
        $has = self::htmlHasSlot($newContent);
        $had = self::htmlHasSlot($oldContent);
        if ($has) {
            $metaPatch['seo_head'] = 'slot';
            if ($prev === 'off') {
                $warnings[] = 'seo_slot_restored';
            } elseif ($prev === 'auto' && !$had) {
                $warnings[] = 'seo_slot_pinned';
            }
        } elseif ($had || $prev === 'slot') {
            $metaPatch['seo_head'] = 'off';
            $warnings[] = 'seo_slot_removed';
        } elseif ($prev === 'off') {
            $metaPatch['seo_head'] = 'off';
        } else {
            $metaPatch['seo_head'] = 'auto';
        }
        return $warnings;
    }

    public static function warningMessage(string $code): string {
        [$base, $n] = array_pad(explode(':', $code, 2), 2, '');
        $map = [
            'seo_slot_removed' => 'SEO slot removed — Forma will not emit <head> tags on this template',
            'seo_slot_restored' => 'SEO slot restored',
            'seo_slot_pinned' => 'SEO chip added — head tags emit at [[seo]]',
            'custom_schema_invalid_json' => 'Custom JSON-LD block' . ($n !== '' ? " #{$n}" : '') . ' is not valid JSON — it will be dropped, not published',
            'custom_schema_missing_type' => 'Custom JSON-LD block' . ($n !== '' ? " #{$n}" : '') . ' has no "@type" — schema.org requires one, it will be dropped',
            'custom_schema_malformed_tag' => 'Found data-fx-schema but the <script type="application/ld+json"> tag looks malformed',
        ];
        return $map[$base] ?? $code;
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
        $doc['seo_head'] = self::normalizeHeadMode((string)($doc['seo_head'] ?? 'auto'));
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

        $analytics = self::analyticsHeadHtml($seo);
        if ($analytics !== '') {
            $tags[] = $analytics;
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
        foreach (['gbp_url', 'review_url', 'maps_embed_url'] as $extraKey) {
            $extra = trim((string)($seo[$extraKey] ?? ''));
            if ($extra !== '' && preg_match('#^https?://#i', $extra) && !in_array($extra, $out, true)) {
                $out[] = $extra;
            }
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
            $placeId = trim((string)($seo['place_id'] ?? ''));
            $mapUrl = trim((string)($seo['gbp_url'] ?? ''))
                ?: trim((string)($seo['maps_embed_url'] ?? ''));
            if ($placeId !== '') {
                $biz['hasMap'] = 'https://www.google.com/maps/place/?q=place_id:' . rawurlencode($placeId);
            } elseif ($mapUrl !== '') {
                $biz['hasMap'] = $mapUrl;
            }
            $hoursRaw = trim((string)($seo['schema_hours'] ?? ''));
            if ($hoursRaw !== '') {
                $hours = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $hoursRaw) ?: [])));
                if ($hours) {
                    $biz['openingHours'] = count($hours) === 1 ? $hours[0] : $hours;
                }
            }
            $priceRange = trim((string)($seo['schema_price_range'] ?? ''));
            if ($priceRange !== '') {
                $biz['priceRange'] = $priceRange;
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

        if (!empty($doc['podcast_series'])) {
            $ps = $doc['podcast_series'];
            $series = [
                '@type' => 'PodcastSeries',
                'name' => $ps['name'] ?? '',
                'url' => $ps['url'] ?? '',
            ];
            if (!empty($ps['description'])) {
                $series['description'] = $ps['description'];
            }
            if (!empty($ps['image'])) {
                $series['image'] = $ps['image'];
            }
            $graph[] = $series;
        }

        if (!empty($doc['podcast_episode'])) {
            $pe = $doc['podcast_episode'];
            $episode = [
                '@type' => 'PodcastEpisode',
                'name' => $pe['name'] ?? '',
                'url' => $pe['url'] ?? '',
            ];
            if (!empty($pe['description'])) {
                $episode['description'] = $pe['description'];
            }
            if (!empty($pe['image'])) {
                $episode['image'] = $pe['image'];
            }
            if (!empty($pe['episode_number'])) {
                $episode['episodeNumber'] = $pe['episode_number'];
            }
            if (!empty($pe['season_number'])) {
                $episode['seasonNumber'] = $pe['season_number'];
            }
            if (!empty($pe['published_at'])) {
                $episode['datePublished'] = date('c', (int)$pe['published_at']);
            }
            if (!empty($pe['series_name'])) {
                $episode['partOfSeries'] = [
                    '@type' => 'PodcastSeries',
                    'name' => $pe['series_name'],
                    'url' => $pe['series_url'] ?? self::absoluteUrl('/podcast'),
                ];
            }
            if (!empty($pe['audio_url'])) {
                $audio = ['@type' => 'AudioObject', 'contentUrl' => $pe['audio_url']];
                if (!empty($pe['duration_iso'])) {
                    $audio['duration'] = $pe['duration_iso'];
                }
                $episode['associatedMedia'] = $audio;
            }
            $graph[] = $episode;
        }

        if (!empty($doc['_faq_items'])) {
            $qas = [];
            foreach ($doc['_faq_items'] as $item) {
                $qas[] = [
                    '@type' => 'Question',
                    'name' => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ];
            }
            if ($qas) {
                $graph[] = ['@type' => 'FAQPage', 'mainEntity' => $qas];
            }
        }

        if (!empty($doc['_custom_schema'])) {
            foreach ($doc['_custom_schema'] as $node) {
                $graph[] = $node;
            }
        }

        $crumbs = self::breadcrumbList($doc);
        if ($crumbs) {
            $graph[] = $crumbs;
        }

        if (!$graph) {
            return '';
        }
        $payload = count($graph) === 1
            ? array_merge(['@context' => 'https://schema.org'], $graph[0])
            : ['@context' => 'https://schema.org', '@graph' => $graph];
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Scan rendered HTML for `data-fx-faq` accordion blocks and pull clean
     * Q/A pairs for FAQPage JSON-LD. The visible <details class="fx-faq-item">
     * markup (seeded by the faq-ui snippet + toolbar "FAQ block" insert) is
     * the only source of truth — there's no separate FAQ data to keep in sync,
     * and Google requires the JSON-LD to match what's actually on the page.
     *
     * @return list<array{q:string,a:string}>
     */
    public static function extractFaqItems(string $html): array {
        if (!str_contains($html, 'fx-faq-item')) {
            return [];
        }
        $items = [];
        if (preg_match_all(
            '/<details\b[^>]*\bclass=(["\'])[^"\']*\bfx-faq-item\b[^"\']*\1[^>]*>(.*?)<\/details>/is',
            $html,
            $m
        )) {
            foreach ($m[2] as $inner) {
                if (!preg_match('/<summary\b[^>]*>(.*?)<\/summary>(.*)$/is', $inner, $qm)) {
                    continue;
                }
                $q = self::plainTextSnippet(html_entity_decode(strip_tags($qm[1]), ENT_QUOTES, 'UTF-8'), 300);
                $aRaw = (string)preg_replace('/<(br|\/p|\/div|\/li)\b[^>]*>/i', "\n", $qm[2]);
                $a = trim(html_entity_decode(strip_tags($aRaw), ENT_QUOTES, 'UTF-8'));
                $a = (string)preg_replace('/[ \t]+/', ' ', $a);
                $a = trim((string)preg_replace('/\n{3,}/', "\n\n", $a));
                if ($q === '' || $a === '') {
                    continue;
                }
                $items[] = ['q' => $q, 'a' => $a];
            }
        }
        return $items;
    }

    /**
     * Scan rendered HTML for `<script type="application/ld+json" data-fx-schema>`
     * blocks — the per-page custom JSON-LD escape hatch. Accepts a single node,
     * a bare array of nodes, or {"@graph":[...]}. Nodes without "@type" are
     * dropped (schema.org requires it and it's the #1 way to catch a typo/paste
     * error before it ships broken structured data).
     *
     * These scripts are always stripped from the body by applyToHtml() —
     * whether valid or not — because the real ones get re-emitted, merged,
     * inside the single generated <script> block in <head>.
     *
     * @return list<array<string,mixed>>
     */
    public static function extractCustomSchema(string $html): array {
        if (!str_contains($html, 'data-fx-schema')) {
            return [];
        }
        $nodes = [];
        if (preg_match_all(
            '/<script\b[^>]*\btype=(["\'])application\/ld\+json\1[^>]*\bdata-fx-schema\b[^>]*>(.*?)<\/script>/is',
            $html,
            $m
        )) {
            foreach ($m[2] as $raw) {
                $decoded = json_decode(trim($raw), true);
                if (!is_array($decoded)) {
                    continue;
                }
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $node) {
                        if (is_array($node) && !empty($node['@type'])) {
                            $nodes[] = $node;
                        }
                    }
                } elseif (array_is_list($decoded)) {
                    foreach ($decoded as $node) {
                        if (is_array($node) && !empty($node['@type'])) {
                            $nodes[] = $node;
                        }
                    }
                } elseif (!empty($decoded['@type'])) {
                    unset($decoded['@context']);
                    $nodes[] = $decoded;
                }
            }
        }
        return $nodes;
    }

    /**
     * Validate `data-fx-schema` blocks at save time so a broken/typo'd JSON-LD
     * block surfaces as a warning immediately instead of silently doing
     * nothing on the live page. Returns human warning strings (empty = clean).
     *
     * @return list<string>
     */
    public static function validateCustomSchema(string $html): array {
        if (!str_contains($html, 'data-fx-schema')) {
            return [];
        }
        $warnings = [];
        if (preg_match_all(
            '/<script\b[^>]*\btype=(["\'])application\/ld\+json\1[^>]*\bdata-fx-schema\b[^>]*>(.*?)<\/script>/is',
            $html,
            $m
        )) {
            foreach ($m[2] as $i => $raw) {
                $decoded = json_decode(trim($raw), true);
                if (!is_array($decoded)) {
                    $warnings[] = 'custom_schema_invalid_json:' . ($i + 1);
                    continue;
                }
                $nodes = isset($decoded['@graph']) && is_array($decoded['@graph'])
                    ? $decoded['@graph']
                    : (array_is_list($decoded) ? $decoded : [$decoded]);
                foreach ($nodes as $node) {
                    if (!is_array($node) || empty($node['@type'])) {
                        $warnings[] = 'custom_schema_missing_type:' . ($i + 1);
                        break;
                    }
                }
            }
        } elseif (str_contains($html, 'data-fx-schema')) {
            $warnings[] = 'custom_schema_malformed_tag';
        }
        return $warnings;
    }

    public static function applyToHtml(string $html, array $doc): string {
        if (!isset($doc['_faq_items'])) {
            $doc['_faq_items'] = self::extractFaqItems($html);
        }
        if (!isset($doc['_custom_schema'])) {
            $doc['_custom_schema'] = self::extractCustomSchema($html);
        }
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
            '/<script[^>]*data-fx-analytics[^>]*>.*?<\/script>/is',
            '/<script\s+type=["\']application\/ld\+json["\'][^>]*>.*?<\/script>/is',
        ];
        foreach ($patterns as $p) {
            $html = (string)preg_replace($p, '', $html);
        }
        $slotCount = 0;
        $html = str_replace(self::SLOT_TOKEN, $block, $html, $slotCount);
        if ($slotCount > 0) {
            return $html;
        }
        $mode = self::normalizeHeadMode((string)($doc['seo_head'] ?? 'auto'));
        if ($mode === 'off' || $mode === 'slot') {
            return $html;
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

    public static function llmsTxt(?array $seo = null): string {
        $seo = $seo ?? self::settings();
        if (empty($seo['llms_enabled'])) {
            return '';
        }
        if (empty($seo['llms_auto'])) {
            $manual = trim((string)($seo['llms_manual'] ?? ''));
            if ($manual !== '') {
                return rtrim($manual) . "\n";
            }
        }
        return self::buildLlmsTxt($seo);
    }

    public static function buildLlmsTxt(array $seo): string {
        $site = Database::get()->getSetting('site');
        $title = trim((string)($site['title'] ?? '')) ?: 'Website';
        $desc = self::plainTextSnippet((string)($site['description'] ?? ''), 240);
        $now = time();
        $lines = ['# ' . $title, ''];
        if ($desc !== '') {
            $lines[] = '> ' . $desc;
            $lines[] = '';
        }
        $lines[] = 'Public pages on this site, listed for language models.';
        $lines[] = '';

        $pages = [];
        foreach (PageRepo::list() as $p) {
            $fn = $p['filename'] ?? '';
            if (str_starts_with($fn, '_') || in_array($fn, self::TEMPLATE_FILENAMES, true)) {
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
            $label = trim((string)($meta['title'] ?? $meta['seo_title'] ?? ''));
            if ($label === '') {
                $label = $fn === 'home' ? $title : $fn;
            }
            $pageDesc = (string)($meta['seo_description'] ?? $meta['description'] ?? '');
            $pages[] = self::llmsLink($label, $loc, $pageDesc);
        }
        if ($pages) {
            $lines[] = '## Pages';
            $lines[] = '';
            foreach ($pages as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        $posts = [];
        foreach (BlogRepo::list(true) as $post) {
            $full = BlogRepo::get($post['filename']);
            $seoJson = $full ? (json_decode($full['seo_json'] ?? '{}', true) ?: []) : [];
            $robots = strtolower((string)($seoJson['robots'] ?? ''));
            if (str_contains($robots, 'noindex')) {
                continue;
            }
            $label = trim((string)($post['title'] ?? $post['slug'] ?? 'Post'));
            $loc = self::absoluteUrl('/blog/' . ltrim((string)($post['slug'] ?? ''), '/'));
            $posts[] = self::llmsLink($label, $loc, (string)($post['description'] ?? ''));
        }
        if ($posts) {
            $lines[] = '## Blog';
            $lines[] = '';
            $lines[] = self::llmsLink('Blog', self::absoluteUrl('/blog'), 'All published posts');
            foreach ($posts as $line) {
                $lines[] = $line;
            }
            $lines[] = '';
        }

        if (class_exists('License') && License::isPodcastLicensed()) {
            $eps = [];
            foreach (PodcastRepo::list() as $ep) {
                if (empty($ep['published_at']) || (int)$ep['published_at'] > $now) {
                    continue;
                }
                $label = trim((string)($ep['title'] ?? $ep['episode_id'] ?? 'Episode'));
                $loc = self::absoluteUrl('/podcast/' . $ep['episode_id']);
                $eps[] = self::llmsLink($label, $loc, (string)($ep['description'] ?? ''));
            }
            if ($eps) {
                $lines[] = '## Podcast';
                $lines[] = '';
                $podTitle = trim((string)(Database::get()->getSetting('podcast')['title'] ?? ''));
                $lines[] = self::llmsLink('Podcast', self::absoluteUrl('/podcast'), $podTitle !== '' ? $podTitle : 'Episodes');
                foreach ($eps as $line) {
                    $lines[] = $line;
                }
                $lines[] = '';
            }
        }

        $lines[] = '## Optional';
        $lines[] = '';
        $lines[] = self::llmsLink('Sitemap', self::absoluteUrl('/sitemap.xml'), 'XML sitemap of public URLs');
        $lines[] = self::llmsLink('robots.txt', self::absoluteUrl('/robots.txt'), 'Crawler rules');
        if (!empty(Database::get()->getSetting('blog')['blog_feed_rss'])) {
            $lines[] = self::llmsLink('RSS feed', self::absoluteUrl('/feed.xml'), 'Blog RSS');
        }

        return implode("\n", $lines) . "\n";
    }

    private static function llmsLink(string $title, string $url, string $desc = ''): string {
        $title = str_replace(['[', ']', "\n", "\r"], ['', '', ' ', ''], $title);
        $title = trim($title) !== '' ? trim($title) : $url;
        $desc = self::plainTextSnippet($desc);
        if ($desc === '') {
            return '- [' . $title . '](' . $url . ')';
        }
        return '- [' . $title . '](' . $url . '): ' . $desc;
    }

    private static function plainTextSnippet(string $raw, int $max = 200): string {
        if (class_exists('Render')) {
            $raw = Render::neutralizeShortcodes($raw);
        }
        $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES, 'UTF-8');
        $raw = preg_replace('/\s+/u', ' ', $raw) ?? $raw;
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($raw) > $max) {
            return rtrim(mb_substr($raw, 0, $max - 1)) . '…';
        }
        if (strlen($raw) > $max) {
            return rtrim(substr($raw, 0, $max - 1)) . '…';
        }
        return $raw;
    }

    /** JSON-LD BreadcrumbList for non-home URLs. Homepage is omitted. */
    private static function breadcrumbList(array $doc): ?array {
        $rawPath = (string)($doc['path'] ?? '');
        $path = trim($rawPath, '/');
        if ($path === '') {
            return null;
        }
        $pageTitle = trim((string)($doc['title'] ?? ''));
        $canonical = (string)($doc['canonical'] ?? self::absoluteUrl($rawPath === '' ? '/' : $rawPath));
        $items = [
            [
                '@type'    => 'ListItem',
                'position' => 1,
                'name'     => 'Home',
                'item'     => self::siteUrl() . '/',
            ],
        ];
        if ($path === 'blog' || str_starts_with($path, 'blog/')) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => 'Blog',
                'item'     => self::absoluteUrl('/blog'),
            ];
            if ($path !== 'blog') {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => 3,
                    'name'     => $pageTitle !== '' ? $pageTitle : basename($path),
                    'item'     => $canonical,
                ];
            }
        } elseif ($path === 'podcast' || str_starts_with($path, 'podcast/')) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => 'Podcast',
                'item'     => self::absoluteUrl('/podcast'),
            ];
            if ($path !== 'podcast') {
                $items[] = [
                    '@type'    => 'ListItem',
                    'position' => 3,
                    'name'     => $pageTitle !== '' ? $pageTitle : basename($path),
                    'item'     => $canonical,
                ];
            }
        } else {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => 2,
                'name'     => $pageTitle !== '' ? $pageTitle : str_replace(['-', '_'], ' ', $path),
                'item'     => $canonical,
            ];
        }
        return [
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
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

    /** ISO-8601 lastmod. Accepts unix ints or SQL/ISO datetime strings; ignores epoch-garbage. */
    public static function lastmodIso(mixed $ts): ?string {
        if ($ts === null || $ts === '' || $ts === false) {
            return null;
        }
        if (is_numeric($ts)) {
            $n = (int)$ts;
        } else {
            $parsed = strtotime((string)$ts);
            $n = $parsed === false ? 0 : $parsed;
        }
        if ($n < 946684800) { // 2000-01-01
            return null;
        }
        return date('c', $n);
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
                if (str_starts_with($fn, '_') || in_array($fn, self::TEMPLATE_FILENAMES, true)) {
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
                    'lastmod' => self::lastmodIso($p['updated_at'] ?? null),
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
                    'lastmod' => self::lastmodIso($post['updated_at'] ?? null)
                        ?? self::lastmodIso($post['published_at'] ?? null),
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
                    'lastmod' => self::lastmodIso($ep['published_at'] ?? null),
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
    private static function uploadPathExists(string $path): bool {
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/uploads/')) {
            return true;
        }
        return defined('ROOT_DIR') && is_file(ROOT_DIR . $path);
    }

    /** @param callable $add */
    private static function auditDocument(
        callable $add,
        array &$titles,
        array &$descs,
        string $kind,
        string $label,
        string $href,
        string $title,
        string $displayTitle,
        string $desc,
        string $img,
        array $seo,
        string $robots = ''
    ): void {
        $displayTitle = trim($displayTitle) !== '' ? trim($displayTitle) : $title;
        if ($title === '') {
            $add('title_missing', 'fail', "{$kind} “{$label}” has no title", 'Set title or SEO title', $href);
        } else {
            $len = mb_strlen($displayTitle);
            $fieldLen = mb_strlen($title);
            if ($len > 60) {
                $fix = $title;
                if ($displayTitle !== $title && $fieldLen <= 60) {
                    $fix = 'Site title suffix pushes the <title> over 60 characters';
                }
                $add('title_long', 'warn', "{$kind} “{$label}” title is {$len} chars (aim ≤60)", $fix, $href);
            }
            $key = mb_strtolower($displayTitle);
            if (isset($titles[$key])) {
                $add('title_dupe', 'fail', "Duplicate title: “{$displayTitle}” ({$titles[$key]} & {$label})", '', $href);
            }
            $titles[$key] = $label;
        }
        if ($desc === '') {
            $add('desc_missing', 'warn', "{$kind} “{$label}” has no meta description", '', $href);
        } else {
            $len = mb_strlen($desc);
            if ($len > 160) {
                $add('desc_long', 'warn', "{$kind} “{$label}” description is {$len} chars (aim ≤160)", '', $href);
            } elseif ($len < 50) {
                $add('desc_short', 'info', "{$kind} “{$label}” description is short ({$len} chars)", '', $href);
            }
            $dkey = mb_strtolower($desc);
            if (isset($descs[$dkey])) {
                $add('desc_dupe', 'warn', "Duplicate description on “{$label}” (also {$descs[$dkey]})", '', $href);
            }
            $descs[$dkey] = $label;
        }
        if ($img === '' && trim((string)($seo['default_og_image'] ?? '')) === '') {
            $add('image_missing', 'warn', "{$kind} “{$label}” has no featured image (and no site default)", 'Set a default social image once in SEO settings', $href);
        }
    }

    /**
     * Cheap, single-document health check — title/description length + presence,
     * social image, and (for full HTML docs) whether the [[seo]] slot is actually
     * emitting tags. Safe to run per-row in a list endpoint or in an editor.
     *
     * This intentionally skips the cross-page duplicate-title/description checks
     * that healthReport() does — those need the whole site loaded at once.
     *
     * @param array $doc normalized doc from forPage()/forPost()/forSimple()
     * @param string|null $rawContent full page source (pass for pages to also check the SEO slot)
     */
    public static function quickHealth(array $doc, ?string $rawContent = null): array {
        $issues = [];
        $add = static function (string $field, string $severity, string $message) use (&$issues): void {
            $issues[] = ['field' => $field, 'severity' => $severity, 'message' => $message];
        };

        $title = trim((string)($doc['title'] ?? ''));
        if ($title === '') {
            $add('title', 'warn', 'No title set');
        } elseif (mb_strlen($title) > 60) {
            $add('title', 'warn', 'Title is ' . mb_strlen($title) . ' chars (aim ≤60)');
        }

        $desc = trim((string)($doc['description'] ?? ''));
        if ($desc === '') {
            $add('desc', 'warn', 'No meta description');
        } elseif (mb_strlen($desc) > 160) {
            $add('desc', 'warn', 'Description is ' . mb_strlen($desc) . ' chars (aim ≤160)');
        } elseif (mb_strlen($desc) < 50) {
            $add('desc', 'info', 'Description is short (' . mb_strlen($desc) . ' chars)');
        }

        if (trim((string)($doc['og_image'] ?? '')) === '') {
            $add('image', 'info', 'No social image (site default may cover this)');
        }

        if ($rawContent !== null && class_exists('PageRepo')) {
            $meta = PageRepo::extractMeta($rawContent);
            $mode = self::normalizeHeadMode((string)($meta['seo_head'] ?? 'auto'));
            $body = PageRepo::stripMeta($rawContent);
            $looksFullDoc = (bool)preg_match('/<(!DOCTYPE|html|head)[\s>]/i', $body);
            if ($looksFullDoc && !self::htmlHasSlot($rawContent) && ($mode === 'off' || $mode === 'slot')) {
                $add('slot', 'warn', 'SEO tags are off for this page');
            }
        }

        $ok = true;
        foreach ($issues as $i) {
            if ($i['severity'] !== 'info') {
                $ok = false;
                break;
            }
        }
        return ['ok' => $ok, 'issues' => $issues];
    }

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
        if (empty($seo['robots_index'])) {
            $add('site_noindex', 'fail', 'Sitewide indexing is off', 'Turn on “Allow search engines to index”', 'index.php?section=settings&sub=seo');
        }
        $siteDesc = trim((string)($site['description'] ?? ''));
        if ($siteDesc === '') {
            $add('site_desc', 'warn', 'Site description is empty', 'Set it under Settings → General', 'index.php?section=settings&sub=general');
        } elseif (mb_strlen($siteDesc) > 160) {
            $add('site_desc_long', 'warn', 'Site description is ' . mb_strlen($siteDesc) . ' chars (aim ≤160)', '', 'index.php?section=settings&sub=general');
        }
        if (trim((string)($seo['favicon'] ?? '')) === '') {
            $add('favicon', 'warn', 'No favicon set', 'Add one under How links look', '');
        }
        if (trim((string)($seo['default_og_image'] ?? '')) === '') {
            $add('default_og', 'warn', 'No default social / OG image', 'Upload a 1200×630 image under How links look', '');
        }
        if (empty($seo['sitemap_enabled'])) {
            $add('sitemap', 'warn', 'Sitemap is disabled', 'Enable sitemap.xml under Forma already does this', '');
        }
        if (empty($seo['llms_enabled'])) {
            $add('llms', 'info', 'llms.txt is off', 'Turn it back on under Advanced — robots, sitemap, titles', 'index.php?section=settings&sub=seo');
        } elseif (empty($seo['llms_auto']) && trim((string)($seo['llms_manual'] ?? '')) === '') {
            $add('llms_empty', 'warn', 'llms.txt auto is off and the manual file is empty', 'Paste a file under Advanced, or turn Auto-generate llms.txt back on', 'index.php?section=settings&sub=seo');
        }
        $faviconPath = trim((string)($seo['favicon'] ?? ''));
        if ($faviconPath !== '' && !self::uploadPathExists($faviconPath)) {
            $add('favicon_missing', 'fail', 'Favicon path does not exist on disk', $faviconPath, '');
        }
        $ogPath = trim((string)($seo['default_og_image'] ?? ''));
        if ($ogPath !== '' && !self::uploadPathExists($ogPath)) {
            $add('og_missing', 'fail', 'Default social image path does not exist on disk', $ogPath, '');
        }

        $schemaType = (string)($seo['schema_type'] ?? 'person');
        if (in_array($schemaType, ['local_business', 'organization', 'person'], true)
            && trim((string)($seo['organization_logo'] ?? '')) === ''
        ) {
            $add('schema_logo', 'info', 'No schema logo / headshot', 'Used in JSON-LD and Google’s knowledge panel', '');
        }
        if ($schemaType === 'local_business') {
            if (trim((string)($seo['schema_phone'] ?? '')) === '') {
                $add('schema_phone', 'warn', 'Business phone is missing', 'Add it under This business', '');
            }
            if (trim((string)($seo['schema_address'] ?? '')) === '' && trim((string)($seo['schema_city'] ?? '')) === '') {
                $add('schema_address', 'warn', 'Business address is missing', 'Add street and city under This business', '');
            }
            $hasMaps = trim((string)($seo['gbp_url'] ?? '')) !== ''
                || trim((string)($seo['place_id'] ?? '')) !== ''
                || trim((string)($seo['maps_embed_url'] ?? '')) !== '';
            if (!$hasMaps) {
                $add('schema_gbp', 'warn', 'No Google Maps link', 'Paste the Share link under Google', '');
            }
        }
        if (trim((string)($seo['google_site_verification'] ?? '')) === '') {
            $add('gsc', 'info', 'Search Console is not connected', 'Paste the HTML tag from Google Search Console under Google', '');
        }
        if (trim((string)($seo['google_analytics'] ?? '')) === '') {
            $add('analytics', 'info', 'No Analytics / Tag Manager ID', 'Paste a G- or GTM- ID under Google', '');
        }

        $slotOff = [];
        $slotAuto = 0;
        foreach (PageRepo::list() as $p) {
            $fn = (string)($p['filename'] ?? '');
            $full = PageRepo::get($fn);
            if (!$full) {
                continue;
            }
            $raw = (string)($full['content'] ?? '');
            $body = PageRepo::stripMeta($raw);
            if (!preg_match('/<(!DOCTYPE|html|head)[\s>]/i', $body)) {
                continue;
            }
            $meta = PageRepo::extractMeta($raw);
            $mode = self::normalizeHeadMode((string)($meta['seo_head'] ?? 'auto'));
            if (self::htmlHasSlot($raw)) {
                continue;
            }
            if ($mode === 'off' || $mode === 'slot') {
                $slotOff[] = $fn;
            } else {
                $slotAuto++;
            }
        }
        if ($slotOff) {
            $first = $slotOff[0];
            $add(
                'seo_slot_off',
                'warn',
                'SEO slot off on: ' . implode(', ', $slotOff),
                'Insert the SEO chip in the page editor — Forma will not emit <head> tags on these templates',
                'index.php?section=pages&file=' . rawurlencode($first)
            );
        }
        if ($slotAuto > 0) {
            $add(
                'seo_slot_auto',
                'info',
                $slotAuto . ' HTML page(s) still use automatic SEO injection',
                'Add an SEO chip after <head> if you want to move or turn off those tags. Until then Forma still injects them.',
                'index.php?section=pages'
            );
        }

        $titles = [];
        $descs = [];
        $skip = self::TEMPLATE_FILENAMES;

        foreach (PageRepo::list() as $p) {
            $fn = $p['filename'] ?? '';
            if (str_starts_with($fn, '_') || in_array($fn, $skip, true)) {
                continue;
            }
            $full = PageRepo::get($fn);
            if (!$full) {
                continue;
            }
            $doc = self::forPage($full);
            self::auditDocument(
                $add, $titles, $descs, 'Page', $fn,
                'index.php?section=pages&file=' . rawurlencode($fn),
                trim((string)($doc['title'] ?? '')),
                trim((string)($doc['display_title'] ?? '')),
                trim((string)($doc['description'] ?? '')),
                trim((string)($doc['og_image'] ?? '')),
                $seo,
                (string)($doc['robots'] ?? '')
            );
        }

        foreach (BlogRepo::list(false) as $post) {
            $full = BlogRepo::get($post['filename']);
            if (!$full) {
                continue;
            }
            $pub = !empty($full['published_at']) && (int)$full['published_at'] <= time();
            if (!$pub) {
                continue;
            }
            $doc = self::forPost($full);
            $label = $post['slug'] ?: $post['filename'];
            self::auditDocument(
                $add, $titles, $descs, 'Post', $label,
                'index.php?section=blog&file=' . rawurlencode($post['filename']),
                trim((string)($doc['title'] ?? '')),
                trim((string)($doc['display_title'] ?? '')),
                trim((string)($doc['description'] ?? '')),
                trim((string)($doc['og_image'] ?? '')),
                $seo,
                (string)($doc['robots'] ?? '')
            );
        }

        if (class_exists('License') && License::isPodcastLicensed() && class_exists('PodcastRepo')) {
            foreach (PodcastRepo::list() as $ep) {
                if (empty($ep['published_at']) || (int)$ep['published_at'] > time()) {
                    continue;
                }
                $id = (string)($ep['episode_id'] ?? '');
                $doc = self::forSimple(
                    '/podcast/' . $id,
                    (string)($ep['title'] ?? ''),
                    (string)($ep['description'] ?? ''),
                    (string)($ep['episode_art'] ?? '')
                );
                self::auditDocument(
                    $add, $titles, $descs, 'Episode', $id !== '' ? $id : 'episode',
                    'index.php?section=podcast&file=' . rawurlencode($id),
                    trim((string)($doc['title'] ?? '')),
                    trim((string)($doc['display_title'] ?? '')),
                    trim((string)($doc['description'] ?? '')),
                    trim((string)($doc['og_image'] ?? '')),
                    $seo
                );
            }
        }

        $rank = ['fail' => 0, 'warn' => 1, 'info' => 2];
        usort($issues, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['level'] ?? ''] ?? 9) <=> ($rank[$b['level'] ?? ''] ?? 9);
        });

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

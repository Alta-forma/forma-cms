<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$section = $_POST['section'] ?? '';
$allowed = ['site', 'blog', 'podcast', 'cache', 'security', 'seo'];
if (!in_array($section, $allowed, true)) {
    echo fx_toast_oob('Unknown section');
    exit;
}

$current = Database::get()->getSetting($section);

if ($section === 'site') {
    foreach (['title', 'description', 'url', 'default_author', 'language', 'timezone'] as $k) {
        if (isset($_POST[$k])) {
            $current[$k] = trim((string)$_POST[$k]);
        }
    }
} elseif ($section === 'blog') {
    $current['feed_posts'] = (int)($_POST['feed_posts'] ?? $current['feed_posts'] ?? 20);
    $current['excerpt_length'] = (int)($_POST['excerpt_length'] ?? $current['excerpt_length'] ?? 250);
    $current['blog_feed_rss'] = !empty($_POST['blog_feed_rss']);
    $current['blog_feed_json'] = !empty($_POST['blog_feed_json']);
    $current['auto_regen_feed'] = !empty($_POST['auto_regen_feed']);
    if (isset($_POST['default_author'])) {
        $site = Database::get()->getSetting('site');
        $site['default_author'] = trim((string)$_POST['default_author']);
        Database::get()->saveSetting('site', $site);
    }
} elseif ($section === 'podcast') {
    foreach (['title', 'description', 'author', 'email', 'category', 'subcategory', 'image', 'explicit', 'language'] as $k) {
        if (isset($_POST[$k])) {
            $current[$k] = trim((string)$_POST[$k]);
        }
    }
    $current['podcast_feed_rss'] = !empty($_POST['podcast_feed_rss']);
    $current['auto_regen_feed'] = !empty($_POST['auto_regen_feed']);
} elseif ($section === 'cache') {
    $current['enabled'] = !empty($_POST['enabled']);
    $current['ttl'] = max(60, (int)($_POST['ttl'] ?? 3600));
    $current['static_fallback'] = !empty($_POST['static_fallback']);
} elseif ($section === 'seo') {
    foreach ([
        'robots_extra', 'robots_manual', 'sitemap_manual', 'llms_manual', 'noindex_paths',
        'title_separator', 'default_og_image', 'favicon', 'apple_touch_icon',
        'twitter_site', 'twitter_card', 'google_site_verification', 'bing_site_verification',
        'google_analytics',
        'organization_name', 'organization_logo', 'schema_type', 'same_as',
        'schema_email', 'schema_phone', 'schema_address', 'schema_city', 'schema_region',
        'schema_postal', 'schema_country', 'schema_hours', 'schema_price_range', 'place_id', 'gbp_url', 'review_url', 'maps_embed_url',
    ] as $k) {
        if (isset($_POST[$k])) {
            $current[$k] = in_array($k, ['robots_manual', 'sitemap_manual', 'llms_manual', 'same_as', 'schema_hours'], true)
                ? (string)$_POST[$k]
                : trim((string)$_POST[$k]);
        }
    }
    foreach ([
        'robots_auto', 'robots_index', 'robots_follow',
        'sitemap_auto', 'sitemap_enabled', 'sitemap_include_pages',
        'sitemap_include_posts', 'sitemap_include_podcast', 'sitemap_include_images',
        'llms_auto', 'llms_enabled',
        'title_suffix', 'json_ld_website',
    ] as $k) {
        $current[$k] = !empty($_POST[$k]);
    }
    // Keep legacy flag in sync for older code paths
    $current['json_ld_organization'] = in_array($current['schema_type'] ?? '', ['organization', 'local_business', 'person'], true);
    $current = Seo::normalizeSettings($current);
}

Database::get()->saveSetting($section, $current);
if ($section === 'blog') {
    Feed::maybeRegenerateBlog();
}
if ($section === 'podcast') {
    Feed::maybeRegeneratePodcast();
}
if ($section === 'seo' || $section === 'site') {
    Database::get()->flushCache();
}

if ($section === 'cache') {
    // Setting is already persisted above, so StaticFallback::enabled() reads the fresh value.
    Htaccess::ensureStaticFallbackRules();
    Htaccess::ensureFastCgiSafeFrontController();
    if ($current['static_fallback']) {
        StaticFallback::enable();
    } else {
        StaticFallback::disable();
    }
}
if (in_array($section, ['site', 'seo', 'blog', 'podcast'], true)) {
    StaticFallback::republishIfEnabled();
}

if ($section === 'seo') {
    // Reload full SEO panel so health score refreshes
    ob_start();
    require ADMIN_DIR . '/partials/settings-seo.php';
    echo ob_get_clean();
}
echo fx_toast_oob('Settings saved');

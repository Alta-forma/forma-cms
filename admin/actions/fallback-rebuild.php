<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

// Rebuilding the HTML cache turns it on if needed.
$cache = Database::get()->getSetting('cache');
if (empty($cache['static_fallback'])) {
    $cache['static_fallback'] = true;
    Database::get()->saveSetting('cache', $cache);
}
Htaccess::ensureStaticFallbackRules();
Htaccess::ensureFastCgiSafeFrontController();
$counts = StaticFallback::enable();

$parts = [];
$parts[] = (int)($counts['pages'] ?? 0) . ' page' . ((int)($counts['pages'] ?? 0) === 1 ? '' : 's');
$parts[] = (int)($counts['posts'] ?? 0) . ' post' . ((int)($counts['posts'] ?? 0) === 1 ? '' : 's');
if (!empty($counts['podcast_episodes'])) {
    $parts[] = (int)$counts['podcast_episodes'] . ' episode' . ((int)$counts['podcast_episodes'] === 1 ? '' : 's');
}
$search = $counts['search'] ?? [];
$searchTotal = (int)($search['page'] ?? 0) + (int)($search['post'] ?? 0) + (int)($search['podcast'] ?? 0);

$message = $counts['enabled']
    ? 'Published ' . implode(', ', $parts) . ' · indexed ' . $searchTotal . ' for search'
    : 'Could not publish — check fallback/ is writable';

echo fx_toast_oob($message);

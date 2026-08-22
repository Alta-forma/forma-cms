<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';
$kind = $_POST['kind'] ?? 'blog';
if ($kind === 'podcast') {
    $ok = Feed::writePodcastRss();
} else {
    $ok = Feed::writeBlogRss() && Feed::writeBlogJson();
}
echo fx_toast_oob($ok ? 'Feeds regenerated' : 'Feed write failed');

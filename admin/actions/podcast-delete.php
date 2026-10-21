<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require ADMIN_DIR . '/partials/_helpers.php';

try {
    PodcastRepo::delete($_POST['episode_id'] ?? '');
    require ADMIN_DIR . '/partials/podcast.php';
    echo fx_toast_oob('Deleted');
} catch (Throwable $e) {
    http_response_code(400);
    echo '<p style="padding:2rem;color:var(--error)">' . htmlspecialchars($e->getMessage()) . '</p>';
}

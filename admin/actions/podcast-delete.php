<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
PodcastRepo::delete($_POST['episode_id'] ?? '');
require ADMIN_DIR . '/partials/_helpers.php';
require ADMIN_DIR . '/partials/podcast.php';
echo fx_toast_oob('Deleted');

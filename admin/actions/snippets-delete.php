<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
SnippetRepo::delete($_POST['filename'] ?? '');
require ADMIN_DIR . '/partials/_helpers.php';
require ADMIN_DIR . '/partials/snippets.php';
echo fx_toast_oob('Deleted');

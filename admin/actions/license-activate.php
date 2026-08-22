<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
$result = License::activate($_POST['license_key'] ?? '');
if (!empty($result['success'])) {
    // Reload so Podcast appears in top nav / settings once licensed
    header('HX-Refresh: true');
}
require ADMIN_DIR . '/partials/_helpers.php';
require ADMIN_DIR . '/partials/settings-license.php';
echo fx_toast_oob($result['message'] ?? 'Done');

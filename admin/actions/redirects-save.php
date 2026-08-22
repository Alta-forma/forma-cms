<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$action = (string)($_POST['action'] ?? 'save');
$message = 'Saved';

try {
    if ($action === 'delete') {
        RedirectRepo::delete((int)($_POST['id'] ?? 0));
        $message = 'Redirect deleted';
    } else {
        RedirectRepo::save([
            'id' => (int)($_POST['id'] ?? 0),
            'from_path' => $_POST['from_path'] ?? '',
            'to_url' => $_POST['to_url'] ?? '',
            'status' => (int)($_POST['status'] ?? 301),
            'enabled' => !empty($_POST['enabled']),
            'note' => $_POST['note'] ?? '',
        ]);
        $message = 'Redirect saved';
    }
    Database::get()->flushCache();
} catch (Throwable $e) {
    $message = $e->getMessage();
}

// Reload SEO panel (includes redirects + health)
ob_start();
require ADMIN_DIR . '/partials/settings-seo.php';
echo ob_get_clean();
echo fx_toast_oob($message);

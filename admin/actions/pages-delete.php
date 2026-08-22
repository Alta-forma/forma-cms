<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

try {
    PageRepo::delete($_POST['filename'] ?? '');
    require_once ADMIN_DIR . '/partials/_helpers.php';
    require ADMIN_DIR . '/partials/pages.php';
    echo fx_toast_oob('Deleted');
} catch (Throwable $e) {
    http_response_code(400);
    echo '<div class="section-container"><p style="padding:2rem;color:var(--error)">'
        . htmlspecialchars($e->getMessage()) . '</p></div>';
}

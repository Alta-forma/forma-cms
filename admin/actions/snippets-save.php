<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

try {
    $row = SnippetRepo::save($_POST['filename'] ?? '', $_POST['shortcode'] ?? '', $_POST['content'] ?? '');
    $_GET['file'] = $row['filename'] ?? '';
    require ADMIN_DIR . '/partials/_helpers.php';
    require ADMIN_DIR . '/partials/snippets-list.php';
    echo fx_toast_oob('Snippet saved');
} catch (Throwable $e) {
    http_response_code(400);
    echo '<div id="snippets-list"><div class="file-item" style="color:var(--error)">' . htmlspecialchars($e->getMessage()) . '</div></div>';
}

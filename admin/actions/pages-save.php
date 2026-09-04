<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

try {
    $filename = $_POST['filename'] ?? '';
    $content = $_POST['content'] ?? '';
    $contentType = $_POST['content_type'] ?? 'html';
    $slug = $_POST['slug'] ?? null;
    $seoMeta = [];
    foreach (Seo::PAGE_META_KEYS as $k) {
        if (array_key_exists($k, $_POST)) {
            $seoMeta[$k] = trim((string)$_POST[$k]);
        }
    }
    if (array_key_exists('featured_image', $seoMeta)) {
        $seoMeta['og_image'] = $seoMeta['featured_image'];
    }
    $row = PageRepo::save($filename, $content, $contentType, $slug, $seoMeta);
    StaticFallback::refreshSavedPage($row);
    $_GET['file'] = $row['filename'] ?? $filename;
    require ADMIN_DIR . '/partials/_helpers.php';
    require ADMIN_DIR . '/partials/pages-list.php';
    $warns = $row['_warnings'] ?? [];
    $msg = 'Page saved';
    if ($warns) {
        $msg .= ' · ' . Seo::warningMessage((string)$warns[0]);
    }
    echo fx_toast_oob($msg);
    // Also refresh editor active state via oob optional — list is enough
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div class="file-list-content" id="pages-list"><div class="file-item" style="color:var(--error)">'
        . htmlspecialchars($e->getMessage()) . '</div></div>';
}

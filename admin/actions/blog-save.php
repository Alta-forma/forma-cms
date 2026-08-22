<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

try {
    $seo = [];
    foreach (Seo::PAGE_META_KEYS as $k) {
        if (array_key_exists($k, $_POST)) {
            $seo[$k] = trim((string)$_POST[$k]);
        }
    }
    if (array_key_exists('featured_image', $seo)) {
        $seo['og_image'] = $seo['featured_image'];
    }
    $row = BlogRepo::save([
        'filename'    => $_POST['filename'] ?? '',
        'title'       => $_POST['title'] ?? '',
        'slug'        => $_POST['slug'] ?? '',
        'body'        => $_POST['body'] ?? '',
        'description' => $_POST['description'] ?? '',
        'author'      => $_POST['author'] ?? '',
        'categories'  => $_POST['categories'] ?? '',
        'tags'        => $_POST['tags'] ?? '',
        'date'        => $_POST['date'] ?? '',
        'seo'         => $seo,
    ]);
    $_GET['file'] = $row['filename'] ?? '';
    require ADMIN_DIR . '/partials/_helpers.php';
    require ADMIN_DIR . '/partials/blog-list.php';
    echo fx_toast_oob('Post saved · feed updated');
} catch (Throwable $e) {
    http_response_code(400);
    echo '<div class="file-list-content" id="blog-list"><div class="file-item" style="color:var(--error)">'
        . htmlspecialchars($e->getMessage()) . '</div></div>';
}

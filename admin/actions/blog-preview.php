<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$seo = [];
foreach (Seo::PAGE_META_KEYS as $k) {
    if (array_key_exists($k, $_POST)) {
        $seo[$k] = trim((string)$_POST[$k]);
    }
}
if (array_key_exists('featured_image', $seo)) {
    $seo['og_image'] = $seo['featured_image'];
}

$row = [
    'filename'     => (string)($_POST['filename'] ?? 'preview'),
    'slug'         => (string)($_POST['slug'] ?? 'preview'),
    'title'        => (string)($_POST['title'] ?? 'Preview'),
    'body'         => (string)($_POST['body'] ?? ''),
    'description'  => (string)($_POST['description'] ?? ''),
    'author'       => (string)($_POST['author'] ?? ''),
    'categories'   => json_encode(array_values(array_filter(array_map('trim', explode(',', (string)($_POST['categories'] ?? '')))))),
    'tags'         => json_encode(array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? '')))))),
    'published_at' => strtotime((string)($_POST['date'] ?? 'now')) ?: time(),
    'seo_json'     => json_encode($seo, JSON_UNESCAPED_SLASHES),
];

$html = '';
try {
    $html = Render::renderBlogPost($row);
} catch (Throwable $e) {
    $title = htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8');
    $body = Render::parsedown()->text($row['body']);
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $title . '</title>'
        . '<style>body{font-family:Georgia,serif;max-width:40rem;margin:2rem auto;padding:0 1.25rem;line-height:1.6;color:#222}'
        . 'h1{font-size:2rem;line-height:1.15}img{max-width:100%}</style></head><body>'
        . '<article><h1>' . $title . '</h1>' . $body . '</article></body></html>';
}

$site = Database::get()->getSetting('site');
$origin = rtrim((string)($site['url'] ?? ''), '/');
if ($origin === '') {
    $origin = '/';
} else {
    $origin .= '/';
}
if (!preg_match('/<base\s/i', $html)) {
    $html = preg_replace(
        '/<head([^>]*)>/i',
        '<head$1><base href="' . htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') . '">',
        $html,
        1
    ) ?: $html;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Forma-Preview: 1');
echo $html;

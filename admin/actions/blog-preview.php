<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$row = [
    'filename'     => $_POST['filename'] ?? 'preview',
    'slug'         => $_POST['slug'] ?? 'preview',
    'title'        => $_POST['title'] ?? 'Preview',
    'body'         => $_POST['body'] ?? '',
    'description'  => $_POST['description'] ?? '',
    'author'       => $_POST['author'] ?? '',
    'categories'   => json_encode(array_filter(array_map('trim', explode(',', $_POST['categories'] ?? '')))),
    'tags'         => json_encode(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? '')))),
    'published_at' => strtotime($_POST['date'] ?? 'now') ?: time(),
];

$html = Render::renderBlogPost($row);
// Open as full document response for iframe-less preview window via htmx swap into target — wrap
header('Content-Type: text/html; charset=UTF-8');
echo '<div style="position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:3000;display:flex;align-items:center;justify-content:center;padding:2rem" onclick="this.remove()">'
    . '<div style="background:#fff;color:#111;max-width:48rem;width:100%;max-height:90vh;overflow:auto;border-radius:8px;position:relative" onclick="event.stopPropagation()">'
    . '<button type="button" onclick="this.closest(\'div[style*=fixed]\').remove()" style="position:sticky;top:0;float:right;margin:8px;padding:6px 12px;cursor:pointer">Close</button>'
    . '<iframe style="width:100%;height:80vh;border:0" srcdoc="' . htmlspecialchars($html, ENT_QUOTES) . '"></iframe>'
    . '</div></div>';
// Put preview into body via oob so it overlays
echo '<div id="blog-preview" hx-swap-oob="true"></div>';

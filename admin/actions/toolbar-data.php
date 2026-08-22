<?php
/**
 * JSON lists for the editor toolbar (pages, posts, uploads, snippets).
 */
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(true);
header('Content-Type: application/json; charset=UTF-8');

$type = $_GET['type'] ?? '';

try {
    switch ($type) {
        case 'pages':
            $out = [];
            foreach (PageRepo::list() as $p) {
                $row = PageRepo::get($p['filename']);
                $meta = $row ? PageRepo::extractMeta($row['content']) : [];
                $slug = $meta['slug'] ?? ('/' . $p['filename']);
                if ($slug !== '/' && !str_starts_with($slug, '/')) {
                    $slug = '/' . $slug;
                }
                $out[] = [
                    'filename' => $p['filename'],
                    'slug'     => $slug,
                    'label'    => $p['filename'] . ($slug ? " ($slug)" : ''),
                    'path'     => $slug === '//' ? '/' : $slug,
                ];
            }
            echo json_encode(['items' => $out]);
            break;

        case 'posts':
            $out = [];
            foreach (BlogRepo::list(false) as $p) {
                $out[] = [
                    'filename' => $p['filename'],
                    'title'    => $p['title'] ?: $p['filename'],
                    'path'     => '/blog/' . ($p['slug'] ?: $p['filename']),
                ];
            }
            echo json_encode(['items' => $out]);
            break;

        case 'uploads':
            $out = [];
            foreach (MediaRepo::list() as $f) {
                $out[] = [
                    'filename' => $f['filename'],
                    'path'     => $f['url'],
                    'ext'      => $f['ext'],
                ];
            }
            echo json_encode(['items' => $out]);
            break;

        case 'snippets':
            $out = [];
            foreach (SnippetRepo::list() as $s) {
                $out[] = [
                    'filename'  => $s['filename'],
                    'shortcode' => $s['shortcode'],
                    'insert'    => '[[' . $s['shortcode'] . ']]',
                ];
            }
            echo json_encode(['items' => $out]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown type']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

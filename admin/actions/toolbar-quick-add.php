<?php
/**
 * Quick-create page / snippet / upload from editor toolbar modal.
 * Returns JSON: { success, insert, item }
 */
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(true);
header('Content-Type: application/json; charset=UTF-8');

$kind = $_POST['kind'] ?? '';

try {
    if ($kind === 'page') {
        $filename = PageRepo::sanitizeFilename($_POST['filename'] ?? '');
        $title = trim($_POST['title'] ?? $filename);
        $slug = trim($_POST['slug'] ?? '') ?: ('/' . $filename);
        if ($filename === '') {
            throw new InvalidArgumentException('Filename required');
        }
        $content = "<!--META\nslug: {$slug}\ntitle: {$title}\n-->\n<h1>" . htmlspecialchars($title) . "</h1>\n<p>New page.</p>\n";
        $row = PageRepo::save($filename, $content, 'html', $slug);
        $meta = PageRepo::extractMeta($row['content'] ?? $content);
        $path = $meta['slug'] ?? $slug;
        if ($path !== '/' && !str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        echo json_encode(['success' => true, 'insert' => $path, 'item' => ['filename' => $filename, 'path' => $path]]);
        exit;
    }

    if ($kind === 'snippet') {
        $filename = PageRepo::sanitizeFilename($_POST['filename'] ?? '');
        $shortcode = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['shortcode'] ?? '') ?? '';
        $body = $_POST['content'] ?? '<p>New snippet</p>';
        if ($filename === '' || $shortcode === '') {
            throw new InvalidArgumentException('Filename and shortcode required');
        }
        SnippetRepo::save($filename, $shortcode, $body);
        $insert = '[[' . $shortcode . ']]';
        echo json_encode(['success' => true, 'insert' => $insert, 'item' => ['filename' => $filename, 'shortcode' => $shortcode]]);
        exit;
    }

    if ($kind === 'upload') {
        $saved = MediaRepo::saveUpload($_FILES['file'] ?? []);
        echo json_encode(['success' => true, 'insert' => $saved['url'], 'item' => $saved, 'filename' => $saved['filename']]);
        exit;
    }

    if ($kind === 'post') {
        $filename = PageRepo::sanitizeFilename($_POST['filename'] ?? '');
        $title = trim($_POST['title'] ?? $filename);
        if ($filename === '' || $title === '') {
            throw new InvalidArgumentException('Filename and title required');
        }
        $row = BlogRepo::save([
            'filename' => $filename,
            'title'    => $title,
            'slug'     => $_POST['slug'] ?? '',
            'body'     => $_POST['body'] ?? "## {$title}\n\n",
            'date'     => date('Y-m-d'),
            'author'   => Database::get()->getSetting('site')['default_author'] ?? 'Admin',
        ]);
        $path = '/blog/' . ($row['slug'] ?? $filename);
        echo json_encode(['success' => true, 'insert' => $path, 'item' => $row]);
        exit;
    }

    throw new InvalidArgumentException('Unknown kind');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

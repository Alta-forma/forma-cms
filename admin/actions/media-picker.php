<?php
/**
 * Media picker API for admin image/audio fields.
 * GET  ?type=image|audio|all  → { files: [...] }
 * POST multipart file=…       → { success, path, url, filename }
 */
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(true);
header('Content-Type: application/json; charset=UTF-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $type = $_GET['type'] ?? 'image';
        if (!in_array($type, ['image', 'audio', 'any', 'all'], true)) {
            $type = 'image';
        }
        if ($type === 'all') {
            $type = 'any';
        }
        $files = [];
        foreach (MediaRepo::list() as $f) {
            $ext = $f['ext'] ?? '';
            if ($type === 'image' && !(MediaRepo::isImageExt($ext) || $ext === 'ico')) {
                continue;
            }
            if ($type === 'audio' && !MediaRepo::isAudioExt($ext)) {
                continue;
            }
            $files[] = [
                'filename' => $f['filename'],
                'url'      => $f['url'],
                'path'     => forma_uploads_web_path($f['filename']),
                'ext'      => $ext,
                'size'     => $f['size'],
                'mtime'    => $f['mtime'],
                'is_image' => MediaRepo::isImageExt($ext) || $ext === 'ico',
                'icon'     => MediaRepo::iconFor($ext),
            ];
        }
        echo json_encode(['success' => true, 'files' => $files]);
        exit;
    }

    if ($method === 'POST') {
        session_write_close();
        $saved = MediaRepo::saveUpload($_FILES['file'] ?? []);
        $path = forma_uploads_web_path($saved['filename']);
        echo json_encode([
            'success'  => true,
            'filename' => $saved['filename'],
            'url'      => $saved['url'],
            'path'     => $path,
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

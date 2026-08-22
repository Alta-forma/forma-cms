<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$old = basename((string)($_POST['filename'] ?? ''));
$new = trim((string)($_POST['new_filename'] ?? $old));
$content = array_key_exists('content', $_POST) ? (string)$_POST['content'] : null;

try {
    if ($old === '') {
        throw new RuntimeException('No file selected');
    }
    $file = MediaRepo::get($old);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $msg = 'Saved';
    if ($content !== null && MediaRepo::isTextExt($file['ext'])) {
        MediaRepo::writeText($old, $content);
        $msg = 'File saved';
    }

    if ($new !== '' && $new !== $old) {
        $new = MediaRepo::rename($old, $new);
        $msg = 'Renamed';
        $old = $new;
    }

    $_GET['file'] = $old;
    require ADMIN_DIR . '/partials/uploads.php';
    echo fx_toast_oob($msg);
} catch (Throwable $e) {
    http_response_code(400);
    echo fx_toast_oob($e->getMessage());
}

<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

try {
    $uploaded = [];
    $bag = $_FILES['file'] ?? $_FILES['files'] ?? null;
    if (!$bag) {
        throw new RuntimeException('No file uploaded');
    }

    // Normalize single vs multi
    if (is_array($bag['name'] ?? null)) {
        $count = count($bag['name']);
        for ($i = 0; $i < $count; $i++) {
            $one = [
                'name'     => $bag['name'][$i],
                'type'     => $bag['type'][$i] ?? '',
                'tmp_name' => $bag['tmp_name'][$i],
                'error'    => $bag['error'][$i],
                'size'     => $bag['size'][$i] ?? 0,
            ];
            if (($one['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $uploaded[] = MediaRepo::saveUpload($one);
        }
    } else {
        $uploaded[] = MediaRepo::saveUpload($bag);
    }

    if (!$uploaded) {
        throw new RuntimeException('No file uploaded');
    }

    $_GET['file'] = $uploaded[array_key_last($uploaded)]['filename'];
    require ADMIN_DIR . '/partials/uploads.php';
    $n = count($uploaded);
    echo fx_toast_oob($n === 1 ? 'Uploaded' : "Uploaded {$n} files");
} catch (Throwable $e) {
    http_response_code(400);
    echo '<p style="padding:2rem;color:var(--error)">' . htmlspecialchars($e->getMessage()) . '</p>';
    echo fx_toast_oob($e->getMessage());
}

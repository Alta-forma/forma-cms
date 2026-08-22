<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
require_once ROOT_DIR . '/lib/Importer.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

try {
    if (empty($_FILES['json_file']['tmp_name'])) {
        throw new RuntimeException('No file uploaded');
    }
    $raw = file_get_contents($_FILES['json_file']['tmp_name']);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON');
    }
    $stats = Importer::fromJson($data);
    echo fx_toast_oob(sprintf(
        'Imported %d pages, %d posts, %d snippets, %d episodes',
        $stats['pages'], $stats['posts'], $stats['snippets'], $stats['episodes']
    ));
} catch (Throwable $e) {
    echo fx_toast_oob('Import failed: ' . $e->getMessage());
}

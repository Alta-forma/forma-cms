<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

try {
    if (empty($_FILES['package']['tmp_name']) || !is_uploaded_file($_FILES['package']['tmp_name'])) {
        throw new RuntimeException('Choose a .zip site package to upload');
    }
    $name = (string)($_FILES['package']['name'] ?? '');
    if (!str_ends_with(strtolower($name), '.zip')) {
        throw new RuntimeException('Package must be a .zip file');
    }
    $replaceDb = !empty($_POST['replace_database']);
    $mergeUploads = !empty($_POST['merge_uploads']);

    $result = SitePackage::importZip($_FILES['package']['tmp_name'], $replaceDb, $mergeUploads);
    $stats = $result['stats'] ?? [];
    $bits = [];
    if (!empty($stats['database'])) {
        $bits[] = 'database replaced';
    }
    if (!empty($stats['json_fallback'])) {
        $bits[] = 'JSON content merged';
    }
    if (isset($stats['uploads'])) {
        $bits[] = (int)$stats['uploads'] . ' uploads';
    }
    if (!empty($stats['backup'])) {
        $bits[] = 'backup ' . $stats['backup'];
    }
    $msg = 'Imported' . ($bits ? ': ' . implode(', ', $bits) : '');
    echo fx_toast_oob($msg);
} catch (Throwable $e) {
    echo fx_toast_oob('Import failed: ' . $e->getMessage());
}

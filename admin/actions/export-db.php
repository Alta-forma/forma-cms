<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
$path = DB_FILE;
if (!is_file($path)) {
    http_response_code(404);
    echo 'Database not found';
    exit;
}
header('Content-Type: application/x-sqlite3');
header('Content-Disposition: attachment; filename="formax-' . date('Ymd') . '.db"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

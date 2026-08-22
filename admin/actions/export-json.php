<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$export = SitePackage::buildDataJson();
header('Content-Type: application/json; charset=UTF-8');
header('Content-Disposition: attachment; filename="formax-export-' . date('Ymd') . '.json"');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;

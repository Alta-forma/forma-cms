<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(true);
header('Content-Type: application/json; charset=UTF-8');
$checks = HostingCheck::run(Database::get());
echo json_encode(HostingCheck::summarize($checks));

<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$created = Agent::createToken($_POST['name'] ?? 'Agent', $_POST['scopes'] ?? []);
$_GET['new_token'] = $created['token'];
require ADMIN_DIR . '/partials/settings-agents.php';

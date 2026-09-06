<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$clientId = trim((string)($_POST['client_id'] ?? ''));
if ($clientId !== '') {
    AgentOAuth::revokeClient($clientId);
}
require ADMIN_DIR . '/partials/settings-agents.php';

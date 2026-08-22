<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
Agent::revokeToken((int)($_POST['id'] ?? 0));
require ADMIN_DIR . '/partials/settings-agents.php';

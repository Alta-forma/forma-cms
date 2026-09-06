<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$preset = (string)($_POST['preset'] ?? '');
$scopes = $_POST['scopes'] ?? [];
$name = $_POST['name'] ?? 'Agent';
if ($preset === 'site-editor') {
    $name = trim((string)$name) ?: 'Chatbot site editor';
    $scopes = ['content:read', 'content:write', 'media:write', 'site:write', 'rollback:write'];
}
$created = Agent::createToken((string)$name, is_array($scopes) ? $scopes : []);
$_GET['new_token'] = $created['token'];
require ADMIN_DIR . '/partials/settings-agents.php';

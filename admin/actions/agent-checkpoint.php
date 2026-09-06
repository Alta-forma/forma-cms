<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$action = (string)($_POST['checkpoint_action'] ?? '');
try {
    if ($action === 'restore') {
        $result = AgentCheckpoint::restore('Admin');
        $_GET['checkpoint_message'] = 'Site restored to the last known good point';
        if (!empty($result['warnings'])) {
            $_GET['checkpoint_message'] .= '. ' . implode(' ', $result['warnings']);
        }
    } elseif ($action === 'accept') {
        AgentCheckpoint::accept('Admin');
        $_GET['checkpoint_message'] = 'Current site marked good';
    } else {
        throw new InvalidArgumentException('Unknown rollback action');
    }
} catch (Throwable $e) {
    $_GET['checkpoint_error'] = $e->getMessage();
}

require ADMIN_DIR . '/partials/settings-access.php';

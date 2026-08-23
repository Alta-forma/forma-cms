<?php
/**
 * CMS updater — check is GET via the partial; apply/rollback are POST here.
 */
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$do = (string)($_POST['do'] ?? $_GET['do'] ?? '');
$result = null;

if ($do === 'apply') {
    $result = Updater::apply();
} elseif ($do === 'rollback') {
    $result = Updater::rollback();
}

if (is_array($result)) {
    echo fx_toast_oob((string)$result['message']);
}

$fxUpdateRefresh = ($do === 'apply' || $do === 'rollback');
require ADMIN_DIR . '/partials/settings-update.php';

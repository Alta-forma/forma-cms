<?php
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::startSession();
// Logout is a state change reachable by GET; require the session's CSRF token
// so a third-party page can't log the admin out with an <img src>.
$given = (string)($_GET['t'] ?? $_POST['csrf_token'] ?? '');
$expected = (string)($_SESSION['forma_csrf'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    header('Location: index.php');
    exit;
}
Auth::logout();
header('Location: login.php');
exit;

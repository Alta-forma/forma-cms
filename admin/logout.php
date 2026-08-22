<?php
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::logout();
header('Location: login.php');
exit;

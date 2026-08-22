<?php
/**
 * Forma – Path constants
 */
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__FILE__));
}
if (!defined('ADMIN_DIR')) {
    define('ADMIN_DIR', ROOT_DIR . '/admin');
}
if (!defined('UPLOADS_DIR')) {
    define('UPLOADS_DIR', ROOT_DIR . '/uploads');
}
if (!defined('FEEDS_DIR')) {
    define('FEEDS_DIR', ROOT_DIR . '/feeds');
}
if (!defined('DB_FILE')) {
    define('DB_FILE', ROOT_DIR . '/database/forma.db');
}
if (!defined('FALLBACK_DIR')) {
    define('FALLBACK_DIR', ROOT_DIR . '/fallback');
}

require_once ROOT_DIR . '/version.php';
require_once ROOT_DIR . '/lib/Urls.php';

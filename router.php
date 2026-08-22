<?php
/**
 * PHP built-in server router:
 *   php -S localhost:8787 router.php
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = __DIR__ . $uri;
if ($uri !== '/' && is_file($file)) {
    return false; // serve static
}
require __DIR__ . '/index.php';

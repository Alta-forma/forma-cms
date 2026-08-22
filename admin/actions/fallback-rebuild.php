<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

Htaccess::ensureStaticFallbackRules();
StaticFallback::writeStamp();
StaticFallback::refreshHomeIfStale(true);
$st = StaticFallback::status();
echo fx_toast_oob($st['home'] ? 'Last-good homepage written' : 'Could not write fallback/index.html — check folder permissions');

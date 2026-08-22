<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';
$content = (string)($_POST['content'] ?? Htaccess::defaultContent());
$ok = Htaccess::write($content);
if ($ok && !Htaccess::hasSeoPassthrough($content)) {
    Htaccess::ensureSeoPassthrough();
}
echo fx_toast_oob($ok ? '.htaccess written' : 'Write failed — check file permissions on the site root');

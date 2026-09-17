<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$action = (string)($_POST['action'] ?? '');
$message = '';

try {
    if ($action === 'remove_static_seo') {
        $result = Htaccess::removeStaticSeoFiles();
        if ($result['removed'] === [] && $result['errors'] === []) {
            $message = 'No leftover sitemap.xml / llms.txt. robots.txt placeholder kept.';
        } elseif ($result['ok']) {
            $message = 'Removed: ' . implode(', ', $result['removed']) . '. robots.txt placeholder kept.';
        } else {
            $parts = [];
            if ($result['removed']) {
                $parts[] = 'Removed: ' . implode(', ', $result['removed']);
            }
            $parts = array_merge($parts, $result['errors']);
            $message = implode(' · ', $parts);
        }
        // Also ensure rewrite routes so a re-upload can't stick forever
        Htaccess::ensureSeoPassthrough();
    } elseif ($action === 'ensure_robots_placeholder') {
        if (!Htaccess::ensureRobotsPlaceholder()) {
            throw new RuntimeException('Could not write robots.txt (permissions?)');
        }
        Htaccess::ensureSeoPassthrough();
        $message = 'Wrote robots.txt placeholder. Forma still generates the live file.';
    } elseif ($action === 'ensure_seo_routes') {
        if (!is_file(ROOT_DIR . '/.htaccess')) {
            if (!Htaccess::write(Htaccess::defaultContent())) {
                throw new RuntimeException('Could not create .htaccess');
            }
            $message = 'Created default .htaccess (includes SEO routes).';
        } elseif (Htaccess::ensureSeoPassthrough()) {
            $message = Htaccess::hasSeoPassthrough()
                ? 'SEO routes are in .htaccess (robots.txt + sitemap.xml → Forma).'
                : 'Could not confirm SEO routes — try Reset to default + Write.';
        } else {
            throw new RuntimeException('.htaccess not writable');
        }
    } else {
        throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
}

// Reload Server panel so status rows update; toast via OOB
ob_start();
require ADMIN_DIR . '/partials/settings-server.php';
$html = ob_get_clean();
echo $html;
echo fx_toast_oob($message !== '' ? $message : 'Done');

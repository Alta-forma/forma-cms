<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

$action = $_POST['action'] ?? '';
$message = '';

try {
    if ($action === 'ensure_directories') {
        $dirs = [dirname(DB_FILE), UPLOADS_DIR, FEEDS_DIR, FALLBACK_DIR];
        $made = [];
        foreach ($dirs as $d) {
            if (!is_dir($d)) {
                if (!@mkdir($d, 0755, true)) {
                    throw new RuntimeException('Could not create: ' . $d);
                }
                $made[] = basename($d);
            } elseif (!is_writable($d)) {
                @chmod($d, 0755);
            }
        }
        $message = $made
            ? 'Created: ' . implode(', ', $made)
            : 'Folders already existed; permissions adjusted if possible.';
    } elseif ($action === 'ensure_htaccess') {
        if (file_exists(ROOT_DIR . '/.htaccess')) {
            Htaccess::ensureSeoPassthrough();
            Htaccess::ensureStaticFallbackRules();
            Htaccess::ensureFastCgiSafeFrontController();
            $message = '.htaccess already exists; ensured SEO + fallback routes. Edit under Settings → Server if needed.';
        } else {
            if (!Htaccess::ensureDefault()) {
                throw new RuntimeException('Could not write .htaccess');
            }
            $message = 'Created default .htaccess';
        }
    } elseif ($action === 'remove_static_seo') {
        $result = Htaccess::removeStaticSeoFiles();
        Htaccess::ensureSeoPassthrough();
        if ($result['removed'] === [] && $result['errors'] === []) {
            $message = 'No static robots.txt / sitemap.xml found; SEO routes checked.';
        } elseif ($result['ok']) {
            $message = 'Removed: ' . implode(', ', $result['removed']) . '. SEO routes ensured.';
        } else {
            $message = implode(' · ', array_merge(
                $result['removed'] ? ['Removed: ' . implode(', ', $result['removed'])] : [],
                $result['errors']
            ));
        }
    } elseif ($action === 'ensure_seo_routes') {
        if (!is_file(ROOT_DIR . '/.htaccess')) {
            if (!Htaccess::write(Htaccess::defaultContent())) {
                throw new RuntimeException('Could not create .htaccess');
            }
            $message = 'Created default .htaccess with SEO routes.';
        } elseif (!Htaccess::ensureSeoPassthrough()) {
            throw new RuntimeException('.htaccess not writable');
        } else {
            $message = 'SEO routes ensured in .htaccess.';
        }
    } elseif ($action === 'ensure_static_fallback') {
        if (!is_file(ROOT_DIR . '/.htaccess')) {
            if (!Htaccess::write(Htaccess::defaultContent())) {
                throw new RuntimeException('Could not create .htaccess');
            }
        } else {
            Htaccess::ensureStaticFallbackRules();
            Htaccess::ensureFastCgiSafeFrontController();
        }
        if (!is_dir(FALLBACK_DIR) && !@mkdir(FALLBACK_DIR, 0755, true)) {
            throw new RuntimeException('Could not create fallback/');
        }
        StaticFallback::writeStamp();
        StaticFallback::refreshHomeIfStale(true);
        $message = 'Fallback rules ensured; last-good homepage written if possible.';
    } elseif ($action === 'chmod_database') {
        if (!file_exists(DB_FILE)) {
            throw new RuntimeException('database/forma.db does not exist yet.');
        }
        if (!@chmod(DB_FILE, 0640)) {
            throw new RuntimeException('chmod failed — try File Manager or SSH: chmod 640 database/forma.db');
        }
        $message = 'Set database file to chmod 0640';
    } else {
        throw new RuntimeException('Unknown action');
    }
} catch (Throwable $e) {
    $message = 'Fix failed: ' . $e->getMessage();
}

require ADMIN_DIR . '/partials/_helpers.php';
require ADMIN_DIR . '/partials/settings-hosting.php';
echo fx_toast_oob($message);

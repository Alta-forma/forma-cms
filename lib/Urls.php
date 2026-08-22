<?php
/**
 * Public URL helpers for subdirectory installs.
 */
function forma_site_base_path(): string {
    if (!defined('ROOT_DIR')) {
        return '';
    }
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $doc  = realpath($_SERVER['DOCUMENT_ROOT']);
        $root = realpath(ROOT_DIR);
        if ($doc !== false && $root !== false && str_starts_with($root, $doc) && strlen($root) > strlen($doc)) {
            $rel = trim(str_replace('\\', '/', substr($root, strlen($doc))), '/');
            if ($rel !== '') {
                return '/' . $rel;
            }
        }
    }
    $sn = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    if ($sn === '/admin' || preg_match('#^(.+)/admin$#', $sn, $m)) {
        return ($m[1] ?? '') === '' ? '' : $m[1];
    }
    if (str_contains($sn, '/api/v1')) {
        $sn = preg_replace('#/api/v1.*$#', '', $sn) ?? '';
        return $sn === '/' ? '' : $sn;
    }
    if ($sn === '' || $sn === '/') {
        return '';
    }
    return $sn;
}

function forma_uploads_web_prefix(): string {
    $b = rtrim(forma_site_base_path(), '/');
    return ($b === '' ? '' : $b) . '/uploads/';
}

function forma_admin_base_href(): string {
    $site = rtrim(forma_site_base_path(), '/');
    return ($site === '' ? '/admin/' : $site . '/admin/');
}

/** Site title from Settings → General, or the product name if unset. */
function forma_site_title(): string {
    try {
        $t = trim((string)(Database::get()->getSetting('site')['title'] ?? ''));
        if ($t !== '') {
            return $t;
        }
    } catch (Throwable $e) {
        // First-run / missing DB
    }
    return defined('FORMA_PRODUCT') ? FORMA_PRODUCT : 'Forma';
}

function forma_public_url(string $path = '/'): string {
    $config = Database::get()->getConfig();
    $base   = rtrim($config['site']['url'] ?? '', '/');
    if ($base === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base  = $proto . $host . rtrim(forma_site_base_path(), '/');
    }
    return $base . '/' . ltrim($path, '/');
}

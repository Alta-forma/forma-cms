#!/usr/bin/env php
<?php
/**
 * CLI: php tools/import-formalite.php /path/to/export.json
 *      php tools/import-formalite.php /path/to/forma.db
 */
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';
require_once ROOT_DIR . '/lib/Importer.php';

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage: php tools/import-formalite.php <export.json|forma.db>\n");
    exit(1);
}

try {
    if (str_ends_with(strtolower($path), '.json')) {
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON');
        }
        $stats = Importer::fromJson($data);
    } else {
        $stats = Importer::fromSqliteFile($path);
    }
    echo "Import OK\n";
    foreach ($stats as $k => $v) {
        echo "  $k: $v\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

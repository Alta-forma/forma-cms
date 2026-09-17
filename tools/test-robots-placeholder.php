<?php
/**
 * Unit-style test for the robots.txt placeholder (no live install required).
 *
 *   php tools/test-robots-placeholder.php
 */
$tmp = sys_get_temp_dir() . '/forma-robots-' . bin2hex(random_bytes(3));
if (!mkdir($tmp, 0777, true) && !is_dir($tmp)) {
    fwrite(STDERR, "Could not create $tmp\n");
    exit(1);
}
define('ROOT_DIR', $tmp);
require dirname(__DIR__) . '/lib/Htaccess.php';

$fail = 0;
function expect($ok, $msg) {
    global $fail;
    if ($ok) {
        echo "ok  $msg\n";
        return;
    }
    echo "FAIL  $msg\n";
    $fail++;
}

expect(!is_file($tmp . '/robots.txt'), 'starts with no robots.txt');
expect(Htaccess::ensureRobotsPlaceholder(), 'ensure writes placeholder');
expect(is_file($tmp . '/robots.txt'), 'placeholder exists');
$first = (string)file_get_contents($tmp . '/robots.txt');
expect(str_contains($first, 'DreamHost'), 'mentions why it exists');
expect(str_contains($first, "User-agent: *\nAllow: /"), 'safe fallback Allow');

expect(Htaccess::ensureRobotsPlaceholder(), 'second ensure is a no-op');
expect(file_get_contents($tmp . '/robots.txt') === $first, 'does not overwrite a present file');

file_put_contents($tmp . '/custom-robots.txt', "User-agent: *\nDisallow: /\n");
file_put_contents($tmp . '/robots.txt', "User-agent: *\nDisallow: /\n");
expect(Htaccess::ensureRobotsPlaceholder(), 'leaves a custom robots.txt alone');
expect(file_get_contents($tmp . '/robots.txt') === "User-agent: *\nDisallow: /\n", 'custom body kept');

file_put_contents($tmp . '/sitemap.xml', '<urlset/>');
file_put_contents($tmp . '/llms.txt', '# leftover');
$removed = Htaccess::removeStaticSeoFiles();
expect($removed['ok'] === true, 'remove leftover sitemap/llms succeeds');
expect($removed['removed'] === ['sitemap.xml', 'llms.txt'], 'removed only sitemap + llms: ' . json_encode($removed['removed']));
expect(!is_file($tmp . '/sitemap.xml') && !is_file($tmp . '/llms.txt'), 'sitemap/llms gone');
expect(is_file($tmp . '/robots.txt'), 'robots.txt still on disk after cleanup');

unlink($tmp . '/robots.txt');
$removed = Htaccess::removeStaticSeoFiles();
expect($removed['ok'] === true, 'cleanup with missing robots still ok');
expect(is_file($tmp . '/robots.txt'), 'cleanup rewrites the placeholder');

$files = glob($tmp . '/*') ?: [];
foreach ($files as $f) {
    unlink($f);
}
rmdir($tmp);

if ($fail) {
    fwrite(STDERR, "$fail failed\n");
    exit(1);
}
echo "all passed\n";

<?php
/**
 * Rebuild forma-social.png from the canonical Forma geometry.
 * Usage: php tools/render-brand-social.php [output.png]
 */
if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD is required.\n");
    exit(1);
}

$output = $argv[1] ?? dirname(__DIR__) . '/forma-social.png';
$iconOutput = $argv[2] ?? dirname(__DIR__) . '/forma-icon.png';
$width = 1200;
$height = 630;
$im = imagecreatetruecolor($width, $height);
imageantialias($im, true);

// Near-black background with a very subtle warm lift behind the mark.
for ($y = 0; $y < $height; $y++) {
    $t = $y / max(1, $height - 1);
    $v = (int)round(6 + (12 * $t));
    $color = imagecolorallocate($im, $v, $v, $v + 1);
    imageline($im, 0, $y, $width, $y, $color);
}
$line = imagecolorallocatealpha($im, 252, 190, 52, 112);
for ($x = -500; $x < 1500; $x += 62) {
    imageline($im, $x, 0, $x + 630, 630, $line);
}

$gold = imagecolorallocate($im, 252, 190, 52);
$white = imagecolorallocate($im, 245, 245, 247);
$muted = imagecolorallocate($im, 169, 169, 178);

// Exact canonical Forma paths, transformed into the social-card layout.
$ox = 92;
$oy = 147;
$scale = 0.78;
$poly = static function (array $points) use ($ox, $oy, $scale): array {
    $out = [];
    foreach ($points as [$x, $y]) {
        $out[] = (int)round($ox + ($x * $scale));
        $out[] = (int)round($oy + ($y * $scale));
    }
    return $out;
};
imagefilledpolygon($im, $poly([[0,0],[400,0],[320,80],[0,80],[0,0]]), $gold);
imagefilledpolygon($im, $poly([[0,100],[300,100],[220,180],[0,180],[0,100]]), $gold);
imagefilledpolygon($im, $poly([[0,200],[80,200],[0,280],[0,200]]), $gold);

$fontRegular = '/System/Library/Fonts/Supplemental/Arial.ttf';
$fontBold = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
if (!is_file($fontRegular) || !is_file($fontBold)) {
    fwrite(STDERR, "Arial system fonts were not found.\n");
    exit(1);
}
imagettftext($im, 92, 0, 470, 300, $white, $fontBold, 'Forma');
imagettftext($im, 29, 0, 476, 366, $muted, $fontRegular, 'The portable PHP CMS that doesn’t suck.');
imagettftext($im, 22, 0, 476, 416, $gold, $fontRegular, 'PHP + SQLite  ·  HTML cache  ·  SEO  ·  Agent API');

if (!imagepng($im, $output, 8)) {
    fwrite(STDERR, "Could not write {$output}.\n");
    exit(1);
}

// Square touch/app icon using the same exact three paths.
$icon = imagecreatetruecolor(512, 512);
imageantialias($icon, true);
$iconBg = imagecolorallocate($icon, 8, 8, 9);
$iconGold = imagecolorallocate($icon, 252, 190, 52);
imagefill($icon, 0, 0, $iconBg);
$iconOx = 76;
$iconOy = 79;
$iconScale = 0.9;
$iconPoly = static function (array $points) use ($iconOx, $iconOy, $iconScale): array {
    $out = [];
    foreach ($points as [$x, $y]) {
        $out[] = (int)round($iconOx + ($x * $iconScale));
        $out[] = (int)round($iconOy + ($y * $iconScale));
    }
    return $out;
};
imagefilledpolygon($icon, $iconPoly([[0,0],[400,0],[320,80],[0,80],[0,0]]), $iconGold);
imagefilledpolygon($icon, $iconPoly([[0,100],[300,100],[220,180],[0,180],[0,100]]), $iconGold);
imagefilledpolygon($icon, $iconPoly([[0,200],[80,200],[0,280],[0,200]]), $iconGold);
if (!imagepng($icon, $iconOutput, 8)) {
    fwrite(STDERR, "Could not write {$iconOutput}.\n");
    exit(1);
}

echo $output . PHP_EOL . $iconOutput . PHP_EOL;

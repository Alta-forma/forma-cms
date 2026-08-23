<?php
/**
 * Cinematic 1200×630 covers using the canonical Forma mark.
 * Usage: php tools/render-post-covers.php [outdir]
 */
if (!extension_loaded('gd')) {
    fwrite(STDERR, "PHP GD is required.\n");
    exit(1);
}

$outDir = $argv[1] ?? dirname(__DIR__) . '/covers';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

$fontRegular = '/System/Library/Fonts/Supplemental/Arial.ttf';
$fontBold = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
if (!is_file($fontRegular) || !is_file($fontBold)) {
    fwrite(STDERR, "Arial system fonts were not found.\n");
    exit(1);
}

$covers = [
    'html-cache' => ['HTML cache', 'Files for URLs. PHP for questions.', [252, 190, 52], 0.22, 18],
    'search'     => ['Site search', 'One shortcode. The whole site.', [255, 208, 96], 0.18, -12],
    'snippets'   => ['Snippets', 'A tiny design system.', [232, 168, 40], 0.26, 8],
    'seo'        => ['SEO launch', 'Fifteen minutes. The real checklist.', [252, 190, 52], 0.16, 28],
    'fastcgi'    => ['When PHP died', 'The afternoon FastCGI went silent.', [255, 120, 72], 0.28, -22],
    'pages'      => ['Your first page', 'META, slugs, and shipping HTML.', [120, 196, 255], 0.18, 14],
    'blogging'   => ['Write a post', 'Markdown in. A live article out.', [196, 140, 255], 0.2, -8],
    'agents'     => ['Cursor + Forma', 'A scoped token is a leash.', [252, 190, 52], 0.2, 4],
];

$drawMark = static function ($im, int $ox, int $oy, float $scale, int $color): void {
    $poly = static function (array $points) use ($ox, $oy, $scale): array {
        $out = [];
        foreach ($points as [$x, $y]) {
            $out[] = (int)round($ox + ($x * $scale));
            $out[] = (int)round($oy + ($y * $scale));
        }
        return $out;
    };
    imagefilledpolygon($im, $poly([[0, 0], [400, 0], [320, 80], [0, 80], [0, 0]]), $color);
    imagefilledpolygon($im, $poly([[0, 100], [300, 100], [220, 180], [0, 180], [0, 100]]), $color);
    imagefilledpolygon($im, $poly([[0, 200], [80, 200], [0, 280], [0, 200]]), $color);
};

foreach ($covers as $slug => [$kicker, $line, $rgb, $glow, $tilt]) {
    $im = imagecreatetruecolor(1200, 630);
    imageantialias($im, true);
    for ($y = 0; $y < 630; $y++) {
        $t = $y / 629;
        $v = (int)round(5 + (14 * $t));
        imageline($im, 0, $y, 1199, $y, imagecolorallocate($im, $v, $v, $v + 2));
    }
    $glowCol = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], (int)round(127 * (1 - $glow)));
    imagefilledellipse($im, 220, 180, 720, 520, $glowCol);
    imagefilledellipse($im, 980, 520, 640, 360, imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 118));

    $stripe = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 114);
    $step = 54;
    for ($x = -700; $x < 1800; $x += $step) {
        imageline($im, $x, 0, $x + 630 + $tilt, 630, $stripe);
    }

    $gold = imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
    $goldSoft = imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], 70);
    $white = imagecolorallocate($im, 245, 245, 247);
    $muted = imagecolorallocate($im, 168, 168, 178);

    $drawMark($im, 760, 70, 1.05, $goldSoft);
    $drawMark($im, 72, 168, 0.62, $gold);

    imagettftext($im, 22, 0, 72, 118, $gold, $fontBold, 'FORMA');
    imagettftext($im, 54, 0, 72, 430, $white, $fontBold, $kicker);
    imagettftext($im, 22, 0, 74, 488, $muted, $fontRegular, $line);

    $path = $outDir . '/cover-' . $slug . '.png';
    imagepng($im, $path, 8);
    echo $path . PHP_EOL;
}

echo 'ok ' . count($covers) . PHP_EOL;

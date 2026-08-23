<?php
/**
 * One-shot: tighten Eden post SEO titles/descriptions and unpublish the Forma sample.
 * Run on the live vhost: php tools/eden-seo-tighten.php
 */
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';

$titles = [
    'free-pregnancy-testing'       => 'Free pregnancy testing in Norman',
    'two-lines'                    => 'Two lines. What happens next.',
    'welcome-no-matter-what'       => 'You are welcome here, no matter what',
    'breastfeeding-101'            => 'Breastfeeding 101: care for you both',
    'safe-place-to-talk'           => 'A safe place to talk through pregnancy',
    'ectopic-pregnancy-ultrasound' => 'Ectopic pregnancy: why ultrasound matters',
    'abortion-pill-second-thoughts'=> 'If you\'ve taken the abortion pill',
    'fatherhood-success'           => 'Fatherhood: a quieter kind of success',
    'before-abortion-pill'         => 'Before you hear about the abortion pill',
    'tell-boyfriend-college'       => 'Telling your boyfriend you\'re pregnant',
    'after-the-baby'               => 'After the baby: joy and heaviness',
    'college-students-norman'      => 'Norman students: a possible pregnancy',
    'postpartum-anxiety'           => 'Postpartum anxiety: when the alarm stays',
    'talking-with-him'             => 'Talking with him when you\'re pregnant',
    'mental-health-matters'        => 'Why mental health support matters',
    'after-abortion-support-notes' => 'If you are carrying what happened',
    'not-alone-parenting'          => 'Parenting support when you feel alone',
    'hope-and-support'             => 'Finding a place to set the weight down',
    'support-a-friend'             => 'How to support a friend who\'s pregnant',
    'smoking-during-pregnancy'     => 'Smoking in pregnancy, without the shame',
    'healthy-relationships'        => 'Healthy relationships you can stay in',
    'oklahoma-adoption-consent'    => 'Oklahoma adoption consent, in plain words',
    'understanding-femtech'        => 'Femtech: what an app cannot do',
    'cervical-cancer-screening'    => 'Pap tests belong with your clinician',
    'introducing-partner-holidays' => 'Introducing a partner over the holidays',
];

$descs = [
    'two-lines' => 'A positive test can feel enormous and unfinished. How we confirm a pregnancy, what a limited ultrasound can tell you, and the next step without being hurried.',
    'postpartum-anxiety' => 'Jumpiness after a baby can be ordinary. Dread that grows is medical. What to notice, when to use 911 or 988, and why we do not pretend to treat it.',
];

$seo = Database::get()->getSetting('seo');
$seo['title_separator'] = ' | ';
$seo['title_suffix'] = true;
Database::get()->saveSetting('seo', $seo);

$sep = ' | ';
$siteTitle = Database::get()->getSetting('site')['title'] ?? 'The Eden Clinic';
$budget = 60 - mb_strlen($sep . $siteTitle);

foreach ($titles as $file => $unique) {
    $len = mb_strlen($unique);
    if ($len > $budget) {
        fwrite(STDERR, "TOO LONG $file ($len > $budget): $unique\n");
        continue;
    }
    $row = BlogRepo::get($file);
    if (!$row) {
        fwrite(STDERR, "missing $file\n");
        continue;
    }
    $seoPatch = ['seo_title' => $unique];
    if (isset($descs[$file])) {
        $d = $descs[$file];
        if (mb_strlen($d) > 160) {
            fwrite(STDERR, "DESC LONG $file " . mb_strlen($d) . "\n");
        }
        $seoPatch['seo_description'] = $d;
    }
    BlogRepo::save([
        'filename' => $file,
        'seo' => $seoPatch,
    ]);
    echo "ok $file  unique=$len  display=" . mb_strlen($unique . $sep . $siteTitle) . "\n";
}

$welcome = BlogRepo::get('welcome');
if ($welcome && !empty($welcome['published_at'])) {
    BlogRepo::save(['filename' => 'welcome', 'date' => '']);
    echo "unpublished welcome (Forma sample)\n";
}

$h = Seo::healthReport();
echo json_encode(['score' => $h['score'], 'counts' => $h['counts']], JSON_UNESCAPED_SLASHES) . "\n";
foreach ($h['issues'] as $i) {
    echo sprintf("[%s] %s\n", $i['level'], $i['message']);
}

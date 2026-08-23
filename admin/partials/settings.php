<?php
require_once __DIR__ . '/_helpers.php';
$sub = $_GET['sub'] ?? 'general';
$podcastLicensed = License::isPodcastLicensed();
$aliases = [
    'license' => 'general',
    'about'   => 'general',
    'hosting' => 'server',
    'agents'  => 'access',
    'account' => 'access',
];
if (isset($aliases[$sub])) {
    $sub = $aliases[$sub];
}
$subs = [
    'general' => ['General', 'cog'],
    'seo'     => ['SEO', 'search'],
    'blog'    => ['Blog', 'blog'],
];
if ($podcastLicensed) {
    $subs['podcast'] = ['Podcast', 'podcast'];
}
$subs += [
    'cache'  => ['Cache', 'bolt'],
    'server' => ['Server', 'server'],
    'update' => ['Update', 'cloud-download-alt'],
    'access' => ['Access', 'user-lock'],
    'backup' => ['Backup', 'download'],
    'import' => ['Import', 'file-import'],
];
if ($sub === 'podcast' && !$podcastLicensed) {
    $sub = 'general';
}
if (!isset($subs[$sub])) {
    $sub = 'general';
}
?>
<div class="section-container">
    <div class="file-list settings-nav">
        <?php foreach ($subs as $key => [$label, $icon]): ?>
        <div class="file-item <?php echo $sub === $key ? 'active' : ''; ?>"
             data-section="<?php echo h($key); ?>"
             hx-get="partials/settings-<?php echo $key; ?>.php"
             hx-target="#settings-panel"
             hx-swap="innerHTML"
             hx-push-url="index.php?section=settings&sub=<?php echo $key; ?>"
             hx-on::after-request="document.querySelectorAll('.settings-nav .file-item').forEach(i=>i.classList.remove('active')); this.classList.add('active')">
            <i class="fas fa-<?php echo h($icon); ?>"></i> <?php echo h($label); ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="editor-container settings-panel-shell" id="settings-panel">
        <?php require __DIR__ . '/settings-' . $sub . '.php'; ?>
    </div>
</div>

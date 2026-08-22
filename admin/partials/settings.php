<?php
require_once __DIR__ . '/_helpers.php';
$sub = $_GET['sub'] ?? 'general';
$podcastLicensed = License::isPodcastLicensed();
$subs = [
    'general'  => ['General', 'cog'],
    'seo'      => ['SEO', 'search'],
    'blog'     => ['Blog', 'blog'],
];
if ($podcastLicensed) {
    $subs['podcast'] = ['Podcast', 'podcast'];
}
$subs += [
    'cache'    => ['Cache', 'bolt'],
    'server'   => ['Server', 'shield-alt'],
    'hosting'  => ['Hosting check', 'cloud'],
    'account'  => ['Account', 'user-lock'],
    'agents'   => ['Agents', 'robot'],
    'license'  => ['License', 'key'],
    'backup'   => ['Backup', 'download'],
    'import'   => ['Import', 'file-import'],
    'about'    => ['About', 'info-circle'],
];
if ($sub === 'podcast' && !$podcastLicensed) {
    $sub = 'license';
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

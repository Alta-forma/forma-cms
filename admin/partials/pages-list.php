<?php
require_once __DIR__ . '/_helpers.php';
$pages = PageRepo::list();
$active = $_GET['file'] ?? '';
?>
<div class="file-list-content" id="pages-list">
<?php if (!$pages): ?>
    <div class="file-item" style="opacity:.5"><i class="fas fa-info-circle"></i> No pages yet</div>
<?php else: foreach ($pages as $p): ?>
    <div class="file-item <?php echo $active === $p['filename'] ? 'active' : ''; ?>"
         hx-get="partials/pages-editor.php?file=<?php echo urlencode($p['filename']); ?>"
         hx-target="#pages-editor"
         hx-swap="innerHTML"
         hx-on::after-request="document.querySelectorAll('#pages-list .file-item').forEach(i=>i.classList.remove('active')); this.classList.add('active')">
        <i class="fas <?php echo $p['filename'] === 'home' ? 'fa-house' : 'fa-file-alt'; ?>"></i>
        <?php echo h($p['filename']); ?>
        <?php if (($p['content_type'] ?? '') === 'md'): ?><span class="status-pill">(md)</span><?php endif; ?>
    </div>
<?php endforeach; endif; ?>
</div>

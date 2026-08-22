<?php
require_once __DIR__ . '/_helpers.php';
$posts = BlogRepo::list(false);
$active = $_GET['file'] ?? '';
?>
<div class="file-list-content" id="blog-list">
<?php if (!$posts): ?>
    <div class="file-item" style="opacity:.5"><i class="fas fa-info-circle"></i> No posts yet</div>
<?php else: foreach ($posts as $p): ?>
    <div class="file-item <?php echo $active === $p['filename'] ? 'active' : ''; ?>"
         hx-get="partials/blog-editor.php?file=<?php echo urlencode($p['filename']); ?>"
         hx-target="#blog-editor"
         hx-swap="innerHTML"
         hx-on::after-request="document.querySelectorAll('#blog-list .file-item').forEach(i=>i.classList.remove('active')); this.classList.add('active')">
        <i class="fas fa-newspaper"></i>
        <?php echo h($p['title'] ?: $p['filename']); ?>
        <?php if (empty($p['published_at'])): ?><span class="status-pill">draft</span><?php endif; ?>
    </div>
<?php endforeach; endif; ?>
</div>

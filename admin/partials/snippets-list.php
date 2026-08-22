<?php
require_once __DIR__ . '/_helpers.php';
$items = SnippetRepo::list();
$active = $_GET['file'] ?? '';
?>
<div class="file-list-content" id="snippets-list">
<?php if (!$items): ?>
    <div class="file-item" style="opacity:.5">No snippets</div>
<?php else: foreach ($items as $s): ?>
    <div class="file-item <?php echo $active === $s['filename'] ? 'active' : ''; ?>"
         hx-get="partials/snippets-editor.php?file=<?php echo urlencode($s['filename']); ?>"
         hx-target="#snippets-editor"
         hx-swap="innerHTML"
         hx-on::after-request="document.querySelectorAll('#snippets-list .file-item').forEach(i=>i.classList.remove('active')); this.classList.add('active')">
        <i class="fas fa-code"></i> <?php echo h($s['filename']); ?>
        <span class="status-pill">[[<?php echo h($s['shortcode']); ?>]]</span>
    </div>
<?php endforeach; endif; ?>
</div>

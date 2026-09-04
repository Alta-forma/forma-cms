<?php
require_once __DIR__ . '/_helpers.php';
$file = PageRepo::sanitizeFilename($_GET['file'] ?? '');
$row = $file ? SnippetRepo::get($file) : null;
$summary = trim(($row['filename'] ?? 'new') . (!empty($row['shortcode']) ? ' · [[' . $row['shortcode'] . ']]' : ''));
?>
<form id="snippet-form" class="editor-form"
      hx-post="actions/snippets-save.php"
      hx-target="#snippets-list"
      hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="meta-panel">
        <button type="button" class="meta-panel-toggle" aria-expanded="true">
            <span><i class="fas fa-sliders"></i> Snippet details</span>
            <span class="meta-panel-summary"><?php echo h($summary); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <div class="form-group">
                <label>Filename</label>
                <input type="text" name="filename" required value="<?php echo h($row['filename'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Shortcode</label>
                <input type="text" name="shortcode" required value="<?php echo h($row['shortcode'] ?? ''); ?>">
                <span class="hint">Use as [[shortcode]] in pages</span>
            </div>
        </div>
    </div>

    <div class="form-group" style="flex:1;min-height:0;display:flex;flex-direction:column">
        <textarea name="content" class="code-editor" data-mode="htmlmixed" data-chips="1"><?php echo h($row['content'] ?? ''); ?></textarea>
    </div>
</form>
<footer>
    <div class="buttons">
        <div class="button-group">
            <button type="submit" form="snippet-form" class="standard-btn"><i class="small fas fa-save"></i> Save</button>
            <?php if (!empty($row['filename'])): ?>
            <button type="button" class="delete-btn"
                    hx-post="actions/snippets-delete.php"
                    hx-vals='{"filename":"<?php echo h($row['filename']); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#main"
                    hx-confirm="Delete snippet?">
                <i class="small fas fa-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
</footer>

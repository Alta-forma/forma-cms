<?php
require_once __DIR__ . '/_helpers.php';
?>
<div class="section-container">
    <div class="file-list">
        <div class="file-item new-file"
             hx-get="partials/snippets-editor.php"
             hx-target="#snippets-editor"
             hx-swap="innerHTML">
            <i class="fas fa-plus"></i> Add Snippet
        </div>
        <?php require __DIR__ . '/snippets-list.php'; ?>
    </div>
    <div class="editor-container" id="snippets-editor">
        <?php
        $items = SnippetRepo::list();
        $_GET['file'] = $_GET['file'] ?? ($items[0]['filename'] ?? '');
        require __DIR__ . '/snippets-editor.php';
        ?>
    </div>
</div>

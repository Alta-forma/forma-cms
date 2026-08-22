<?php
require_once __DIR__ . '/_helpers.php';
?>
<div class="section-container">
    <div class="file-list">
        <div class="file-item new-file"
             hx-get="partials/pages-editor.php"
             hx-target="#pages-editor"
             hx-swap="innerHTML">
            <i class="fas fa-plus"></i> Add New Page
        </div>
        <?php require __DIR__ . '/pages-list.php'; ?>
    </div>
    <div class="editor-container" id="pages-editor">
        <?php
        $_GET['file'] = $_GET['file'] ?? 'home';
        require __DIR__ . '/pages-editor.php';
        ?>
    </div>
</div>

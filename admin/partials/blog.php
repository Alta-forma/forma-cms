<?php
require_once __DIR__ . '/_helpers.php';
?>
<div class="section-container">
    <div class="file-list">
        <div class="file-item new-file"
             hx-get="partials/blog-editor.php"
             hx-target="#blog-editor"
             hx-swap="innerHTML">
            <i class="fas fa-plus"></i> Add New Post
        </div>
        <?php require __DIR__ . '/blog-list.php'; ?>
    </div>
    <div class="editor-container" id="blog-editor">
        <?php
        if (!isset($_GET['file'])) {
            $posts = BlogRepo::list(false);
            $_GET['file'] = $posts[0]['filename'] ?? '';
        }
        require __DIR__ . '/blog-editor.php';
        ?>
    </div>
</div>

<?php
require_once __DIR__ . '/_helpers.php';
fx_list_editor_shell(
    'Add New Post',
    'partials/blog-editor.php',
    'blog-editor',
    'blog-list.php',
    'blog-editor.php',
    static function () {
        $posts = BlogRepo::list(false);
        return $posts[0]['filename'] ?? '';
    }
);

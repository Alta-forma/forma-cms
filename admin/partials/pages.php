<?php
require_once __DIR__ . '/_helpers.php';
fx_list_editor_shell(
    'Add New Page',
    'partials/pages-editor.php',
    'pages-editor',
    'pages-list.php',
    'pages-editor.php',
    static fn () => 'home'
);

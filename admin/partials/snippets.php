<?php
require_once __DIR__ . '/_helpers.php';
fx_list_editor_shell(
    'Add Snippet',
    'partials/snippets-editor.php',
    'snippets-editor',
    'snippets-list.php',
    'snippets-editor.php',
    static function () {
        $items = SnippetRepo::list();
        return $items[0]['filename'] ?? '';
    }
);

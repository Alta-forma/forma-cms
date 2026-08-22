<?php
require_once __DIR__ . '/_helpers.php';
$episodes = PodcastRepo::list();
$active = $_GET['file'] ?? '';
?>
<div class="section-container">
    <div class="file-list">
        <div class="file-item new-file"
             hx-get="partials/podcast-editor.php"
             hx-target="#podcast-editor"
             hx-swap="innerHTML">
            <i class="fas fa-plus"></i> New Episode
        </div>
        <div class="file-list-content" id="podcast-list">
            <?php foreach ($episodes as $ep): ?>
            <div class="file-item <?php echo $active === $ep['episode_id'] ? 'active' : ''; ?>"
                 hx-get="partials/podcast-editor.php?file=<?php echo urlencode($ep['episode_id']); ?>"
                 hx-target="#podcast-editor"
                 hx-swap="innerHTML">
                <i class="fas fa-microphone"></i> <?php echo h($ep['title'] ?: $ep['episode_id']); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="editor-container" id="podcast-editor">
        <?php
        $_GET['file'] = $active ?: ($episodes[0]['episode_id'] ?? '');
        require __DIR__ . '/podcast-editor.php';
        ?>
    </div>
</div>

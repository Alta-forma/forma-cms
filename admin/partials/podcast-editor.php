<?php
require_once __DIR__ . '/_helpers.php';
if (!License::isPodcastLicensed()) {
    echo '<p class="locked-banner">Podcast locked</p>';
    return;
}
$id = preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['file'] ?? '') ?? '';
$row = ($id !== '' ? PodcastRepo::get($id) : null) ?? [];
$title = $row['title'] ?? '';
$epId = $row['episode_id'] ?? '';
$publishedAt = isset($row['published_at']) ? (int)$row['published_at'] : 0;
$summary = trim(($title ?: ($epId ?: 'new episode')) . ($publishedAt ? ' · ' . date('Y-m-d', $publishedAt) : ''));
?>
<form id="podcast-form" class="editor-form"
      hx-post="actions/podcast-save.php"
      hx-target="#main"
      hx-swap="innerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="meta-panel collapsed">
        <button type="button" class="meta-panel-toggle" aria-expanded="false">
            <span><i class="fas fa-sliders"></i> Episode details</span>
            <span class="meta-panel-summary"><?php echo h($summary); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <div class="form-row">
                <div class="form-group">
                    <label>Episode ID</label>
                    <input type="text" name="episode_id" value="<?php echo h($epId); ?>" placeholder="auto if blank">
                </div>
                <div class="form-group">
                    <label>Publish date</label>
                    <input type="date" name="date" value="<?php echo h($publishedAt ? date('Y-m-d', $publishedAt) : date('Y-m-d')); ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Title</label>
                <input type="text" name="title" required value="<?php echo h($title); ?>">
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" name="description" value="<?php echo h($row['description'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <?php echo fx_media_field('audio_file', $row['audio_file'] ?? '', [
                    'label' => 'Audio file',
                    'placeholder' => 'episode.mp3',
                    'hint' => 'Stored as uploads basename',
                    'accept' => 'audio',
                    'mode' => 'basename',
                    'preview' => false,
                ]); ?>
            </div>
            <div class="form-group">
                <?php echo fx_media_field('episode_art', $row['episode_art'] ?? '', [
                    'label' => 'Episode artwork',
                    'placeholder' => '/uploads/episode-art.jpg',
                    'hint' => 'Optional override of podcast cover for this episode',
                ]); ?>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Duration</label><input type="text" name="duration" value="<?php echo h($row['duration'] ?? '00:00:00'); ?>"></div>
                <div class="form-group"><label>Episode #</label><input type="number" name="episode_number" value="<?php echo (int)($row['episode_number'] ?? 0); ?>"></div>
                <div class="form-group"><label>Season #</label><input type="number" name="season_number" value="<?php echo (int)($row['season_number'] ?? 1); ?>"></div>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="explicit" value="1" <?php echo !empty($row['explicit']) ? 'checked' : ''; ?>> Explicit</label>
            </div>
        </div>
    </div>

    <div class="form-group" style="flex:1;min-height:0;display:flex;flex-direction:column">
        <label>Show notes (Markdown)</label>
        <textarea name="show_notes" class="code-editor" data-mode="markdown" data-chips="1"><?php echo h($row['show_notes'] ?? ''); ?></textarea>
    </div>
</form>
<footer>
    <div class="buttons">
        <div class="button-group">
            <button type="submit" form="podcast-form" class="standard-btn"><i class="small fas fa-save"></i> Save</button>
            <?php if ($epId !== ''): ?>
            <button type="button" class="delete-btn"
                    hx-post="actions/podcast-delete.php"
                    hx-vals='{"episode_id":"<?php echo h($epId); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#main" hx-confirm="Delete episode?">
                <i class="small fas fa-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
</footer>

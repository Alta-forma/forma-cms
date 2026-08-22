<?php
require_once __DIR__ . '/_helpers.php';
$s = Database::get()->getSetting('blog');
$site = Database::get()->getSetting('site');
$base = rtrim($site['url'] ?? '', '/');
?>
<?php echo fx_panel_header('blog', 'Blog & feeds', 'How your posts are syndicated to readers'); ?>

<form hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="blog">

    <div class="settings-card">
        <h3><i class="fas fa-list"></i> Feed content</h3>
        <p class="card-sub">What goes into each generated feed.</p>
        <div class="form-row">
            <div class="form-group">
                <label>Posts in feed</label>
                <input type="number" name="feed_posts" min="1" max="100" value="<?php echo (int)($s['feed_posts'] ?? 20); ?>">
                <span class="hint">Most recent posts included</span>
            </div>
            <div class="form-group">
                <label>Excerpt length</label>
                <input type="number" name="excerpt_length" min="50" max="1000" value="<?php echo (int)($s['excerpt_length'] ?? 250); ?>">
                <span class="hint">Characters, when no description is set</span>
            </div>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-rss"></i> Feed formats</h3>
        <p class="card-sub">Feeds are written as static files, so they cost nothing to serve.</p>
        <?php echo fx_switch('blog_feed_rss', (bool)($s['blog_feed_rss'] ?? true), 'RSS/XML feed', 'The classic — works with every reader'); ?>
        <?php echo fx_switch('blog_feed_json', (bool)($s['blog_feed_json'] ?? true), 'JSON Feed', 'Modern alternative at /feed.json'); ?>
        <?php echo fx_switch('auto_regen_feed', (bool)($s['auto_regen_feed'] ?? true), 'Auto-regenerate on save', 'Rebuild feeds every time a post is saved or deleted'); ?>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
            <button type="button" class="standard-btn"
                    hx-post="actions/feed-regen.php"
                    hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>","kind":"blog"}'
                    hx-target="#fx-toast" hx-swap="outerHTML">
                <i class="small fas fa-sync"></i> Regenerate now
            </button>
        </div>
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-link"></i> Public feed URLs</h3>
    <p class="card-sub">Share these with readers and podcast/RSS apps.</p>
    <?php echo fx_url_pill(($base ?: '') . '/feed.xml'); ?>
    <?php echo fx_url_pill(($base ?: '') . '/feed.json'); ?>
</div>

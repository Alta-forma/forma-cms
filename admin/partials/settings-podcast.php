<?php
require_once __DIR__ . '/_helpers.php';
if (!License::isPodcastLicensed()) {
    require __DIR__ . '/settings-general.php';
    return;
}
$s = Database::get()->getSetting('podcast');
$site = Database::get()->getSetting('site');
$base = rtrim($site['url'] ?? '', '/');
$licensed = true;
$categories = [
    'Arts', 'Business', 'Comedy', 'Education', 'Fiction', 'Government', 'Health & Fitness',
    'History', 'Kids & Family', 'Leisure', 'Music', 'News', 'Religion & Spirituality',
    'Science', 'Society & Culture', 'Sports', 'TV & Film', 'Technology', 'True Crime',
];
$explicit = $s['explicit'] ?? 'no';
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('podcast', 'Podcast', 'Show metadata for your podcast RSS feed'); ?>

<form id="fx-settings-form" hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="podcast">

    <div class="settings-card">
        <h3><i class="fas fa-microphone"></i> Show info</h3>
        <p class="card-sub">Appears in Apple Podcasts, Spotify, and every podcast app.</p>
        <div class="form-group">
            <label>Podcast title</label>
            <input type="text" name="title" value="<?php echo h($s['title'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="3"><?php echo h($s['description'] ?? ''); ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Author</label>
                <input type="text" name="author" value="<?php echo h($s['author'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Owner email</label>
                <input type="email" name="email" value="<?php echo h($s['email'] ?? ''); ?>">
                <span class="hint">Required by Apple Podcasts</span>
            </div>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-store"></i> Directory listing</h3>
        <p class="card-sub">Category, artwork, and content rating for podcast directories.</p>
        <div class="form-row">
            <div class="form-group">
                <label>Category</label>
                <input type="text" name="category" list="fx-podcast-cats" value="<?php echo h($s['category'] ?? ''); ?>">
                <datalist id="fx-podcast-cats">
                    <?php foreach ($categories as $c): ?><option value="<?php echo h($c); ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label>Subcategory</label>
                <input type="text" name="subcategory" value="<?php echo h($s['subcategory'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-group">
            <?php echo fx_media_field('image', $s['image'] ?? '', [
                'label' => 'Cover art',
                'placeholder' => '/uploads/cover.jpg',
                'hint' => 'Square JPG/PNG, 1400–3000px — Apple requirement',
            ]); ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Explicit content</label>
                <select name="explicit">
                    <option value="no" <?php echo $explicit === 'no' ? 'selected' : ''; ?>>No</option>
                    <option value="yes" <?php echo $explicit === 'yes' ? 'selected' : ''; ?>>Yes</option>
                    <option value="clean" <?php echo $explicit === 'clean' ? 'selected' : ''; ?>>Clean</option>
                </select>
            </div>
            <div class="form-group">
                <label>Language</label>
                <input type="text" name="language" value="<?php echo h($s['language'] ?? 'en-us'); ?>">
                <span class="hint">e.g. en-us</span>
            </div>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-rss"></i> Feed</h3>
        <?php echo fx_switch('podcast_feed_rss', (bool)($s['podcast_feed_rss'] ?? true), 'Podcast RSS feed', 'Required for Apple Podcasts, Spotify, etc.'); ?>
        <?php echo fx_switch('auto_regen_feed', (bool)($s['auto_regen_feed'] ?? true), 'Auto-regenerate on save', 'Rebuild the feed every time an episode is saved or deleted'); ?>
        <div class="card-actions">
            <button type="button" class="standard-btn"
                    hx-post="actions/feed-regen.php"
                    hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>","kind":"podcast"}'
                    hx-target="#fx-toast" hx-swap="outerHTML">
                <i class="small fas fa-sync"></i> Regenerate now
            </button>
        </div>
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-link"></i> Public feed URL</h3>
    <p class="card-sub">Submit this to Apple Podcasts, Spotify, and other directories.</p>
    <?php echo fx_url_pill(($base ?: '') . '/podcast.xml'); ?>
</div>
<?php
fx_settings_scroll_close();
echo fx_settings_footer('fx-settings-form');

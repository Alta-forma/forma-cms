<?php
require_once __DIR__ . '/_helpers.php';
$s = Database::get()->getSetting('site');
$timezones = [
    'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Moscow',
    'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Kolkata', 'Australia/Sydney', 'Pacific/Auckland',
];
$tz = $s['timezone'] ?? 'UTC';
?>
<?php echo fx_panel_header('cog', 'General', 'Site identity, defaults, and localization'); ?>

<form hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="site">

    <div class="settings-card">
        <h3><i class="fas fa-globe"></i> Site identity</h3>
        <p class="card-sub">Used in page titles, feeds, and SEO metadata.</p>
        <div class="form-group">
            <label>Site title</label>
            <input type="text" name="title" value="<?php echo h($s['title'] ?? ''); ?>" required>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="3"><?php echo h($s['description'] ?? ''); ?></textarea>
            <span class="hint">A short description of your site, used in RSS feeds and SEO</span>
        </div>
        <div class="form-group">
            <label>Site URL</label>
            <input type="url" name="url" value="<?php echo h($s['url'] ?? ''); ?>" placeholder="https://example.com">
            <span class="hint">Full public URL — used for absolute links in feeds</span>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-pen-nib"></i> Publishing defaults</h3>
        <p class="card-sub">Applied when a page or post doesn’t specify its own values.</p>
        <div class="form-group">
            <label>Default author</label>
            <input type="text" name="default_author" value="<?php echo h($s['default_author'] ?? ''); ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Language</label>
                <input type="text" name="language" value="<?php echo h($s['language'] ?? 'en'); ?>">
                <span class="hint">Language code, e.g. en, fr, es</span>
            </div>
            <div class="form-group">
                <label>Timezone</label>
                <select name="timezone">
                    <?php foreach ($timezones as $z): ?>
                    <option value="<?php echo h($z); ?>" <?php echo $tz === $z ? 'selected' : ''; ?>><?php echo h($z); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
        </div>
    </div>
</form>

<p class="hint" style="text-align:right"><?php echo h(FORMA_PRODUCT); ?> <?php echo h(FORMA_VERSION); ?> · <?php echo h(FORMA_VERSION_DATE); ?></p>

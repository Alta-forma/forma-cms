<?php
require_once __DIR__ . '/_helpers.php';
$s = Database::get()->getSetting('site');
$st = License::status();
$php = PHP_VERSION;
$sqlite = class_exists('SQLite3') ? SQLite3::version()['versionString'] ?? '' : '';
$timezones = [
    'UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
    'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Moscow',
    'Asia/Tokyo', 'Asia/Shanghai', 'Asia/Kolkata', 'Australia/Sydney', 'Pacific/Auckland',
];
$tz = $s['timezone'] ?? 'UTC';
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('cog', 'General', 'Site identity, this install, and license'); ?>

<form id="fx-settings-form" hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
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
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-cube"></i> About this install</h3>
    <p class="card-sub">
        A portable PHP + SQLite CMS. Pages, blog, snippets, media, and SEO live in one database file.
        Agents start at <code>GET /api/v1/help</code>.
    </p>
    <div class="kv-row"><span class="k">Product</span><span class="v"><?php echo h(FORMA_PRODUCT); ?></span></div>
    <div class="kv-row"><span class="k">Version</span><span class="v"><code><?php echo h(FORMA_VERSION); ?></code> · <?php echo h(FORMA_VERSION_DATE); ?></span></div>
    <div class="kv-row"><span class="k">Schema</span><span class="v">v<?php echo (int)FORMA_SCHEMA_VERSION; ?></span></div>
    <div class="kv-row"><span class="k">PHP</span><span class="v"><?php echo h($php); ?></span></div>
    <?php if ($sqlite !== ''): ?>
    <div class="kv-row"><span class="k">SQLite</span><span class="v"><?php echo h($sqlite); ?></span></div>
    <?php endif; ?>
    <div class="kv-row"><span class="k">This site</span><span class="v"><?php echo h(forma_site_title()); ?></span></div>
    <ul class="about-list" style="margin-top:1rem">
        <li>Pages, blog, snippets, uploads, and sitewide SEO</li>
        <li>Agent API tokens under Settings → Access</li>
        <li>Versioned site packages under Settings → Backup</li>
        <li>Hosting checks under Settings → Server</li>
    </ul>
    <p class="hint" style="margin-top:.85rem;margin-bottom:0">
        In-app updates are not wired yet — replace PHP files (or restore a site package) yourself.
        Don’t yank <code>database/</code> or <code>uploads/</code> when you do.
    </p>
</div>

<div class="settings-card <?php echo $st['licensed'] ? 'card-glow-pass' : 'card-glow-warn'; ?>">
    <h3><i class="fas fa-key"></i> License</h3>
    <p class="card-sub">Forma is free. Podcast hosting is $39 one-time. Buy in this panel, then paste the emailed key.</p>
    <div class="kv-row">
        <span class="k">Podcast</span>
        <span class="v">
            <?php if ($st['licensed']): ?>
                <span class="status-badge ok"><i class="fas fa-check-circle"></i> <?php echo h(ucfirst($st['license_type'] ?: 'Active')); ?></span>
            <?php else: ?>
                <span class="status-badge off"><i class="fas fa-lock"></i> Locked</span>
            <?php endif; ?>
        </span>
    </div>
    <?php if ($st['licensed'] && !empty($st['valid_until'])): ?>
    <div class="kv-row">
        <span class="k">Valid until</span>
        <span class="v"><?php echo h(date('M j, Y', (int)$st['valid_until'])); ?></span>
    </div>
    <?php endif; ?>
    <form hx-post="actions/license-activate.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group" style="margin-top:1rem">
            <label>License email</label>
            <input type="email" name="license_email" value="<?php echo h($st['licensed_to'] ?? ''); ?>" placeholder="the address you paid with" autocomplete="email">
        </div>
        <div class="form-group">
            <label>License key</label>
            <input type="text" name="license_key" placeholder="XXXX-XXXX-XXXX-XXXX" style="font-family:monospace">
            <span class="hint">$39 one-time. Same email you used at checkout. Local unlock: FX-DEV-LOCAL</span>
        </div>
        <div class="card-actions">
            <a class="standard-btn" href="<?php echo h(License::BUY_URL); ?>" target="_blank" rel="noopener">Buy Podcast — $39</a>
            <button type="submit" class="standard-btn"><i class="small fas fa-key"></i> Activate</button>
        </div>
    </form>
</div>
<?php
fx_settings_scroll_close();
echo fx_settings_footer('fx-settings-form');

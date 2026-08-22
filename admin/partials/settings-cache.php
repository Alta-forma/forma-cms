<?php
require_once __DIR__ . '/_helpers.php';
$s = Database::get()->getSetting('cache');
$row = Database::get()->queryOne('SELECT COUNT(*) AS c FROM page_cache');
$cached = (int)($row['c'] ?? 0);
$fb = StaticFallback::status();
$age = static function (?int $sec): string {
    if ($sec === null) {
        return 'missing';
    }
    if ($sec < 60) {
        return $sec . 's ago';
    }
    if ($sec < 3600) {
        return (int)floor($sec / 60) . 'm ago';
    }
    return (int)floor($sec / 3600) . 'h ago';
};
?>
<?php echo fx_panel_header('bolt', 'Cache', 'SQLite speed vs last-good HTML if PHP dies'); ?>

<form hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="cache">

    <div class="settings-card">
        <h3><i class="fas fa-tachometer-alt"></i> Page cache</h3>
        <p class="card-sub">
            Stores rendered HTML in SQLite so the next visit skips Twig/Markdown.
            <strong>This still runs PHP.</strong> If FastCGI dies (“No input file specified.”), page cache does nothing.
        </p>
        <?php echo fx_switch('enabled', !empty($s['enabled']), 'Enable page cache', 'Less render work — not a substitute for working PHP'); ?>
        <div class="form-group" style="margin-top:1rem">
            <label>Time to live</label>
            <input type="number" name="ttl" min="60" value="<?php echo (int)($s['ttl'] ?? 3600); ?>">
            <span class="hint">Seconds before a cached page expires (3600 = 1 hour). Also used to refresh last-good homepage HTML via <code>/up</code>.</span>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-life-ring"></i> Last-good HTML (survives PHP death)</h3>
        <p class="card-sub">
            Writes the homepage to <code>fallback/index.html</code>. Apache serves that file if PHP/FastCGI is down.
            Heartbeat: <code>GET /up</code> (JSON). Stamp: <code>/fallback/php-ok.json</code> (static — still 200 when PHP is dead).
            Watch with <code>php tools/watch-php.php https://your-site</code>.
        </p>
        <?php echo fx_switch('static_fallback', !empty($s['static_fallback']), 'Write last-good HTML', 'On by default. Flush cache does not delete these files.'); ?>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
        </div>
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-database"></i> Cache status</h3>
    <div class="kv-row">
        <span class="k">Cached pages</span>
        <span class="v"><strong><?php echo $cached; ?></strong></span>
    </div>
    <div class="kv-row">
        <span class="k">Excluded paths</span>
        <span class="v"><?php echo implode(' ', array_map(fn($p) => '<code>' . h((string)$p) . '</code>', $s['excluded_paths'] ?? ['/admin', '/api'])); ?></span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-post="actions/cache-flush.php"
                hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#fx-toast" hx-swap="outerHTML"
                hx-on::after-request="htmx.ajax('GET','partials/settings-cache.php',{target:'#settings-panel',swap:'innerHTML'})">
            <i class="small fas fa-broom"></i> Flush cache
        </button>
        <span class="hint" style="margin:0">SQLite only — last-good HTML stays so an outage still has a homepage.</span>
    </div>
</div>

<div class="settings-card">
    <h3><i class="fas fa-heartbeat"></i> PHP heartbeat</h3>
    <div class="kv-row">
        <span class="k">Last-good home</span>
        <span class="v"><?php echo $fb['home'] ? h($age($fb['home_age'])) : 'not written yet'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">php-ok.json</span>
        <span class="v"><?php echo $fb['stamp'] ? h($age($fb['stamp_age'])) : 'not written yet'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">fallback/ writable</span>
        <span class="v"><?php echo !empty($fb['writable']) ? 'yes' : 'no'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">.htaccess rules</span>
        <span class="v"><?php echo Htaccess::hasStaticFallbackRules() ? 'present' : 'missing — save Cache or open Hosting check'; ?></span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-post="actions/fallback-rebuild.php"
                hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#fx-toast" hx-swap="outerHTML"
                hx-on::after-request="htmx.ajax('GET','partials/settings-cache.php',{target:'#settings-panel',swap:'innerHTML'})">
            <i class="small fas fa-sync-alt"></i> Rebuild homepage now
        </button>
    </div>
</div>

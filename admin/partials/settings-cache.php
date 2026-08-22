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
<?php echo fx_panel_header('bolt', 'Cache & Publish', 'SQLite speed vs real HTML files Apache can serve without PHP'); ?>

<form hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="cache">

    <div class="settings-card">
        <h3><i class="fas fa-tachometer-alt"></i> Page cache</h3>
        <p class="card-sub">
            Stores rendered HTML in SQLite so the next visit skips Twig/Markdown.
            <strong>This still runs PHP.</strong> If FastCGI dies (“No input file specified.”), page cache does nothing.
        </p>
        <?php echo fx_switch('enabled', !empty($s['enabled']), 'Enable page cache', 'Less render work — not a substitute for Publish mode below'); ?>
        <div class="form-group" style="margin-top:1rem">
            <label>Time to live</label>
            <input type="number" name="ttl" min="60" value="<?php echo (int)($s['ttl'] ?? 3600); ?>">
            <span class="hint">Seconds before a cached page expires (3600 = 1 hour).</span>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-life-ring"></i> Publish mode (survives PHP death)</h3>
        <p class="card-sub">
            Writes every page, post, and podcast episode to real <code>.html</code> files under
            <code>fallback/</code>. Apache serves those files directly — before PHP even runs.
            A page you haven't saved yet just falls through to a live PHP render, so turning this
            on never blanks the site mid-migration. It also means the site stays up if PHP/FastCGI
            dies outright. Search (<code>/search</code>), <code>/admin</code>, and <code>/api</code>
            always run PHP. Heartbeat: <code>GET /up</code>. Stamp: <code>/fallback/php-ok.json</code>
            (still 200 when PHP is dead). Watch with <code>php tools/watch-php.php https://your-site</code>.
        </p>
        <?php echo fx_switch('static_fallback', !empty($s['static_fallback']), 'Turn on Publish mode', 'Publishes the whole site now, then keeps every save in sync automatically.'); ?>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save changes</button>
        </div>
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-database"></i> Cache status</h3>
    <div class="kv-row">
        <span class="k">Cached pages (SQLite)</span>
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
        <span class="hint" style="margin:0">SQLite only — published files under fallback/ stay so an outage still has a full site.</span>
    </div>
</div>

<div class="settings-card">
    <h3><i class="fas fa-heartbeat"></i> Publish status</h3>
    <div class="kv-row">
        <span class="k">Publish mode</span>
        <span class="v"><?php echo !empty($fb['enabled']) ? ($fb['marker'] ? 'on — live' : 'on — not published yet') : 'off'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">Homepage</span>
        <span class="v"><?php echo $fb['home'] ? h($age($fb['home_age'])) : 'not written yet'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">Last full publish</span>
        <span class="v"><?php echo $fb['last_published'] ? h($age(time() - $fb['last_published'])) : 'never'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">php-ok.json stamp</span>
        <span class="v"><?php echo $fb['stamp'] ? h($age($fb['stamp_age'])) : 'not written yet'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">fallback/ writable</span>
        <span class="v"><?php echo !empty($fb['writable']) ? 'yes' : 'no'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">.htaccess rules</span>
        <span class="v"><?php echo Htaccess::hasStaticFallbackRules() ? 'present' : 'missing — save above or open Hosting check'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">Search engine</span>
        <span class="v"><?php echo h($fb['search_engine'] === 'fts5' ? 'SQLite FTS5 (ranked)' : 'LIKE fallback'); ?> · <?php echo (int)$fb['search_docs']; ?> indexed</span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-post="actions/fallback-rebuild.php"
                hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#fx-toast" hx-swap="outerHTML"
                hx-on::after-request="htmx.ajax('GET','partials/settings-cache.php',{target:'#settings-panel',swap:'innerHTML'})">
            <i class="small fas fa-sync-alt"></i> Publish now
        </button>
        <span class="hint" style="margin:0">Republishes every page/post/episode + rebuilds the search index. Turns Publish mode on if it's off.</span>
    </div>
</div>

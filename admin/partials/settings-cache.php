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
<?php echo fx_panel_header('bolt', 'Cache', 'Choose how Forma serves rendered pages'); ?>

<form hx-post="actions/settings-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
    <input type="hidden" name="section" value="cache">

    <div class="settings-card cache-card php-cache-card">
        <h3><i class="fas fa-microchip"></i> PHP cache</h3>
        <p class="card-sub">
            Stores rendered pages in SQLite so PHP can skip repeat Twig and Markdown work.
        </p>
        <?php echo fx_switch('enabled', !empty($s['enabled']), 'Enable PHP cache', 'PHP still handles each request, with less rendering work.'); ?>
        <div class="form-group" style="margin-top:1rem">
            <label>Time to live</label>
            <input type="number" name="ttl" min="60" value="<?php echo (int)($s['ttl'] ?? 3600); ?>">
            <span class="hint">Seconds before a cached page expires (3600 = 1 hour).</span>
        </div>
        <div class="cache-status-grid">
            <div class="cache-stat">
                <span>Cached pages</span>
                <strong><?php echo $cached; ?></strong>
            </div>
            <div class="cache-stat">
                <span>Excluded paths</span>
                <div><?php echo implode(' ', array_map(fn($p) => '<code>' . h((string)$p) . '</code>', $s['excluded_paths'] ?? ['/admin', '/api'])); ?></div>
            </div>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save cache settings</button>
            <button type="button" class="standard-btn subtle-btn"
                    hx-post="actions/cache-flush.php"
                    hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#fx-toast" hx-swap="outerHTML"
                    hx-on::after-request="htmx.ajax('GET','partials/settings-cache.php',{target:'#settings-panel',swap:'innerHTML'})">
                <i class="small fas fa-broom"></i> Flush PHP cache
            </button>
        </div>
    </div>

    <div class="settings-card cache-card html-cache-card">
        <h3><i class="fas fa-file-code"></i> HTML cache</h3>
        <p class="card-sub">
            Writes public pages, posts, and podcast episodes to real HTML files. Apache serves
            those files directly; search, admin, and the API remain dynamic.
        </p>
        <?php echo fx_switch('static_fallback', !empty($s['static_fallback']), 'Enable HTML cache', 'Creates the full cache now, then refreshes it automatically when content changes.'); ?>
        <div class="cache-status-grid">
            <div class="cache-stat">
                <span>Status</span>
                <strong><?php echo !empty($fb['enabled']) ? ($fb['marker'] ? 'On' : 'Waiting to build') : 'Off'; ?></strong>
            </div>
            <div class="cache-stat">
                <span>Homepage</span>
                <strong><?php echo $fb['home'] ? h($age($fb['home_age'])) : 'Not cached'; ?></strong>
            </div>
            <div class="cache-stat">
                <span>Last full build</span>
                <strong><?php echo $fb['last_published'] ? h($age(time() - $fb['last_published'])) : 'Never'; ?></strong>
            </div>
            <div class="cache-stat">
                <span>Search</span>
                <strong><?php echo h($fb['search_engine'] === 'fts5' ? 'FTS5' : 'LIKE'); ?> · <?php echo (int)$fb['search_docs']; ?> indexed</strong>
            </div>
            <div class="cache-stat">
                <span>HTML folder</span>
                <strong><?php echo !empty($fb['writable']) ? 'Writable' : 'Not writable'; ?></strong>
            </div>
            <div class="cache-stat">
                <span>Apache rules</span>
                <strong><?php echo Htaccess::hasStaticFallbackRules() ? 'Ready' : 'Missing'; ?></strong>
            </div>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Save cache settings</button>
            <button type="button" class="standard-btn subtle-btn"
                    hx-post="actions/fallback-rebuild.php"
                    hx-vals='{"csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#fx-toast" hx-swap="outerHTML"
                    hx-on::after-request="htmx.ajax('GET','partials/settings-cache.php',{target:'#settings-panel',swap:'innerHTML'})">
                <i class="small fas fa-sync-alt"></i> Rebuild HTML cache
            </button>
        </div>
    </div>
</form>

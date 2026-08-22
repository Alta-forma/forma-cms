<?php
require_once __DIR__ . '/_helpers.php';
$htPath = ROOT_DIR . '/.htaccess';
$exists = is_file($htPath);
$content = $exists ? (string)file_get_contents($htPath) : Htaccess::defaultContent();
$hasSeoRoutes = Htaccess::hasSeoPassthrough($content);
$static = Htaccess::staticSeoFiles();
$staticBlocking = !empty($static['robots']) || !empty($static['sitemap']);
?>
<?php echo fx_panel_header('shield-alt', 'Server', '.htaccess and environment'); ?>

<div class="settings-card">
    <h3><i class="fas fa-server"></i> Environment</h3>
    <div class="kv-row"><span class="k">PHP</span><span class="v"><?php echo h(PHP_VERSION); ?></span></div>
    <div class="kv-row"><span class="k">Server</span><span class="v"><?php echo h($_SERVER['SERVER_SOFTWARE'] ?? 'unknown'); ?></span></div>
    <div class="kv-row"><span class="k">SQLite</span><span class="v"><?php echo h(class_exists('SQLite3') ? SQLite3::version()['versionString'] : (extension_loaded('pdo_sqlite') ? 'via PDO' : 'missing')); ?></span></div>
    <div class="kv-row"><span class="k">Database file</span><span class="v"><code><?php echo h(str_replace(ROOT_DIR, '', DB_FILE)); ?></code></span></div>
    <div class="kv-row"><span class="k">mod_rewrite</span><span class="v"><?php echo function_exists('apache_get_modules') ? (in_array('mod_rewrite', apache_get_modules(), true) ? 'enabled' : 'not detected') : 'unknown (check Hosting check)'; ?></span></div>
    <div class="kv-row"><span class="k">Document root</span><span class="v"><code><?php echo h(ROOT_DIR); ?></code></span></div>
</div>

<div class="settings-card <?php echo $staticBlocking && !$hasSeoRoutes ? 'card-warn' : ''; ?>">
    <h3><i class="fas fa-robot"></i> Static SEO files</h3>
    <p class="card-sub">
        If <code>robots.txt</code> or <code>sitemap.xml</code> exist as real files in the site root, Apache serves them and Forma never runs.
        Prefer deleting them and letting Settings → SEO generate both on the fly.
    </p>
    <div class="kv-row">
        <span class="k">robots.txt on disk</span>
        <span class="v"><?php echo !empty($static['robots']) ? '<span class="status-badge warn">yes — may shadow Forma</span>' : '<span class="status-badge ok">no</span>'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">sitemap.xml on disk</span>
        <span class="v"><?php echo !empty($static['sitemap']) ? '<span class="status-badge warn">yes — may shadow Forma</span>' : '<span class="status-badge ok">no</span>'; ?></span>
    </div>
    <div class="kv-row">
        <span class="k">.htaccess SEO routes</span>
        <span class="v"><?php echo $hasSeoRoutes ? '<span class="status-badge ok">robots + sitemap → PHP</span>' : '<span class="status-badge warn">missing</span>'; ?></span>
    </div>
    <div class="card-actions">
        <?php if ($staticBlocking): ?>
        <button type="button" class="standard-btn"
                hx-post="actions/server-fix.php"
                hx-vals='{"action":"remove_static_seo","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML">
            <i class="small fas fa-trash-alt"></i> Delete static robots/sitemap
        </button>
        <?php endif; ?>
        <?php if (!$hasSeoRoutes): ?>
        <button type="button" class="standard-btn"
                hx-post="actions/server-fix.php"
                hx-vals='{"action":"ensure_seo_routes","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML">
            <i class="small fas fa-route"></i> Add SEO routes to .htaccess
        </button>
        <?php endif; ?>
        <?php if (!$staticBlocking && $hasSeoRoutes): ?>
            <span class="hint" style="margin:0">All clear — Forma is serving <code>/robots.txt</code> and <code>/sitemap.xml</code>.</span>
        <?php endif; ?>
    </div>
</div>

<div class="settings-card htaccess-card">
    <h3><i class="fas fa-file-alt"></i> .htaccess</h3>
    <p class="card-sub">
        <?php if ($exists): ?>
            Root <code>.htaccess</code> is present. A bad edit can take the site down — use <strong>Reset to default</strong> or re-upload via FTP if needed.
        <?php else: ?>
            No <code>.htaccess</code> found — the default below will be created when you save.
        <?php endif; ?>
    </p>
    <form class="htaccess-form" hx-post="actions/htaccess-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="htaccess-editor-wrap">
            <textarea name="content" class="code-editor htaccess-editor" data-mode="null" data-cm="fill" rows="24"><?php echo h($content); ?></textarea>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Write .htaccess</button>
            <button type="button" class="standard-btn"
                    onclick="var f=this.closest('form');var ta=f.querySelector('textarea[name=content]');var v=<?php echo htmlspecialchars(json_encode(Htaccess::defaultContent()), ENT_QUOTES); ?>;if(ta&&ta.codemirror){ta.codemirror.setValue(v);ta.codemirror.save();}else if(ta){ta.value=v;}">
                <i class="small fas fa-undo"></i> Reset to default
            </button>
        </div>
    </form>
</div>

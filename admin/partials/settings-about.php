<?php
require_once __DIR__ . '/_helpers.php';
$st = License::status();
$php = PHP_VERSION;
$sqlite = class_exists('SQLite3') ? SQLite3::version()['versionString'] ?? '' : '';
?>
<?php echo fx_panel_header('info-circle', 'About', 'This Forma install — version, what’s included, and updates'); ?>

<div class="settings-card">
    <h3><i class="fas fa-cube"></i> <?php echo h(FORMA_PRODUCT); ?></h3>
    <p class="card-sub">
        A portable PHP + SQLite CMS. Pages, blog, snippets, media, and SEO live in one database file
        you can back up and move. The admin is server-rendered (htmx). Agents talk to it over the HTTP API —
        start at <code>GET /api/v1/help</code>.
    </p>
    <div class="kv-row"><span class="k">Product</span><span class="v"><?php echo h(FORMA_PRODUCT); ?></span></div>
    <div class="kv-row"><span class="k">Version</span><span class="v"><code><?php echo h(FORMA_VERSION); ?></code> · <?php echo h(FORMA_VERSION_DATE); ?></span></div>
    <div class="kv-row"><span class="k">Schema</span><span class="v">v<?php echo (int)FORMA_SCHEMA_VERSION; ?></span></div>
    <div class="kv-row"><span class="k">PHP</span><span class="v"><?php echo h($php); ?></span></div>
    <?php if ($sqlite !== ''): ?>
    <div class="kv-row"><span class="k">SQLite</span><span class="v"><?php echo h($sqlite); ?></span></div>
    <?php endif; ?>
    <div class="kv-row"><span class="k">This site</span><span class="v"><?php echo h(forma_site_title()); ?></span></div>
</div>

<div class="settings-card">
    <h3><i class="fas fa-check"></i> Included</h3>
    <p class="card-sub">No license required for day-to-day publishing.</p>
    <ul class="about-list">
        <li>Pages (HTML, Twig, or Markdown) with per-page SEO</li>
        <li>Blog with RSS / JSON feeds</li>
        <li>Snippets and shortcodes</li>
        <li>Uploads / media library</li>
        <li>Sitewide SEO: robots.txt, sitemap, Open Graph, JSON-LD</li>
        <li>Agent API + scoped tokens (Settings → Agents)</li>
        <li>Versioned site packages (Settings → Backup)</li>
    </ul>
</div>

<div class="settings-card">
    <h3><i class="fas fa-key"></i> Licensed modules</h3>
    <p class="card-sub">Activate keys under Settings → License. The Podcast nav stays hidden until a key is active.</p>
    <div class="kv-row">
        <span class="k">Podcast</span>
        <span class="v">
            <?php if ($st['licensed']): ?>
                <span class="status-badge ok"><i class="fas fa-check-circle"></i> Unlocked</span>
            <?php else: ?>
                <span class="status-badge off"><i class="fas fa-lock"></i> Locked</span>
            <?php endif; ?>
        </span>
    </div>
    <p class="hint" style="margin-top:.75rem">
        Podcast hosting (episodes, feeds, Apple/Spotify directories) is a paid unlock.
        Forms relay is a separate license — AltaForma-hosted sites include it; self-serve can buy a key or bring your own (Resend, Formspree).
    </p>
</div>

<div class="settings-card">
    <h3><i class="fas fa-cloud-download-alt"></i> Updates</h3>
    <p class="card-sub">
        Future Forma releases will ship as tagged zip packages. In-app update from this screen is not wired yet —
        you still replace PHP files (or restore a site package) yourself.
    </p>
    <div class="card-actions">
        <button type="button" class="standard-btn" disabled title="Coming soon">
                    <i class="small fas fa-cloud-download-alt"></i> Check for updates
        </button>
    </div>
    <span class="hint">Button is a placeholder. Don’t yank <code>database/</code> or <code>uploads/</code> when you do update the app.</span>
</div>

<div class="settings-card">
    <h3><i class="fas fa-book"></i> Docs</h3>
    <p class="card-sub">How this install is meant to be driven.</p>
    <ul class="about-list">
        <li><a href="https://forma-cms.me" target="_blank" rel="noopener noreferrer">forma-cms.me</a> — product site</li>
        <li><code>AGENTS.md</code> in the project root — Agent API map</li>
        <li>Admin → Settings → Hosting check — PHP / rewrite / permissions</li>
        <li><code>GET /up</code> — PHP heartbeat; <code>/fallback/php-ok.json</code> if FastCGI is dead</li>
        <li><code>GET /search?q=…</code> — built-in site search (Settings → Cache & Publish shows the engine in use)</li>
        <li>Settings → Cache & Publish — turn on Publish mode so Apache can serve the site as files</li>
    </ul>
</div>

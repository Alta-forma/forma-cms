<?php
require_once __DIR__ . '/_helpers.php';
$dbSize = file_exists(DB_FILE) ? filesize(DB_FILE) : 0;
$dbSizeH = $dbSize > 1048576 ? round($dbSize / 1048576, 1) . ' MB' : round($dbSize / 1024) . ' KB';
$up = SitePackage::uploadsStats();
$upH = $up['bytes'] > 1048576 ? round($up['bytes'] / 1048576, 1) . ' MB' : round($up['bytes'] / 1024) . ' KB';
$hasZip = class_exists('ZipArchive');
?>
<?php echo fx_panel_header('download', 'Backup', 'Versioned site packages for safe restores & future migrations'); ?>

<div class="settings-card">
    <h3><i class="fas fa-archive"></i> Full site package</h3>
    <p class="card-sub">
        Recommended. Zip includes <code>manifest.json</code> (format + schema versions), a SQLite snapshot,
        portable <code>data.json</code>, and your entire <code>uploads/</code> folder —
        so a future Forma can rebuild this site properly.
    </p>
    <div class="kv-row"><span class="k">Format</span><span class="v"><code>formax-site</code> v<?php echo (int)SitePackage::FORMAT_VERSION; ?></span></div>
    <div class="kv-row"><span class="k">Schema</span><span class="v">v<?php echo (int)SitePackage::SCHEMA_VERSION; ?> · app <?php echo h(FORMA_VERSION); ?></span></div>
    <div class="kv-row"><span class="k">Database</span><span class="v"><?php echo h($dbSizeH); ?></span></div>
    <div class="kv-row"><span class="k">Uploads</span><span class="v"><?php echo (int)$up['files']; ?> files · <?php echo h($upH); ?></span></div>

    <?php if ($hasZip): ?>
    <a class="action-tile" href="actions/export-site.php" style="margin-top:1rem">
        <i class="fas fa-file-archive tile-icon"></i>
        <div class="tile-text">
            <strong>Download site package (.zip)</strong>
            <span>DB + uploads + versioned manifest — use this before migrations or host moves</span>
        </div>
    </a>
    <?php else: ?>
    <p class="hint" style="color:#f87171;margin-top:1rem">PHP <code>ZipArchive</code> is missing on this server — ask the host to enable the zip extension, or use the JSON + separate uploads backup below.</p>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-file-import"></i> Restore site package</h3>
    <p class="card-sub">Uploads a <code>formax-site-*.zip</code>. Current DB is copied to <code>forma.db.bak-…</code> before replace. Uploads are merged (existing files overwritten by name).</p>
    <?php if ($hasZip): ?>
    <form hx-post="actions/import-site.php" hx-encoding="multipart/form-data" hx-target="#fx-toast" hx-swap="outerHTML"
          hx-confirm="Replace this site’s database from the package? A .bak copy is kept. Continue?">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group">
            <label>Site package (.zip)</label>
            <input type="file" name="package" accept=".zip,application/zip" required>
        </div>
        <?php echo fx_switch('replace_database', true, 'Replace database from package', 'Off = merge content from data.json only'); ?>
        <?php echo fx_switch('merge_uploads', true, 'Merge uploads folder', ''); ?>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-file-import"></i> Import package</button>
        </div>
    </form>
    <?php else: ?>
    <p class="hint">Import requires ZipArchive.</p>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-cloud-download-alt"></i> Partial exports</h3>
    <p class="card-sub">Lighter options — not enough alone for a full future restore.</p>

    <a class="action-tile" href="actions/export-db.php">
        <i class="fas fa-database tile-icon"></i>
        <div class="tile-text">
            <strong>Download SQLite database only</strong>
            <span>The .db file (<?php echo h($dbSizeH); ?>) — no images</span>
        </div>
    </a>

    <a class="action-tile" href="actions/export-json.php">
        <i class="fas fa-file-code tile-icon"></i>
        <div class="tile-text">
            <strong>Export JSON only</strong>
            <span>Pages, posts, snippets, episodes, redirects, settings — no binary uploads</span>
        </div>
    </a>
</div>

<div class="settings-card">
    <h3><i class="fas fa-robot"></i> Agent / API</h3>
    <p class="card-sub" style="margin-bottom:.5rem">With a token that has <code>backup:read</code>:</p>
    <?php echo fx_url_pill('GET /api/v1/export/site  →  zip stream'); ?>
    <?php echo fx_url_pill('GET /api/v1/export  →  JSON (versioned)'); ?>
</div>

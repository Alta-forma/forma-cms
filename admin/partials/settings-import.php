<?php
require_once __DIR__ . '/_helpers.php';
?>
<?php echo fx_panel_header('file-import', 'Import', 'Bring an older Forma site into this install'); ?>

<div class="settings-card">
    <h3><i class="fas fa-file-upload"></i> JSON export</h3>
    <p class="card-sub">From the old site: Settings → Backup → Export JSON, then upload the file here. Pages, posts, episodes, snippets, and settings are imported. Existing slugs are skipped, never overwritten.</p>
    <form hx-post="actions/import-formalite.php" hx-encoding="multipart/form-data" hx-target="#fx-toast" hx-swap="outerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group">
            <label>JSON export file</label>
            <input type="file" name="json_file" accept=".json,application/json" required>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-file-import"></i> Import</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <h3><i class="fas fa-terminal"></i> CLI alternative</h3>
    <p class="card-sub">On the server, you can import straight from an older Forma SQLite file.</p>
    <?php echo fx_url_pill('php tools/import-formalite.php --db /path/to/formalite.db'); ?>
</div>

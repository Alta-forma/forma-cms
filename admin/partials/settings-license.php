<?php
require_once __DIR__ . '/_helpers.php';
$st = License::status();
?>
<?php echo fx_panel_header('key', 'License', 'Unlock the podcast module'); ?>

<div class="settings-card">
    <h3><i class="fas fa-podcast"></i> Podcast unlock</h3>
    <div class="kv-row">
        <span class="k">Status</span>
        <span class="v">
            <?php if ($st['licensed']): ?>
                <span class="status-badge ok"><i class="fas fa-check-circle"></i> Active</span>
            <?php else: ?>
                <span class="status-badge off"><i class="fas fa-lock"></i> Locked</span>
            <?php endif; ?>
        </span>
    </div>
    <?php if ($st['licensed']): ?>
    <div class="kv-row">
        <span class="k">Type</span>
        <span class="v"><?php echo h(ucfirst($st['license_type'] ?: '—')); ?></span>
    </div>
    <?php if (!empty($st['valid_until'])): ?>
    <div class="kv-row">
        <span class="k">Valid until</span>
        <span class="v"><?php echo h(date('M j, Y', (int)$st['valid_until'])); ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-unlock-alt"></i> Activate</h3>
    <p class="card-sub">Perpetual keys look like FX-PERP-XXXXXXXX-XXXX. Older FL- keys also work.</p>
    <form hx-post="actions/license-activate.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group">
            <label>License key</label>
            <input type="text" name="license_key" placeholder="FX-PERP-XXXXXXXX-XXXX" style="font-family:monospace">
            <span class="hint">Local development unlock: FX-DEV-LOCAL</span>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-key"></i> Activate</button>
        </div>
    </form>
</div>

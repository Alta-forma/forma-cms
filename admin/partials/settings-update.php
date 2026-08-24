<?php
require_once __DIR__ . '/_helpers.php';
$refresh = !empty($_GET['refresh']) || !empty($fxUpdateRefresh);
$st = Updater::status($refresh);
$pre = $st['preflight'];
$latest = $st['latest'];
$last = $st['last'];
$csrf = Auth::csrf();
$glow = !$pre['ok']
    ? 'card-glow-fail'
    : ($st['update_available'] ? 'card-glow-warn' : 'card-glow-pass');
$lastFailed = is_array($last) && empty($last['ok']);
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('cube', 'Forma core', 'Updates the CMS. Pages, posts, uploads, and settings stay put.'); ?>

<div class="settings-card <?php echo h($glow); ?>">
    <h3><i class="fas fa-cube"></i> Forma core</h3>
    <p class="card-sub">This updates Forma itself — the editor and engine. It does not change your content.</p>
    <div class="kv-row"><span class="k">This site</span><span class="v"><code><?php echo h($st['installed']); ?></code><?php echo $st['installed_date'] !== '' ? ' · ' . h($st['installed_date']) : ''; ?></span></div>
    <?php if (!empty($latest['ok'])): ?>
    <div class="kv-row"><span class="k">Latest</span><span class="v"><code><?php echo h($latest['version']); ?></code></span></div>
    <?php else: ?>
    <div class="kv-row"><span class="k">Latest</span><span class="v"><span class="status-badge warn">couldn’t check</span> <?php echo h($latest['error'] ?: 'Try again in a moment'); ?></span></div>
    <?php endif; ?>
    <div class="kv-row">
        <span class="k">Status</span>
        <span class="v">
            <?php if ($st['update_available']): ?>
                <span class="status-badge warn"><i class="fas fa-arrow-up"></i> Update available</span>
            <?php elseif ($st['current']): ?>
                <span class="status-badge ok"><i class="fas fa-check-circle"></i> Up to date</span>
            <?php else: ?>
                <span class="status-badge off">Couldn’t compare</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-get="partials/settings-update.php?refresh=1"
                hx-target="#settings-panel"
                hx-swap="innerHTML">
            <i class="fas fa-sync-alt"></i> Check for updates
        </button>
        <?php if ($st['update_available'] && $pre['ok']): ?>
        <button type="button" class="standard-btn"
                hx-post="actions/cms-update.php"
                hx-vals='{"do":"apply","csrf_token":"<?php echo h($csrf); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML"
                hx-confirm="Update Forma core to <?php echo h($latest['tag']); ?>?

This replaces the CMS only. Pages, posts, uploads, and settings are not touched.
A backup of Forma core is saved first, so you can undo if something looks wrong.

Continue?">
            <i class="fas fa-cloud-download-alt"></i> Update Forma core
        </button>
        <?php endif; ?>
    </div>
    <?php if (!$st['update_available'] && $st['current']): ?>
        <p class="hint" style="margin:.85rem 0 0">You’re on the latest Forma core. Check again when you hear there’s a new release.</p>
    <?php endif; ?>
</div>

<?php if (!empty($st['backups'])): ?>
<div class="settings-card">
    <h3><i class="fas fa-undo"></i> Undo last update</h3>
    <p class="card-sub">Forma saves a copy of the core before each update. Your content is not in that copy.</p>
    <?php
    $latestBak = $st['backups'][0];
    ?>
    <div class="kv-row">
        <span class="k">Saved</span>
        <span class="v"><?php echo h(date('M j, Y · H:i', (int)$latestBak['mtime'])); ?> · <?php echo h(Updater::bytes((int)$latestBak['bytes'])); ?></span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-post="actions/cms-update.php"
                hx-vals='{"do":"rollback","csrf_token":"<?php echo h($csrf); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML"
                hx-confirm="Undo the last Forma core update? Pages, posts, and uploads stay as they are. Continue?">
            <i class="fas fa-undo"></i> Undo last update
        </button>
    </div>
</div>
<?php else: ?>
<div class="settings-card">
    <h3><i class="fas fa-undo"></i> Undo last update</h3>
    <p class="card-sub">Before an update, Forma saves a copy of the core. After the first update, you can restore it from here if something breaks.</p>
</div>
<?php endif; ?>

<?php if (!$pre['ok']): ?>
<div class="settings-card card-glow-fail">
    <h3><i class="fas fa-exclamation-circle"></i> Can’t update right now</h3>
    <p class="card-sub"><?php echo h($pre['message']); ?></p>
    <?php if (!empty($pre['checks'])): ?>
    <ul class="about-list">
        <?php foreach ($pre['checks'] as $c): ?>
            <li><?php echo h($c); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!empty($latest['ok']) && trim((string)$latest['notes']) !== '' && $st['update_available']): ?>
<div class="settings-card">
    <h3><i class="fas fa-scroll"></i> What’s new</h3>
    <pre style="white-space:pre-wrap;font-size:.85rem;max-height:16rem;overflow:auto"><?php echo h(trim((string)$latest['notes'])); ?></pre>
</div>
<?php endif; ?>

<?php if ($lastFailed): ?>
<div class="settings-card card-glow-fail">
    <h3><i class="fas fa-history"></i> Last update failed</h3>
    <p class="card-sub"><?php echo h((string)($last['message'] ?? 'The last update did not finish.')); ?></p>
    <?php if (!empty($last['log']) && is_array($last['log'])): ?>
        <ul class="about-list" style="margin-top:.75rem">
            <?php foreach ($last['log'] as $line): ?>
                <li><?php echo h((string)$line); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
fx_settings_scroll_close();

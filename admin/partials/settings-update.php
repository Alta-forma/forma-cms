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
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('cloud-download-alt', 'Update', 'Install a published Forma release. Never git main. Never your content.'); ?>

<div class="settings-card <?php echo h($glow); ?>">
    <h3><i class="fas fa-cube"></i> This install vs the world</h3>
    <p class="card-sub">
        Source of truth is a <strong>GitHub Release</strong> on
        <a href="https://github.com/<?php echo h(Updater::REPO); ?>/releases" target="_blank" rel="noopener"><code><?php echo h(Updater::REPO); ?></code></a>.
        <code>main</code> can be mid-work. This button will not install it.
    </p>
    <div class="kv-row"><span class="k">Running</span><span class="v"><code><?php echo h($st['installed']); ?></code><?php echo $st['installed_date'] !== '' ? ' · ' . h($st['installed_date']) : ''; ?></span></div>
    <?php if (!empty($latest['ok'])): ?>
    <div class="kv-row"><span class="k">Latest release</span><span class="v"><code><?php echo h($latest['version']); ?></code> · <a href="<?php echo h($latest['url']); ?>" target="_blank" rel="noopener"><?php echo h($latest['tag']); ?></a></span></div>
    <?php else: ?>
    <div class="kv-row"><span class="k">Latest release</span><span class="v"><span class="status-badge warn">not found</span> <?php echo h($latest['error'] ?: 'No release published yet'); ?></span></div>
    <?php endif; ?>
    <div class="kv-row">
        <span class="k">Status</span>
        <span class="v">
            <?php if ($st['update_available']): ?>
                <span class="status-badge warn"><i class="fas fa-arrow-up"></i> Update available</span>
            <?php elseif ($st['current']): ?>
                <span class="status-badge ok"><i class="fas fa-check-circle"></i> On the latest release</span>
            <?php else: ?>
                <span class="status-badge off">Cannot compare</span>
            <?php endif; ?>
        </span>
    </div>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-get="partials/settings-update.php?refresh=1"
                hx-target="#settings-panel"
                hx-swap="innerHTML">
            <i class="fas fa-sync-alt"></i> Check GitHub again
        </button>
        <?php if ($st['update_available'] && $pre['ok']): ?>
        <button type="button" class="standard-btn"
                hx-post="actions/cms-update.php"
                hx-vals='{"do":"apply","csrf_token":"<?php echo h($csrf); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML"
                hx-confirm="Update this CMS from GitHub release <?php echo h($latest['tag']); ?>?

This replaces Forma PHP / admin / lib files only.
It will NOT touch database/, uploads/, feeds/, fallback/, .htaccess, or license secrets.
A backup of the current app files is saved first (Settings → Update → Rollback).

Continue?">
            <i class="fas fa-cloud-download-alt"></i> Update Forma
        </button>
        <?php endif; ?>
    </div>
    <?php if (!$st['update_available'] && $st['current']): ?>
        <p class="hint" style="margin:.85rem 0 0">When AltaForma publishes the next GitHub Release, this button installs it. Do not rsync <code>main</code> onto live sites.</p>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-shield-alt"></i> What Update never touches</h3>
    <p class="card-sub">SQLite is the site. The PHP tree is the app. Mixing those up is how sites die.</p>
    <ul class="about-list">
        <li><code>database/</code> — forma.db, this updater’s own backups</li>
        <li><code>uploads/</code> — media</li>
        <li><code>feeds/</code> and <code>fallback/</code> — generated</li>
        <li><code>.htaccess</code> — live vhost rules (preview bypasses, etc.)</li>
        <li><code>.env</code>, <code>config.local.php</code>, <code>lib/LicenseHMACSecret.hex</code></li>
        <li><code>tools/watch-sites.txt</code>, <code>mcp/node_modules/</code></li>
    </ul>
    <p class="hint" style="margin:.85rem 0 0">
        After a release that changes rewrite rules, read the notes, then decide whether Settings → Server → Write .htaccess is safe for <em>this</em> vhost. Default: leave .htaccess alone.
    </p>
</div>

<div class="settings-card <?php echo $pre['ok'] ? '' : 'card-glow-fail'; ?>">
    <h3><i class="fas fa-clipboard-check"></i> Preflight</h3>
    <p class="card-sub"><?php echo h($pre['message']); ?></p>
    <ul class="about-list">
        <?php foreach ($pre['checks'] as $c): ?>
            <li><?php echo h($c); ?></li>
        <?php endforeach; ?>
    </ul>
</div>

<?php if (!empty($latest['ok']) && trim((string)$latest['notes']) !== ''): ?>
<div class="settings-card">
    <h3><i class="fas fa-scroll"></i> Release notes</h3>
    <pre style="white-space:pre-wrap;font-size:.85rem;max-height:16rem;overflow:auto"><?php echo h(trim((string)$latest['notes'])); ?></pre>
</div>
<?php endif; ?>

<?php if (!empty($st['backups'])): ?>
<div class="settings-card">
    <h3><i class="fas fa-undo"></i> App-file backups</h3>
    <p class="card-sub">Taken automatically before each Update. Content is not in these zips — use Settings → Backup for that.</p>
    <?php foreach (array_slice($st['backups'], 0, 5) as $i => $b): ?>
        <div class="kv-row">
            <span class="k"><?php echo $i === 0 ? 'Latest' : 'Older'; ?></span>
            <span class="v"><code><?php echo h($b['name']); ?></code> · <?php echo h(Updater::bytes((int)$b['bytes'])); ?> · <?php echo h(date('Y-m-d H:i', (int)$b['mtime'])); ?></span>
        </div>
    <?php endforeach; ?>
    <div class="card-actions">
        <button type="button" class="standard-btn"
                hx-post="actions/cms-update.php"
                hx-vals='{"do":"rollback","csrf_token":"<?php echo h($csrf); ?>"}'
                hx-target="#settings-panel"
                hx-swap="innerHTML"
                hx-confirm="Restore the last app-file backup? Pages, posts, uploads, and .htaccess stay as they are. Continue?">
            <i class="fas fa-undo"></i> Rollback last app backup
        </button>
    </div>
</div>
<?php endif; ?>

<?php if (is_array($last)): ?>
<div class="settings-card">
    <h3><i class="fas fa-history"></i> Last run</h3>
    <div class="kv-row"><span class="k">Result</span><span class="v"><?php echo !empty($last['ok']) ? '<span class="status-badge ok">ok</span>' : '<span class="status-badge warn">failed</span>'; ?></span></div>
    <div class="kv-row"><span class="k">Message</span><span class="v"><?php echo h((string)($last['message'] ?? '')); ?></span></div>
    <?php if (!empty($last['log']) && is_array($last['log'])): ?>
        <ul class="about-list" style="margin-top:.75rem">
            <?php foreach ($last['log'] as $line): ?>
                <li><?php echo h((string)$line); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="settings-card">
    <h3><i class="fas fa-bullhorn"></i> Shipping Forma (AltaForma)</h3>
    <p class="card-sub">Sites cannot click Update until a release exists, and until they have this pane (one bootstrap of 0.2.0+).</p>
    <ol class="about-list" style="padding-left:1.1rem">
        <li>Finish the work on a branch. Merge to <code>main</code> only when it should go to every install.</li>
        <li>Bump <code>version.php</code> (semver). That number is what this button compares.</li>
        <li>Run <code>./tools/release.sh</code> — it tags <code>vX.Y.Z</code> and creates the GitHub Release. If it fails, do not rsync anything.</li>
        <li>On each live install: Settings → Update → <strong>Update Forma</strong>. First-time old sites still need one code drop of 0.2.0+ to get this button.</li>
        <li>Never rsync <code>database/</code>, <code>uploads/</code>, or a customized <code>.htaccess</code>.</li>
    </ol>
    <p class="hint" style="margin:.85rem 0 0">Full checklist: <code>docs/RELEASE.md</code> in the repo.</p>
</div>
<?php
fx_settings_scroll_close();

<?php
require_once __DIR__ . '/_helpers.php';
$user = Auth::user();
$tokens = Agent::listTokens();
$once = $_GET['new_token'] ?? '';
$scopeInfo = [
    'content:read'   => 'Read pages, posts, and snippets',
    'content:write'  => 'Create and update pages, posts, and snippets',
    'media:write'    => 'Upload and delete files',
    'settings:write' => 'Change site settings',
    'backup:read'    => 'Download site packages, database, and JSON exports',
    'podcast:write'  => 'Manage podcast episodes',
];
fx_settings_scroll_open();
?>
<?php echo fx_panel_header('user-lock', 'Access', 'Admin login and Agent API tokens'); ?>

<?php if ($once): ?>
<div class="settings-card card-glow-pass" style="border-color:var(--primary)">
    <h3><i class="fas fa-exclamation-circle"></i> Copy your new token now</h3>
    <p class="card-sub">It is stored hashed and will never be shown again.</p>
    <?php echo fx_url_pill($once); ?>
</div>
<?php endif; ?>

<form id="fx-account-form" hx-post="actions/account-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="settings-card">
        <h3><i class="fas fa-id-badge"></i> Admin login</h3>
        <p class="card-sub">Your current password is required for any account change.</p>
        <?php if (Auth::usesDefaultPassword()): ?>
        <p class="hint" style="color:#ffcdd2;margin:.5rem 0 1rem">This install is still on the default password. The red bar will not go away until you set a real one below.</p>
        <?php endif; ?>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="<?php echo h($user); ?>" required autocomplete="username">
        </div>
        <div class="form-group">
            <label>Current password</label>
            <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label>New password</label>
            <input type="password" name="new_password" minlength="8" autocomplete="new-password">
            <span class="hint">At least 8 characters. Leave blank to keep your current password.</span>
        </div>
    </div>
</form>

<div class="settings-card">
    <h3><i class="fas fa-plus-circle"></i> Create token</h3>
    <p class="card-sub">Let Cursor and AI tools manage this site over HTTPS. Grant only the scopes the agent actually needs.</p>
    <form hx-post="actions/agent-token-create.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group">
            <label>Token name</label>
            <input type="text" name="name" placeholder="Cursor — Chris’s laptop" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>Scopes</label>
            <?php foreach (Agent::SCOPES as $scope): ?>
            <div class="switch-row">
                <div class="sw-text">
                    <strong style="font-family:monospace;font-size:.85rem"><?php echo h($scope); ?></strong>
                    <span class="hint"><?php echo h($scopeInfo[$scope] ?? ''); ?></span>
                </div>
                <label class="fx-switch">
                    <input type="checkbox" name="scopes[]" value="<?php echo h($scope); ?>"
                        <?php echo in_array($scope, ['content:read', 'content:write'], true) ? 'checked' : ''; ?>>
                    <span class="track"></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-key"></i> Create token</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <h3><i class="fas fa-list-ul"></i> Active tokens</h3>
    <?php if (!$tokens): ?>
    <p class="card-sub" style="margin-bottom:0">No tokens yet. Create one above to connect an agent.</p>
    <?php else: ?>
    <table class="token-table">
        <tr><th>Name</th><th>Scopes</th><th>Last used</th><th></th></tr>
        <?php foreach ($tokens as $t): ?>
        <tr class="<?php echo $t['revoked_at'] ? 'revoked' : ''; ?>">
            <td><?php echo h($t['name']); ?></td>
            <td>
                <?php foreach ((json_decode($t['scopes'] ?: '[]', true) ?: []) as $sc): ?>
                <span class="scope-pill"><?php echo h($sc); ?></span>
                <?php endforeach; ?>
            </td>
            <td><?php echo $t['last_used'] ? h(date('M j, H:i', (int)$t['last_used'])) : '—'; ?></td>
            <td style="text-align:right">
                <?php if (!$t['revoked_at']): ?>
                <button type="button" class="delete-btn" style="min-width:auto;padding:4px 10px"
                        hx-post="actions/agent-token-revoke.php"
                        hx-vals='{"id":"<?php echo (int)$t['id']; ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                        hx-target="#settings-panel" hx-swap="innerHTML"
                        hx-confirm="Revoke this token? Agents using it will lose access immediately.">Revoke</button>
                <?php else: ?><span class="hint">revoked</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="settings-card">
    <h3><i class="fas fa-plug"></i> Connecting</h3>
    <div class="kv-row"><span class="k">Base URL</span><span class="v"><code>/api/v1/</code></span></div>
    <div class="kv-row"><span class="k">Auth header</span><span class="v"><code>Authorization: Bearer fx_…</code></span></div>
    <div class="kv-row"><span class="k">Cursor MCP</span><span class="v"><code>mcp/README.md</code> in the Forma repo</span></div>
</div>
<?php
fx_settings_scroll_close();
echo fx_settings_footer('fx-account-form', 'Update account');

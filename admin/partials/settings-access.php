<?php
require_once __DIR__ . '/_helpers.php';
$user = Auth::user();
$tokens = Agent::listTokens();
$once = $_GET['new_token'] ?? '';
$checkpoint = AgentCheckpoint::status();
$checkpointMessage = (string)($_GET['checkpoint_message'] ?? '');
$checkpointError = (string)($_GET['checkpoint_error'] ?? '');
$siteUrl = rtrim(forma_public_url('/'), '/');
$apiUrl = $siteUrl . '/api/v1/';
$openapiUrl = $siteUrl . '/api/v1/openapi.json';
$mcpUrl = $siteUrl . '/api/v1/mcp';
$scopeInfo = [
    'content:read'   => 'Read pages, posts, and snippets',
    'content:write'  => 'Create and update pages, posts, and snippets',
    'content:delete' => 'Delete pages, posts, and snippets',
    'media:write'    => 'Upload files',
    'media:delete'   => 'Delete uploaded files',
    'site:write'     => 'Change public site identity and SEO (not security or imports)',
    'rollback:write' => 'Restore or accept the single last-known-good site',
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
    <p class="hint">Keep it copied, then <a href="#fx-chatbot-connect">continue to connection details</a>. Paste it only in an authentication field, never ordinary chat text.</p>
</div>
<?php endif; ?>

<?php if ($checkpointMessage !== ''): ?>
<div class="settings-card card-glow-pass"><p class="settings-notice"><?php echo h($checkpointMessage); ?></p></div>
<?php elseif ($checkpointError !== ''): ?>
<div class="settings-card card-glow-fail"><p class="settings-notice fail"><?php echo h($checkpointError); ?></p></div>
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

<div class="settings-card card-glow-pass">
    <h3><i class="fas fa-comments"></i> Connect a subscription chatbot</h3>
    <p class="card-sub">Creates the recommended Site editor token: content, uploads, public site details, SEO, and one-button rollback — no security settings, imports, or backups.</p>
    <form hx-post="actions/agent-token-create.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <input type="hidden" name="preset" value="site-editor">
        <div class="form-group">
            <label>Token name</label>
            <input type="text" name="name" value="Chatbot site editor" required>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-wand-magic-sparkles"></i> Create Site editor token</button>
        </div>
    </form>
</div>

<div class="settings-card <?php echo $checkpoint['pending'] ? 'card-glow-warn' : 'card-glow-pass'; ?>">
    <h3><i class="fas fa-rotate-left"></i> Last known good site</h3>
    <p class="card-sub"><?php echo h((string)$checkpoint['message']); ?></p>
    <div class="kv-row"><span class="k">Status</span><span class="v">
        <span class="status-badge <?php echo $checkpoint['pending'] ? 'warn' : 'ok'; ?>">
            <?php echo $checkpoint['pending'] ? 'Agent edits in progress' : ($checkpoint['available'] ? 'Current site marked good' : 'Armed for first edit'); ?>
        </span>
    </span></div>
    <?php if (!empty($checkpoint['created_at'])): ?>
    <div class="kv-row"><span class="k">Rollback point</span><span class="v"><?php echo h(date('M j, Y · H:i', (int)$checkpoint['created_at'])); ?></span></div>
    <?php endif; ?>
    <div class="card-actions">
        <button type="button" class="delete-btn"
                hx-post="actions/agent-checkpoint.php"
                hx-vals='{"checkpoint_action":"restore","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#settings-panel" hx-swap="innerHTML"
                <?php echo !$checkpoint['available'] ? 'disabled' : ''; ?>
                hx-confirm="Put the site back to the last known good point? Current agent edits will be lost.">
            <i class="small fas fa-rotate-left"></i> Put it back
        </button>
        <button type="button" class="standard-btn"
                hx-post="actions/agent-checkpoint.php"
                hx-vals='{"checkpoint_action":"accept","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                hx-target="#settings-panel" hx-swap="innerHTML"
                hx-confirm="Mark the current live site as good? Future rollback will return here.">
            <i class="small fas fa-check"></i> This looks good
        </button>
    </div>
</div>

<div class="settings-card">
    <h3><i class="fas fa-plus-circle"></i> Create custom token</h3>
    <p class="card-sub">Advanced: choose scopes manually for Cursor or a custom integration. Use the Site editor preset above for subscription chatbots.</p>
    <form hx-post="actions/agent-token-create.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <div class="form-group">
            <label>Token name</label>
            <input type="text" name="name" placeholder="Cursor — Chris’s laptop" required>
        </div>
        <div class="form-group" style="margin-bottom:0">
            <label>Scopes</label>
            <p class="hint">Only read access is selected by default. Add exactly the write/delete/admin scopes this custom integration needs.</p>
            <?php foreach (Agent::SCOPES as $scope): ?>
            <div class="switch-row">
                <div class="sw-text">
                    <strong style="font-family:monospace;font-size:.85rem"><?php echo h($scope); ?></strong>
                    <span class="hint"><?php echo h($scopeInfo[$scope] ?? ''); ?></span>
                </div>
                <label class="fx-switch">
                    <input type="checkbox" name="scopes[]" value="<?php echo h($scope); ?>"
                        <?php echo $scope === 'content:read' ? 'checked' : ''; ?>>
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

<div class="settings-card" id="fx-chatbot-connect">
    <h3><i class="fas fa-plug"></i> Connecting AI tools</h3>
    <div class="kv-row"><span class="k">Agent API</span><span class="v"><code><?php echo h($apiUrl); ?></code></span></div>
    <p class="connector-label">ChatGPT Actions schema</p>
    <?php echo fx_url_pill($openapiUrl); ?>
    <p class="connector-label">Remote MCP</p>
    <?php echo fx_url_pill($mcpUrl); ?>
    <div class="kv-row"><span class="k">Auth header</span><span class="v"><code>Authorization: Bearer fx_…</code></span></div>
    <div class="kv-row"><span class="k">Cursor MCP</span><span class="v"><code>mcp/README.md</code> in the Forma repo</span></div>
    <p class="hint">Enter the token in the chatbot’s connector or Action authentication settings. Never paste it into a conversation. Connector names and availability vary by subscription.</p>
    <details class="connector-steps">
        <summary><strong>Cursor — local MCP</strong></summary>
        <ol class="hint">
            <li>Follow <code>mcp/README.md</code> from a downloaded Forma release on the computer running Cursor.</li>
            <li>Set <code>FORMA_X_URL</code> to <code><?php echo h($siteUrl); ?></code> and store this token as <code>FORMA_X_TOKEN</code>.</li>
            <li>Restart Cursor’s MCP servers, then call <code>formax_help</code>.</li>
        </ol>
    </details>
    <details class="connector-steps">
        <summary><strong>ChatGPT — Custom GPT Action</strong></summary>
        <ol class="hint">
            <li>Create or edit a GPT, then open Configure → Actions.</li>
            <li>Import the ChatGPT Actions schema URL shown above.</li>
            <li>Authentication: API key → Bearer. Paste the token once.</li>
            <li>Tell the GPT to call <code>formaHelp</code> before editing.</li>
        </ol>
    </details>
    <details class="connector-steps">
        <summary><strong>Claude, ChatGPT connectors, Grok, or Perplexity — MCP</strong></summary>
        <ol class="hint">
            <li>Add a custom or remote MCP connector using the Remote MCP URL above.</li>
            <li>Choose Token/API key, or add request header <code>Authorization</code>.</li>
            <li>If entering a full header value, use <code>Bearer fx_…</code> (include the space).</li>
            <li>Ask it to call <code>formax_help</code>, inspect the site, and show you each live change.</li>
        </ol>
    </details>
</div>
<?php
fx_settings_scroll_close();
echo fx_settings_footer('fx-account-form', 'Update account');

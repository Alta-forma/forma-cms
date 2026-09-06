<?php
/**
 * Settings → Access. Three ways in, one card each:
 *   1. Admin login    — you, in this browser
 *   2. AI chatbots    — OAuth 2.1 connectors (lib/AgentOAuth.php)
 *   3. API keys       — Bearer tokens for Cursor, scripts, ChatGPT Actions
 * Plus the agent rollback point, which lives here because it's the undo for
 * everything cards 2 and 3 can do.
 *
 * Also reached as settings-agents.php / settings-account.php (aliases).
 */
require_once __DIR__ . '/_helpers.php';
$user = Auth::user();
$tokens = Agent::listTokens();
$connections = AgentOAuth::listConnections();
$once = $_GET['new_token'] ?? '';
$checkpoint = AgentCheckpoint::status();
$checkpointMessage = (string)($_GET['checkpoint_message'] ?? '');
$checkpointError = (string)($_GET['checkpoint_error'] ?? '');
$siteUrl = rtrim(forma_public_url('/'), '/');
$apiUrl = $siteUrl . '/api/v1/';
$openapiUrl = $siteUrl . '/api/v1/openapi.json';
$mcpUrl = $siteUrl . '/api/v1/mcp';
$defaultPassword = Auth::usesDefaultPassword();
$liveConnections = array_values(array_filter($connections, fn($c) => empty($c['revoked_at'])));
$liveTokens = array_values(array_filter($tokens, fn($t) => empty($t['revoked_at'])));
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
<?php echo fx_panel_header('user-lock', 'Access', 'Who and what is allowed to change this site'); ?>

<?php if ($once): ?>
<div class="settings-card card-glow-pass" style="border-color:var(--primary)">
    <h3><i class="fas fa-exclamation-circle"></i> Copy your new API key now</h3>
    <p class="card-sub">It is stored hashed and will never be shown again.</p>
    <?php echo fx_url_pill($once); ?>
    <p class="hint">Paste it only in a tool's authentication field, never into ordinary chat text. Setup steps are under <a href="#fx-api-keys">API keys</a>.</p>
</div>
<?php endif; ?>

<?php if ($checkpointMessage !== ''): ?>
<div class="settings-card card-glow-pass"><p class="settings-notice"><?php echo h($checkpointMessage); ?></p></div>
<?php elseif ($checkpointError !== ''): ?>
<div class="settings-card card-glow-fail"><p class="settings-notice fail"><?php echo h($checkpointError); ?></p></div>
<?php endif; ?>

<form id="fx-account-form" hx-post="actions/account-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="settings-card<?php echo $defaultPassword ? ' card-glow-fail' : ''; ?>">
        <div class="fx-access-head">
            <div class="fx-access-icon"><i class="fas fa-id-badge"></i></div>
            <div class="fx-access-copy">
                <h3>Admin login</h3>
                <p class="card-sub">You, signing in to this admin panel with a username and password.</p>
            </div>
            <span class="status-badge <?php echo $defaultPassword ? 'off' : 'ok'; ?>">
                <?php echo $defaultPassword ? 'Default password' : 'Password set'; ?>
            </span>
        </div>
        <?php if ($defaultPassword): ?>
        <p class="hint" style="color:#ffcdd2;margin:0 0 1rem">This install is still on the default password. The red bar will not go away — and AI tools cannot connect — until you set a real one.</p>
        <?php endif; ?>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="<?php echo h($user); ?>" required autocomplete="username">
        </div>
        <div class="form-group">
            <label>Current password</label>
            <input type="password" name="current_password" required autocomplete="current-password">
            <span class="hint">Required for any account change.</span>
        </div>
        <div class="form-group">
            <label>New password</label>
            <input type="password" name="new_password" minlength="8" autocomplete="new-password">
            <span class="hint">At least 8 characters. Leave blank to keep your current password.</span>
        </div>
    </div>
</form>

<div class="settings-card">
    <div class="fx-access-head">
        <div class="fx-access-icon"><i class="fas fa-comments"></i></div>
        <div class="fx-access-copy">
            <h3>AI chatbots</h3>
            <p class="card-sub">Grok, Claude, ChatGPT, or Perplexity. Paste one URL, then approve access in the browser — no key to copy.</p>
        </div>
        <span class="status-badge <?php echo $liveConnections ? 'ok' : ''; ?>">
            <?php echo $liveConnections ? count($liveConnections) . ' connected' : 'None connected'; ?>
        </span>
    </div>
    <p class="connector-label">Remote MCP URL</p>
    <?php echo fx_url_pill($mcpUrl); ?>
    <ol class="hint">
        <li>Add a custom or remote MCP connector in your AI tool.</li>
        <li>Paste the URL above. Leave any advanced OAuth fields alone.</li>
        <li>Sign in to this site when prompted, then click <strong>Allow Site editor</strong>.</li>
    </ol>
    <p class="hint">A connected tool can edit content, upload media, update public SEO, and use rollback. It cannot delete anything, change accounts, import backups, or update Forma core. Access expires hourly and renews itself until you disconnect it.</p>

    <?php if ($connections): ?>
    <p class="connector-label">Connected tools</p>
    <table class="token-table">
        <tr><th>Tool</th><th>Status</th><th>Last used</th><th></th></tr>
        <?php foreach ($connections as $connection): ?>
        <tr class="<?php echo $connection['revoked_at'] ? 'revoked' : ''; ?>">
            <td>
                <?php echo h((string)$connection['client_name']); ?>
                <div class="hint"><?php echo h((string)(parse_url((json_decode((string)$connection['redirect_uris'], true)[0] ?? ''), PHP_URL_HOST) ?: 'OAuth client')); ?></div>
            </td>
            <td><?php echo (int)$connection['active_tokens'] > 0 ? '<span class="status-badge ok">Active</span>' : ($connection['revoked_at'] ? 'Disconnected' : 'Awaiting use'); ?></td>
            <td><?php echo $connection['last_used'] ? h(date('M j, H:i', (int)$connection['last_used'])) : '—'; ?></td>
            <td style="text-align:right">
                <?php if (!$connection['revoked_at']): ?>
                <button type="button" class="delete-btn" style="min-width:auto;padding:4px 10px"
                        hx-post="actions/oauth-client-revoke.php"
                        hx-vals='{"client_id":"<?php echo h((string)$connection['client_id']); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                        hx-target="#settings-panel" hx-swap="innerHTML"
                        hx-confirm="Disconnect this AI tool? Its access will stop working immediately.">Disconnect</button>
                <?php else: ?><span class="hint">disconnected</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>

<div class="settings-card" id="fx-api-keys">
    <div class="fx-access-head">
        <div class="fx-access-icon"><i class="fas fa-key"></i></div>
        <div class="fx-access-copy">
            <h3>API keys</h3>
            <p class="card-sub">Long-lived <code>Bearer fx_…</code> tokens for Cursor, scripts, and ChatGPT Actions — tools that cannot do the browser approval above.</p>
        </div>
        <span class="status-badge <?php echo $liveTokens ? 'ok' : ''; ?>">
            <?php echo $liveTokens ? count($liveTokens) . ' active' : 'No keys'; ?>
        </span>
    </div>
    <form hx-post="actions/agent-token-create.php" hx-target="#settings-panel" hx-swap="innerHTML">
        <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
        <input type="hidden" name="preset" value="site-editor">
        <input type="hidden" name="name" value="Site editor API key">
        <div class="card-actions" style="margin-top:0">
            <button type="submit" class="standard-btn"><i class="small fas fa-key"></i> Create Site editor key</button>
        </div>
    </form>
    <p class="hint">Same non-destructive permissions as a chatbot connection, but it never expires — revoke it here when you are done with it.</p>

    <?php if ($tokens): ?>
    <p class="connector-label">Keys</p>
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
                        hx-confirm="Revoke this key? Anything using it loses access immediately.">Revoke</button>
                <?php else: ?><span class="hint">revoked</span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <details class="connector-steps">
        <summary><strong>Custom key with specific scopes</strong></summary>
        <form hx-post="actions/agent-token-create.php" hx-target="#settings-panel" hx-swap="innerHTML">
            <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
            <div class="form-group">
                <label>Key name</label>
                <input type="text" name="name" placeholder="Cursor — Chris’s laptop" required>
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label>Scopes</label>
                <p class="hint">Only read access is selected by default. Add exactly the write, delete, or admin scopes this integration needs.</p>
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
                <button type="submit" class="standard-btn"><i class="small fas fa-key"></i> Create key</button>
            </div>
        </form>
    </details>

    <details class="connector-steps">
        <summary><strong>Endpoints and per-tool setup</strong></summary>
        <div class="kv-row"><span class="k">Agent API</span><span class="v"><code><?php echo h($apiUrl); ?></code></span></div>
        <div class="kv-row"><span class="k">Header</span><span class="v"><code>Authorization: Bearer fx_…</code></span></div>
        <p class="connector-label">ChatGPT Actions schema</p>
        <?php echo fx_url_pill($openapiUrl); ?>
        <ol class="hint">
            <li><strong>Cursor:</strong> follow <code>mcp/README.md</code> from a Forma release, set <code>FORMA_X_URL</code> to <code><?php echo h($siteUrl); ?></code> and the key as <code>FORMA_X_TOKEN</code>, restart MCP servers, then call <code>formax_help</code>.</li>
            <li><strong>ChatGPT Custom GPT:</strong> Configure → Actions → import the schema URL above, set Authentication to API key → Bearer, then tell the GPT to call <code>formaHelp</code> first.</li>
            <li><strong>Scripts:</strong> <code>GET <?php echo h($apiUrl); ?>help</code> returns every endpoint and field name.</li>
        </ol>
    </details>
</div>

<div class="settings-card <?php echo $checkpoint['pending'] ? 'card-glow-warn' : 'card-glow-pass'; ?>">
    <div class="fx-access-head">
        <div class="fx-access-icon"><i class="fas fa-rotate-left"></i></div>
        <div class="fx-access-copy">
            <h3>Undo for AI edits</h3>
            <p class="card-sub"><?php echo h((string)$checkpoint['message']); ?></p>
        </div>
        <span class="status-badge <?php echo $checkpoint['pending'] ? 'warn' : 'ok'; ?>">
            <?php echo $checkpoint['pending'] ? 'Edits in progress' : ($checkpoint['available'] ? 'Current site marked good' : 'Armed for first edit'); ?>
        </span>
    </div>
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
<?php
fx_settings_scroll_close();
echo fx_settings_footer('fx-account-form', 'Update account');

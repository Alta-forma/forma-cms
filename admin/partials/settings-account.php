<?php
require_once __DIR__ . '/_helpers.php';
$user = Auth::user();
?>
<?php echo fx_panel_header('user-lock', 'Account', 'Admin login credentials'); ?>

<form hx-post="actions/account-save.php" hx-target="#fx-toast" hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="settings-card">
        <h3><i class="fas fa-id-badge"></i> Identity</h3>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" value="<?php echo h($user); ?>" required autocomplete="username">
        </div>
    </div>

    <div class="settings-card">
        <h3><i class="fas fa-key"></i> Password</h3>
        <p class="card-sub">Your current password is required for any account change.</p>
        <div class="form-group">
            <label>Current password</label>
            <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label>New password</label>
            <input type="password" name="new_password" minlength="8" autocomplete="new-password">
            <span class="hint">At least 8 characters. Leave blank to keep your current password.</span>
        </div>
        <div class="card-actions">
            <button type="submit" class="standard-btn"><i class="small fas fa-save"></i> Update account</button>
        </div>
    </div>
</form>

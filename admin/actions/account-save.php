<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
require_once ADMIN_DIR . '/partials/_helpers.php';

$user = Auth::user();
$row = Database::get()->queryOne('SELECT * FROM users WHERE username = ?', [$user]);
if (!$row || !password_verify($_POST['current_password'] ?? '', $row['password_hash'])) {
    echo fx_toast_oob('Current password incorrect');
    exit;
}
$newUser = trim($_POST['username'] ?? $user);
$newPass = $_POST['new_password'] ?? '';
if ($newUser === '') {
    echo fx_toast_oob('Username required');
    exit;
}
if ($newPass !== '') {
    if (strlen($newPass) < 8) {
        echo fx_toast_oob('Password must be 8+ chars');
        exit;
    }
    Database::get()->execute(
        'UPDATE users SET username = ?, password_hash = ? WHERE id = ?',
        [$newUser, password_hash($newPass, PASSWORD_DEFAULT), $row['id']]
    );
} else {
    Database::get()->execute('UPDATE users SET username = ? WHERE id = ?', [$newUser, $row['id']]);
}
$_SESSION['forma_user'] = $newUser;
echo fx_toast_oob('Account updated');

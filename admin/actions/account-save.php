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
if ($newUser !== $user) {
    $taken = Database::get()->queryOne('SELECT id FROM users WHERE username = ? AND id != ?', [$newUser, $row['id']]);
    if ($taken) {
        echo fx_toast_oob('That username is already taken');
        exit;
    }
}
try {
    if ($newPass !== '') {
        if (strlen($newPass) < 8) {
            echo fx_toast_oob('Password must be 8+ chars');
            exit;
        }
        if (strtolower($newPass) === 'admin' || strtolower($newPass) === strtolower($newUser)) {
            echo fx_toast_oob('Pick a password that is not the username and not the stock default');
            exit;
        }
        Database::get()->execute(
            'UPDATE users SET username = ?, password_hash = ? WHERE id = ?',
            [$newUser, password_hash($newPass, PASSWORD_DEFAULT), $row['id']]
        );
    } else {
        Database::get()->execute('UPDATE users SET username = ? WHERE id = ?', [$newUser, $row['id']]);
    }
} catch (Throwable $e) {
    echo fx_toast_oob('Could not update account: ' . $e->getMessage());
    exit;
}
$_SESSION['forma_user'] = $newUser;
echo fx_admin_alerts_html(true);
echo fx_toast_oob('Account updated');

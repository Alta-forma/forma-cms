<?php
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::startSession();

if (Auth::user()) {
    header('Location: index.php');
    exit;
}

$maxAttempts = 5;
$lockoutWindow = 900;
$error = null;
$db = Database::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - $lockoutWindow]);
    $attempts = $db->queryOne('SELECT COUNT(*) as c FROM login_attempts WHERE ip = ?', [$ip]);

    if (($attempts['c'] ?? 0) >= $maxAttempts) {
        $error = 'Too many failed attempts. Please wait 15 minutes.';
    } else {
        $row = $db->queryOne('SELECT password_hash FROM users WHERE username = ?', [$username]);
        if ($row && password_verify($password, $row['password_hash'])) {
            $db->execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            Auth::login($username);
            header('Location: index.php');
            exit;
        }
        $db->execute('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [$ip, time()]);
        $error = 'Invalid username or password';
    }
}

$isDefaultPassword = false;
$row = $db->queryOne('SELECT password_hash FROM users WHERE username = ?', ['admin']);
if ($row && password_verify('admin', $row['password_hash'])) {
    $isDefaultPassword = true;
}
$siteTitle = forma_site_title();
$showProductSub = $siteTitle !== FORMA_PRODUCT;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo htmlspecialchars(forma_admin_base_href(), ENT_QUOTES, 'UTF-8'); ?>">
    <title>Login — <?php echo htmlspecialchars($siteTitle); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/core.css">
</head>
<body>
<div class="login-container">
    <h1><?php echo htmlspecialchars($siteTitle); ?></h1>
    <?php if ($showProductSub): ?>
        <p class="login-sub"><?php echo htmlspecialchars(FORMA_PRODUCT); ?></p>
    <?php endif; ?>
    <?php if ($isDefaultPassword): ?>
        <p style="color:var(--primary);font-size:.9rem;text-align:center">Default login: <code>admin</code> / <code>admin</code> — change it after login.</p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p style="color:var(--error);text-align:center"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>
    <form method="post">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <button type="submit" class="login-btn"><i class="fas fa-right-to-bracket"></i> Sign in</button>
    </form>
</div>
</body>
</html>

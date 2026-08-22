<?php
/**
 * Forma – Session auth + CSRF for the browser admin.
 */
class Auth {
    public static function startSession(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }
        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_path', '/');
        ini_set('session.gc_maxlifetime', '3600');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        session_start();
    }

    public static function requireAdmin(bool $isApi = false): void {
        self::startSession();

        $timeout = 3600;
        try {
            $cfg = Database::get()->getSetting('security');
            $timeout = (int)($cfg['session_lifetime'] ?? 3600);
        } catch (Throwable $e) {
            // DB may not be ready during bootstrap edge cases
        }

        if (isset($_SESSION['forma_last_activity']) && (time() - $_SESSION['forma_last_activity'] > $timeout)) {
            session_unset();
            session_destroy();
            self::deny($isApi, 'Session expired', 401);
        }

        if (empty($_SESSION['forma_user'])) {
            self::deny($isApi, 'Unauthorized', 401);
        }

        $_SESSION['forma_last_activity'] = time();

        if (empty($_SESSION['forma_csrf'])) {
            $_SESSION['forma_csrf'] = bin2hex(random_bytes(32));
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            $token = $_SERVER['HTTP_X_CSRF_TOKEN']
                  ?? $_POST['csrf_token']
                  ?? '';
            if (!hash_equals($_SESSION['forma_csrf'], (string)$token)) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => 'Invalid CSRF token']);
                exit;
            }
        }
    }

    public static function csrf(): string {
        self::startSession();
        if (empty($_SESSION['forma_csrf'])) {
            $_SESSION['forma_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['forma_csrf'];
    }

    public static function user(): ?string {
        self::startSession();
        return $_SESSION['forma_user'] ?? null;
    }

    public static function login(string $username): void {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION['forma_user'] = $username;
        $_SESSION['forma_last_activity'] = time();
        $_SESSION['forma_csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void {
        self::startSession();
        session_unset();
        session_destroy();
    }

    private static function deny(bool $isApi, string $message, int $code): void {
        if ($isApi || str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            header('Content-Type: application/json');
            http_response_code($code);
            echo json_encode(['error' => $message]);
        } else {
            // Absolute admin login path — relative "login.php" breaks on /admin (no trailing slash)
            $login = rtrim(forma_admin_base_href(), '/') . '/login.php';
            header('Location: ' . $login);
        }
        exit;
    }
}

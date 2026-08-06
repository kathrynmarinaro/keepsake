<?php

declare(strict_types=1);

namespace Keepsake;

/**
 * Session-based auth for Keepsake's single allowed user.
 *
 * No registration flow, no roles/permissions — this is intentionally as
 * small as single-user auth can be. The one allowed account lives in the
 * `users` table (migrations/001_create_users_table.sql), seeded via
 * scripts/seed_user.php. See README.md for the seeded credentials location
 * and the manual test plan.
 */
final class Auth
{
    private static array $config = [];

    public static function init(array $appConfig): void
    {
        self::$config = $appConfig;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    /**
     * @return array{id:int,username:string}|null
     */
    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }

        return [
            'id' => (int) $_SESSION['user_id'],
            'username' => (string) ($_SESSION['username'] ?? ''),
        ];
    }

    public static function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, username, password_hash FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Regenerate the session id on privilege change to prevent session
        // fixation.
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];

        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
    }

    /**
     * Guard for any page that requires login. Redirects to the login page
     * (preserving the originally requested path) and halts execution when
     * the visitor isn't authenticated.
     */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }

        $redirect = $_SERVER['REQUEST_URI'] ?? '/index.php';
        header('Location: /login.php?redirect=' . urlencode($redirect));
        exit;
    }
}

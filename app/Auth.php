<?php

namespace App;

class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function attempt(string $username, string $password): ?array
    {
        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return null;
        }
        if ($user['status'] !== 'active') {
            return null;
        }
        return $user;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        self::$user = $user;
        self::$loaded = true;
        Database::run('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$loaded = false;
    }

    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid > 0) {
            $user = Database::one('SELECT * FROM users WHERE id = ?', [$uid]);
            if ($user && $user['status'] === 'active') {
                self::$user = $user;
            } else {
                unset($_SESSION['uid']);
            }
        }
        return self::$user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function isStaff(): bool
    {
        return in_array(self::user()['role'] ?? '', ['admin', 'agent'], true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            $next = $_SERVER['REQUEST_URI'] ?? '/';
            $_SESSION['_intended'] = $next;
            if (str_starts_with($next, base_path() . '/api/')) {
                json_out(['ok' => false, 'error' => '登录状态已失效，请重新登录'], 401);
            }
            redirect('/login');
        }
    }

    public static function requireRole(array $roles): void
    {
        self::requireLogin();
        $role = self::user()['role'] ?? 'user';
        if (!in_array($role, $roles, true)) {
            Logger::log('permission.denied', '尝试访问 ' . ($_SERVER['REQUEST_URI'] ?? ''), 'warning');
            abort(403);
        }
    }

    public static function roleLabel(string $role): string
    {
        return ['admin' => '管理员', 'agent' => '客服专员', 'user' => '普通用户'][$role] ?? $role;
    }

    public static function intended(): string
    {
        $next = $_SESSION['_intended'] ?? '/';
        unset($_SESSION['_intended']);
        return is_string($next) && str_starts_with($next, '/') ? $next : '/';
    }
}

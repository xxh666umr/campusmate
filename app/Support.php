<?php
/** 全局辅助函数 */

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    return $GLOBALS['APP_BASE'] ?? '';
}

function url(string $path = ''): string
{
    if ($path === '') {
        return base_path() . '/';
    }
    return base_path() . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path, int $code = 302): void
{
    if (str_starts_with($path, 'http')) {
        $target = $path;
    } elseif (base_path() !== '' && str_starts_with($path, base_path() . '/')) {
        $target = $path; // 已经是包含子目录的完整路径
    } else {
        $target = url($path);
    }
    header('Location: ' . $target, true, $code);
    exit;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token = null): bool
{
    $token = $token ?? ($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    return is_string($token) && $token !== '' && hash_equals($_SESSION['_csrf'] ?? '', $token);
}

function require_csrf(): void
{
    if (!csrf_check()) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', base_path() . '/api/')) {
            json_out(['ok' => false, 'error' => '页面已过期，请刷新后重试'], 419);
        }
        http_response_code(419);
        exit('页面已过期，请刷新后重试。');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }
    $value = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $value;
}

function old(string $key, $default = '')
{
    return $_SESSION['_old'][$key] ?? $default;
}

function remember_old(array $data): void
{
    $_SESSION['_old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['_old']);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function fmt_dt(?string $dt, string $format = 'Y-m-d H:i'): string
{
    if (!$dt) {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date($format, $ts) : e($dt);
}

function str_limit(string $value, int $limit = 60): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_strlen($value, 'UTF-8') > $limit ? mb_substr($value, 0, $limit, 'UTF-8') . '…' : $value;
}

function view(string $name, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = __DIR__ . '/Views/' . $name . '.php';
    if (!is_file($viewFile)) {
        http_response_code(500);
        exit('视图不存在: ' . e($name));
    }
    if (!empty($data['__bare'])) {
        require $viewFile;
        return;
    }
    require __DIR__ . '/Views/layout.php';
}

function abort(int $code, string $message = ''): void
{
    http_response_code($code);
    $titles = [403 => '没有权限', 404 => '页面不存在', 500 => '服务器开小差了'];
    $title = $titles[$code] ?? '出错了';
    $message = $message ?: ($code === 403 ? '当前账号无权访问该页面，请联系管理员。' : '请返回首页重试。');
    view('error', ['code' => $code, 'title' => $title, 'message' => $message, '__bare' => false]);
    exit;
}

function redirect_back(string $fallback = '/'): void
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref && str_starts_with($ref, 'http')) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

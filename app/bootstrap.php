<?php
declare(strict_types=1);

use App\Database;

define('APP_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Support.php';

$config = require __DIR__ . '/config.php';
$GLOBALS['APP_CONFIG'] = $config;

date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');
mb_internal_encoding('UTF-8');

if (!empty($config['debug'])) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

foreach (['', '/logs', '/sessions', '/exports'] as $sub) {
    $dir = $config['storage_path'] . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
}

// 会话保存在项目内，便于演示与清理
session_name('campossid');
session_save_path($config['session_path']);
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 当前部署的子目录，例如 /smart-service/public
$script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$dir = str_replace('\\', '/', dirname($script));
$GLOBALS['APP_BASE'] = $dir === '/' ? '' : rtrim($dir, '/');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

// 数据库不可用时给出可操作的中文提示页（MySQL 未启动是最常见原因），而不是白屏 500
try {
    Database::init($config['db']);
    Database::migrate();
    Database::seed();
} catch (\Throwable $e) {
    boot_db_failure_page($config, $e);
}

/**
 * 数据库初始化失败：写文件日志 + 渲染提示页（API 请求返回 503 JSON）。
 * 此时数据库不可用，只能用文件方式记录，不能依赖 Logger / Database。
 */
function boot_db_failure_page(array $config, \Throwable $e): void
{
    $driver = $config['db']['driver'] ?? 'sqlite';
    if ($driver === 'mysql') {
        $m = $config['db']['mysql'] ?? [];
        $target = ($m['host'] ?? '127.0.0.1') . ':' . ($m['port'] ?? 3306) . ' / ' . ($m['dbname'] ?? 'smart_service');
    } else {
        $target = $config['db']['sqlite_path'] ?? 'storage/app.db';
    }

    $logDir = $config['log_path'] ?? (APP_ROOT . '/storage/logs');
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    @file_put_contents(
        $logDir . '/db-error-' . date('Ymd') . '.log',
        '[' . date('Y-m-d H:i:s') . '] driver=' . $driver . ' target=' . $target . ' error=' . $e->getMessage() . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $isApi = str_contains($uri, '/api/');

    if ($isApi) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => '数据库未就绪，请先启动 MySQL 后刷新页面',
            'hint'  => $driver === 'mysql' ? '打开 XAMPP 控制面板启动 MySQL，或双击 start.bat' : '检查 storage 目录是否可写',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $detail = !empty($config['debug'])
        ? '<details open><summary>错误详情（debug=true）</summary><pre>' . e($e->getMessage()) . '</pre></details>'
        : '<details><summary>错误详情</summary><pre>生产模式已隐藏</pre></details>';
    $driverLabel = $driver === 'mysql' ? 'MySQL' : 'SQLite';

    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>数据库未就绪 · 智服 CampusMate</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;color:#e2e8f0;font:15px/1.7 "Microsoft YaHei",system-ui,sans-serif}'
        . '.card{max-width:680px;margin:24px;padding:32px 36px;background:#1e293b;border:1px solid #334155;border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,.35)}'
        . 'h1{margin:0 0 6px;font-size:22px;color:#f8fafc}p.sub{margin:0 0 20px;color:#94a3b8}'
        . 'ol{margin:0 0 18px;padding-left:22px}li{margin:8px 0}code{background:#0f172a;color:#7dd3fc;padding:2px 6px;border-radius:5px;font-size:13px}'
        . 'pre{white-space:pre-wrap;word-break:break-all;background:#0f172a;padding:14px;border-radius:10px;font-size:13px;color:#fca5a5;max-height:220px;overflow:auto}'
        . 'a.btn{display:inline-block;margin-top:8px;padding:9px 18px;background:#2563eb;color:#fff;border-radius:9px;text-decoration:none}'
        . 'details{margin-top:16px;color:#94a3b8}summary{cursor:pointer}'
        . '</style></head><body><div class="card">'
        . '<h1>数据库未就绪</h1><p class="sub">当前数据库：' . e($driverLabel) . ' · ' . e($target) . '</p>'
        . '<ol>'
        . '<li>先启动数据库：打开 <b>XAMPP Control Panel</b>，在 MySQL 一行点 <b>Start</b>；或双击本项目根目录的 <code>start.bat</code>，它会自动拉起 MySQL。</li>'
        . '<li>确认端口 <code>3306</code> 没有被其它程序占用；改了 root 密码的话，请同步修改 <code>app/config.local.php</code> 里的 <code>pass</code>。</li>'
        . '<li>想先跑起来：把 <code>app/config.local.php</code> 里的 <code>\'driver\' => \'mysql\'</code> 改成 <code>\'sqlite\'</code>（或直接删掉该文件），刷新即回到零配置模式。</li>'
        . '</ol>'
        . $detail
        . '<a class="btn" href="" onclick="location.reload();return false;">启动后点此重试</a>'
        . '</div></body></html>';
    exit;
}

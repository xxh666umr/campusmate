<?php
/**
 * 应用配置。可新建 config.local.php 覆盖（该文件不提交）。
 */
$root = dirname(__DIR__);

$config = [
    'name'        => '智服 · 校园智能客服',
    'short_name'  => '智服 CampusMate',
    'school'      => '东莞理工学院',
    'timezone'    => 'Asia/Shanghai',
    'debug'       => true,
    'demo_mode'   => true, // 登录页展示演示账号

    'session_path' => $root . '/storage/sessions',
    'storage_path' => $root . '/storage',
    'log_path'     => $root . '/storage/logs',
    'export_path'  => $root . '/storage/exports',

    'db' => [
        // sqlite：零配置，适合本机演示；mysql：交作业/部署到 XAMPP 时切换
        'driver'      => 'sqlite',
        'sqlite_path' => $root . '/storage/app.db',
        'mysql'       => [
            'host'    => '127.0.0.1',
            'port'    => 3306,
            'dbname'  => 'smart_service',
            'user'    => 'root',
            'pass'    => '',
            'charset' => 'utf8mb4',
        ],
    ],

    // 讯飞星火大模型。留空时自动使用本地模拟引擎（演示模式）。
    'spark' => [
        'app_id'     => '',
        'api_key'    => '',
        'api_secret' => '',
        'url'        => 'wss://spark-api.xf-yun.com/v3.5/chat',
        'domain'     => 'generalv3.5',
        'temperature'=> 0.5,
        'max_tokens' => 2048,
        'timeout'    => 30,
    ],

    // auto: 有密钥且能连通则用星火，否则本地模拟；也可强制 spark / mock
    'provider' => 'auto',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $config = array_replace_recursive($config, $local);
    }
}

return $config;

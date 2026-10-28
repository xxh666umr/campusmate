<?php
/**
 * 本地配置示例：复制为 config.local.php 后填写（config.local.php 已被 .gitignore，不要提交）。
 *
 * 注意：当前项目根目录的 app/config.local.php 已配好 MySQL（root 空密码），
 *       本文件只作为“换电脑 / 换账号”时的参考，不要直接覆盖已有配置。
 */
return [
    // 数据库：mysql 用 XAMPP 自带 MySQL/MariaDB（root 默认空密码）；sqlite 为零配置备选
    'db' => [
        'driver' => 'mysql',
        'mysql'  => [
            'host'   => '127.0.0.1',
            'port'   => 3306,
            'dbname' => 'smart_service',
            'user'   => 'root',
            'pass'   => '',
        ],
    ],

    // 讯飞星火（没有密钥就留空，自动使用本地演示引擎）
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

    // auto：有密钥用星火，失败自动降级；spark：强制星火；mock：只用本地引擎
    'provider' => 'auto',
];

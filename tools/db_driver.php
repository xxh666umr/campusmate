<?php
/**
 * 输出当前数据库驱动（sqlite / mysql），供 start.bat 判断是否需要先启动 MySQL。
 * 不依赖框架，只用配置文件，避免 start.bat 里写死判断逻辑。
 */
$config = require dirname(__DIR__) . '/app/config.php';
echo ($config['db']['driver'] ?? 'sqlite') . PHP_EOL;

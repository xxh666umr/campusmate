<?php

namespace App;

class Logger
{
    /** 脱敏：手机号、邮箱、身份证号 */
    public static function mask(string $text): string
    {
        $text = preg_replace('/(?<!\d)(1[3-9]\d)\d{4}(\d{4})(?!\d)/', '$1****$2', $text) ?? $text;
        $text = preg_replace('/([\w.+-]{2})[\w.+-]*@([\w-]+\.[\w.-]+)/u', '$1***@$2', $text) ?? $text;
        $text = preg_replace('/(?<!\d)(\d{6})\d{8}(\d{3}[\dXx])(?!\d)/', '$1********$2', $text) ?? $text;
        return $text;
    }

    public static function log(string $action, string $detail = '', string $level = 'info', ?int $userId = null): void
    {
        $userId = $userId ?? (Auth::check() ? Auth::id() : null);
        $detail = self::mask($detail);
        $ip = client_ip();

        try {
            Database::insert('logs', [
                'user_id'    => $userId,
                'action'     => $action,
                'level'      => $level,
                'detail'     => mb_substr($detail, 0, 2000, 'UTF-8'),
                'ip'         => $ip,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // 日志失败不能影响主流程
        }

        self::writeFile($action, $detail, $level, $userId, $ip);
    }

    private static function writeFile(string $action, string $detail, string $level, ?int $userId, string $ip): void
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = sprintf(
            "[%s] [%s] [uid=%s] [%s] %s %s\n",
            now(),
            strtoupper($level),
            $userId ?? '-',
            $ip,
            $action,
            str_replace(["\r", "\n"], ' ', $detail)
        );
        @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** 读取日志文件最近 N 行（日志管理页展示，文件操作示例） */
    public static function tailFile(int $lines = 200): array
    {
        $file = dirname(__DIR__) . '/storage/logs/' . date('Y-m-d') . '.log';
        if (!is_file($file)) {
            return [];
        }
        $content = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_slice($content, -$lines);
    }
}

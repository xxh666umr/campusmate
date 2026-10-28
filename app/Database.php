<?php

namespace App;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $pdo = null;
    private static array $config = [];

    public static function init(array $config): void
    {
        self::$config = $config;
    }

    public static function isSqlite(): bool
    {
        return (self::$config['driver'] ?? 'sqlite') === 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $cfg = self::$config;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if (self::isSqlite()) {
            $dir = dirname($cfg['sqlite_path']);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            self::$pdo = new PDO('sqlite:' . $cfg['sqlite_path'], null, null, $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $m = $cfg['mysql'];
            $baseDsn = "mysql:host={$m['host']};port={$m['port']};charset={$m['charset']}";
            try {
                self::$pdo = new PDO($baseDsn . ";dbname={$m['dbname']}", $m['user'], $m['pass'], $options);
            } catch (PDOException $e) {
                // 数据库不存在时自动创建（需要 root 权限）
                $tmp = new PDO($baseDsn, $m['user'], $m['pass'], $options);
                $tmp->exec("CREATE DATABASE IF NOT EXISTS `{$m['dbname']}` DEFAULT CHARACTER SET utf8mb4");
                self::$pdo = new PDO($baseDsn . ";dbname={$m['dbname']}", $m['user'], $m['pass'], $options);
            }
        }

        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function value(string $sql, array $params = [])
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::run($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function migrate(): void
    {
        foreach (self::statements() as $sql) {
            self::pdo()->exec($sql);
        }
    }

    private static function statements(): array
    {
        if (self::isSqlite()) {
            return [
                "CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    password_hash TEXT NOT NULL,
                    display_name TEXT NOT NULL,
                    email TEXT,
                    role TEXT NOT NULL DEFAULT 'user',
                    status TEXT NOT NULL DEFAULT 'active',
                    created_at TEXT NOT NULL,
                    last_login_at TEXT
                )",
                "CREATE TABLE IF NOT EXISTS conversations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    mode TEXT NOT NULL,
                    title TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'open',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    conversation_id INTEGER NOT NULL,
                    user_id INTEGER,
                    role TEXT NOT NULL,
                    content TEXT NOT NULL,
                    meta TEXT,
                    created_at TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS feedback (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_id INTEGER NOT NULL,
                    user_id INTEGER NOT NULL,
                    rating INTEGER NOT NULL,
                    comment TEXT,
                    created_at TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS tickets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    conversation_id INTEGER,
                    title TEXT NOT NULL,
                    detail TEXT NOT NULL,
                    priority TEXT NOT NULL DEFAULT 'normal',
                    status TEXT NOT NULL DEFAULT 'open',
                    assignee_id INTEGER,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    action TEXT NOT NULL,
                    level TEXT NOT NULL DEFAULT 'info',
                    detail TEXT,
                    ip TEXT,
                    created_at TEXT NOT NULL
                )",
                "CREATE TABLE IF NOT EXISTS kb_articles (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    category TEXT NOT NULL,
                    title TEXT NOT NULL,
                    keywords TEXT NOT NULL,
                    content TEXT NOT NULL,
                    created_at TEXT NOT NULL
                )",
                "CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id)",
                "CREATE INDEX IF NOT EXISTS idx_conversations_user ON conversations(user_id)",
                "CREATE INDEX IF NOT EXISTS idx_logs_created ON logs(created_at)",
                "CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status)",
                "CREATE UNIQUE INDEX IF NOT EXISTS idx_feedback_message ON feedback(message_id, user_id)",
            ];
        }

        return [
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(64) NOT NULL,
                email VARCHAR(128) DEFAULT NULL,
                role VARCHAR(16) NOT NULL DEFAULT 'user',
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                last_login_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS conversations (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                mode VARCHAR(16) NOT NULL,
                title VARCHAR(200) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_conversations_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS messages (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                role VARCHAR(16) NOT NULL,
                content MEDIUMTEXT NOT NULL,
                meta TEXT,
                created_at DATETIME NOT NULL,
                INDEX idx_messages_conv (conversation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS feedback (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                message_id INT UNSIGNED NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                rating TINYINT NOT NULL,
                comment VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY idx_feedback_message (message_id, user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS tickets (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                conversation_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(200) NOT NULL,
                detail TEXT NOT NULL,
                priority VARCHAR(16) NOT NULL DEFAULT 'normal',
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                assignee_id INT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_tickets_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS logs (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED DEFAULT NULL,
                action VARCHAR(64) NOT NULL,
                level VARCHAR(16) NOT NULL DEFAULT 'info',
                detail TEXT,
                ip VARCHAR(64) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_logs_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS kb_articles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                category VARCHAR(64) NOT NULL,
                title VARCHAR(200) NOT NULL,
                keywords VARCHAR(500) NOT NULL,
                content MEDIUMTEXT NOT NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    public static function seed(): void
    {
        if ((int) self::value('SELECT COUNT(*) FROM users') > 0) {
            return;
        }

        $now = now();
        $users = [
            ['admin', 'admin123', '系统管理员', 'admin@dgut.edu.cn', 'admin'],
            ['teacher', 'teacher123', '罗老师', 'luo@dgut.edu.cn', 'agent'],
            ['student', 'student123', '陈同学', 'chen@dgut.edu.cn', 'user'],
        ];
        foreach ($users as [$u, $p, $name, $mail, $role]) {
            self::insert('users', [
                'username'      => $u,
                'password_hash' => password_hash($p, PASSWORD_DEFAULT),
                'display_name'  => $name,
                'email'         => $mail,
                'role'          => $role,
                'status'        => 'active',
                'created_at'    => $now,
            ]);
        }

        foreach (require __DIR__ . '/SeedData.php' as $article) {
            $article['created_at'] = $now;
            self::insert('kb_articles', $article);
        }

        self::seedDemoActivity();
    }

    /** 生成最近 7 天的演示会话/反馈/工单，让统计图有内容可看 */
    private static function seedDemoActivity(): void
    {
        $studentId = (int) self::value("SELECT id FROM users WHERE username = 'student'");
        $agentId = (int) self::value("SELECT id FROM users WHERE username = 'teacher'");
        $modes = ['single', 'multi', 'guided'];
        $samples = [
            ['选课冲突了怎么办？', '选课分为三轮，冲突时可先退掉一门，再在补退选阶段改选。'],
            ['校园网一直认证失败', '请先确认账号为学号、密码为身份证后 6 位，再检查是否欠费或设备数超限。'],
            ['宿舍空调不制冷，怎么报修？', '企业微信 → 后勤服务 → 宿舍报修，填写楼栋房号并上传照片即可。'],
            ['国家助学金需要什么材料？', '需要《家庭经济困难学生认定申请表》及低保证/脱贫证明等材料之一。'],
            ['图书馆借的书过期了怎么办？', '逾期每册每天 0.1 元，先在公众号缴清罚款即可恢复借阅。'],
            ['怎么申请转专业？', '每学年第二学期 4 月发布通知，GPA 不低于 2.5 且无不及格记录可申请。'],
            ['校园卡丢了怎么补办？', '先在企业微信挂失，再带身份证到行政楼 A 座 1 楼卡务中心补办。'],
            ['VPN 连不上，知网打不开', '请使用 EasyConnect 连接 vpn.dgut.edu.cn，登录后再访问图书馆数据库。'],
            ['补考什么时候报名？', '下一学期开学第 2 周在教务系统提交补考申请，补考最高记 60 分。'],
            ['缓考需要什么手续？', '考前 3 个工作日提交《缓考申请表》并附校医院证明，经学院审批。'],
        ];

        $pdo = self::pdo();
        for ($day = 6; $day >= 0; $day--) {
            $count = max(2, 7 - $day + random_int(0, 2));
            for ($i = 0; $i < $count; $i++) {
                $ts = date('Y-m-d H:i:s', strtotime("-{$day} days " . random_int(9, 20) . ':' . random_int(10, 59) . ':00'));
                $sample = $samples[array_rand($samples)];
                $mode = $modes[array_rand($modes)];
                $convId = self::insert('conversations', [
                    'user_id'    => $studentId,
                    'mode'       => $mode,
                    'title'      => mb_substr($sample[0], 0, 30, 'UTF-8'),
                    'status'     => 'open',
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
                self::insert('messages', [
                    'conversation_id' => $convId,
                    'user_id'         => $studentId,
                    'role'            => 'user',
                    'content'         => $sample[0],
                    'meta'            => null,
                    'created_at'      => $ts,
                ]);
                $msgId = self::insert('messages', [
                    'conversation_id' => $convId,
                    'user_id'         => null,
                    'role'            => 'assistant',
                    'content'         => $sample[1],
                    'meta'            => json_encode(['source' => 'demo', 'sentiment' => 'neutral'], JSON_UNESCAPED_UNICODE),
                    'created_at'      => $ts,
                ]);
                if (random_int(0, 100) < 62) {
                    self::insert('feedback', [
                        'message_id' => $msgId,
                        'user_id'    => $studentId,
                        'rating'     => random_int(0, 10) < 9 ? 5 : random_int(3, 4),
                        'comment'    => null,
                        'created_at' => $ts,
                    ]);
                }
            }
        }

        $tickets = [
            ['宿舍 3 栋 502 空调漏水', '报修后 24 小时仍未处理，空调冷凝水渗到楼下。', 'high', 'processing'],
            ['校园网账号疑似被盗用', '账号频繁在陌生设备登录，已自行改密，申请核查。', 'urgent', 'open'],
            ['成绩复核申请进度咨询', '已提交成绩复核 5 个工作日，想确认进度。', 'normal', 'resolved'],
        ];
        foreach ($tickets as $i => [$title, $detail, $priority, $status]) {
            self::insert('tickets', [
                'user_id'         => $studentId,
                'conversation_id' => null,
                'title'           => $title,
                'detail'          => $detail,
                'priority'        => $priority,
                'status'          => $status,
                'assignee_id'     => $i === 0 ? $agentId : null,
                'created_at'      => date('Y-m-d H:i:s', strtotime("-" . (3 - $i) . " days 10:20:00")),
                'updated_at'      => date('Y-m-d H:i:s', strtotime("-" . (3 - $i) . " days 16:00:00")),
            ]);
        }

        foreach ([
            ['login', 'info', '演示账号 student 登录成功'],
            ['chat', 'info', '发起一次单轮问答'],
            ['ticket.create', 'warning', '创建工单：宿舍 3 栋 502 空调漏水'],
            ['admin.user.update', 'info', '管理员更新用户角色'],
        ] as [$action, $level, $detail]) {
            self::insert('logs', [
                'user_id'    => $studentId,
                'action'     => $action,
                'level'      => $level,
                'detail'     => $detail,
                'ip'         => '127.0.0.1',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            ]);
        }
    }
}

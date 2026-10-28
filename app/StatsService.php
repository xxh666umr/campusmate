<?php

namespace App;

class StatsService
{
    public static function overview(): array
    {
        $today = date('Y-m-d');
        return [
            'users'         => (int) Database::value('SELECT COUNT(*) FROM users'),
            'conversations' => (int) Database::value('SELECT COUNT(*) FROM conversations'),
            'messages'      => (int) Database::value('SELECT COUNT(*) FROM messages'),
            'tickets_open'  => (int) Database::value("SELECT COUNT(*) FROM tickets WHERE status IN ('open','processing')"),
            'today_chats'   => (int) Database::value('SELECT COUNT(*) FROM conversations WHERE created_at >= ?', [$today . ' 00:00:00']),
            'avg_rating'    => round((float) (Database::value('SELECT AVG(rating) FROM feedback') ?? 0), 1),
            'positive_rate' => (function () {
                $total = (int) Database::value('SELECT COUNT(*) FROM feedback');
                if ($total === 0) {
                    return 0;
                }
                $good = (int) Database::value('SELECT COUNT(*) FROM feedback WHERE rating >= 4');
                return (int) round($good * 100 / $total);
            })(),
            'resolution_rate' => (function () {
                $total = (int) Database::value('SELECT COUNT(*) FROM tickets');
                if ($total === 0) {
                    return 100;
                }
                $done = (int) Database::value("SELECT COUNT(*) FROM tickets WHERE status IN ('resolved','closed')");
                return (int) round($done * 100 / $total);
            })(),
        ];
    }

    /** 最近 N 天的会话量、消息量 */
    public static function daily(int $days = 7): array
    {
        $labels = [];
        $conv = [];
        $msg = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('m-d', strtotime($day));
            $conv[$day] = 0;
            $msg[$day] = 0;
        }
        foreach (Database::all('SELECT created_at FROM conversations WHERE created_at >= ?', [date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00']) as $row) {
            $day = substr($row['created_at'], 0, 10);
            if (isset($conv[$day])) {
                $conv[$day]++;
            }
        }
        foreach (Database::all('SELECT created_at FROM messages WHERE role = ? AND created_at >= ?', ['user', date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00']) as $row) {
            $day = substr($row['created_at'], 0, 10);
            if (isset($msg[$day])) {
                $msg[$day]++;
            }
        }
        return ['labels' => $labels, 'conversations' => array_values($conv), 'messages' => array_values($msg)];
    }

    public static function modes(): array
    {
        $rows = Database::all('SELECT mode, COUNT(*) AS n FROM conversations GROUP BY mode');
        $map = ['single' => 0, 'multi' => 0, 'guided' => 0];
        foreach ($rows as $row) {
            $map[$row['mode']] = (int) $row['n'];
        }
        return [
            'labels' => ['单轮问答', '多轮对话', '信息选择'],
            'values' => array_values($map),
        ];
    }

    public static function ratings(): array
    {
        $rows = Database::all('SELECT rating, COUNT(*) AS n FROM feedback GROUP BY rating');
        $map = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        foreach ($rows as $row) {
            $map[(int) $row['rating']] = (int) $row['n'];
        }
        ksort($map);
        return ['labels' => ['1 分', '2 分', '3 分', '4 分', '5 分'], 'values' => array_values($map)];
    }

    public static function tickets(): array
    {
        $rows = Database::all('SELECT status, COUNT(*) AS n FROM tickets GROUP BY status');
        $map = ['open' => 0, 'processing' => 0, 'resolved' => 0, 'closed' => 0];
        foreach ($rows as $row) {
            $map[$row['status']] = (int) $row['n'];
        }
        return ['labels' => ['待处理', '处理中', '已解决', '已关闭'], 'values' => array_values($map)];
    }

    /** 热门咨询主题：基于校园事务词典统计用户消息 */
    public static function topics(int $limit = 8): array
    {
        $dict = ['选课', '成绩', '补考', '缓考', '重修', '校园网', 'VPN', '断网', '宿舍', '报修',
            '水电', '助学金', '勤工', '校园卡', '图书馆', '转专业', '学分', '绩点', '考试', '实习'];
        $counts = array_fill_keys($dict, 0);
        $rows = Database::all("SELECT content FROM messages WHERE role = 'user' ORDER BY id DESC LIMIT 500");
        foreach ($rows as $row) {
            foreach ($dict as $word) {
                if (mb_strpos($row['content'], $word) !== false) {
                    $counts[$word]++;
                }
            }
        }
        arsort($counts);
        $counts = array_filter($counts, static fn($n) => $n > 0);
        $counts = array_slice($counts, 0, $limit, true);
        return ['labels' => array_keys($counts), 'values' => array_values($counts)];
    }

    public static function recentConversations(int $limit = 6): array
    {
        return Database::all(
            'SELECT c.*, u.display_name, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS msg_count
             FROM conversations c LEFT JOIN users u ON u.id = c.user_id
             ORDER BY c.updated_at DESC LIMIT ' . $limit
        );
    }
}

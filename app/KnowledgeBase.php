<?php

namespace App;

class KnowledgeBase
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = Database::all('SELECT * FROM kb_articles ORDER BY id');
        }
        return self::$cache;
    }

    /**
     * 轻量检索：关键词命中 + 标题二元组重合 + 类别命中。
     * 返回 [['article'=>..., 'score'=>...], ...]
     */
    public static function retrieve(string $query, int $limit = 3): array
    {
        $q = self::normalize($query);
        if ($q === '') {
            return [];
        }

        $scored = [];
        foreach (self::all() as $article) {
            $score = 0;
            foreach (preg_split('/[,，、\s]+/u', $article['keywords']) as $kw) {
                $kw = trim($kw);
                if ($kw !== '' && mb_strpos($q, $kw) !== false) {
                    $score += 4 + mb_strlen($kw, 'UTF-8');
                }
            }
            $title = self::normalize($article['title']);
            foreach (self::bigrams($title) as $bg) {
                if (mb_strpos($q, $bg) !== false) {
                    $score += 1;
                }
            }
            if (mb_strpos($q, mb_substr($article['category'], 0, 2, 'UTF-8')) !== false) {
                $score += 2;
            }
            if ($score > 0) {
                $scored[] = ['article' => $article, 'score' => $score];
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    public static function categories(): array
    {
        $rows = Database::all('SELECT category, COUNT(*) AS n FROM kb_articles GROUP BY category ORDER BY n DESC');
        return $rows;
    }

    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', $text) ?? '';
    }

    private static function bigrams(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        for ($i = 0; $i < count($chars) - 1; $i++) {
            if (preg_match('/^[\x{4e00}-\x{9fa5}]$/u', $chars[$i]) && preg_match('/^[\x{4e00}-\x{9fa5}]$/u', $chars[$i + 1])) {
                $out[] = $chars[$i] . $chars[$i + 1];
            }
        }
        return array_values(array_unique($out));
    }

    public static function suggestionsFor(array $article, int $limit = 3): array
    {
        $pool = [
            '教务教学' => ['选课冲突了怎么办？', '成绩可以申请复核吗？', '缓考需要什么手续？'],
            '校园网络' => ['校园网认证失败怎么排查？', '校外怎么用知网？', '网速慢怎么处理？'],
            '宿舍生活' => ['宿舍报修要多久？', '宿舍断电了怎么办？', '水电费在哪里充值？'],
            '奖助学金' => ['国家助学金需要哪些材料？', '勤工助学怎么报名？', '临时困难补助怎么申请？'],
            '校园卡' => ['校园卡丢了怎么补办？', '充值没到账怎么办？', '怎么修改消费密码？'],
            '图书馆' => ['图书快到期了怎么续借？', '自习座位怎么预约？', '找不到文献怎么办？'],
            '就业实习' => ['转专业需要什么条件？', '实习证明在哪里开？', '校招信息在哪里看？'],
        ];
        $cat = $article['category'] ?? '';
        $out = $pool[$cat] ?? [];
        $out = array_values(array_filter($out, static fn($s) => $s !== ($article['title'] ?? '')));
        return array_slice($out, 0, $limit);
    }
}

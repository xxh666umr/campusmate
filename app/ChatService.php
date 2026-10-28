<?php

namespace App;

use App\Spark\MockProvider;
use App\Spark\SparkClient;

class ChatService
{
    private array $config;
    private SparkClient $spark;
    private MockProvider $mock;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->spark = new SparkClient($config['spark'] ?? []);
        $this->mock = new MockProvider();
    }

    public function providerStatus(): array
    {
        $configured = $this->spark->configured();
        $mode = $this->config['provider'] ?? 'auto';
        $useSpark = $configured && $mode !== 'mock';
        return [
            'provider'   => $useSpark ? 'spark' : 'mock',
            'label'      => $useSpark ? '讯飞星火' : '本地演示引擎',
            'configured' => $configured,
            'mode'       => $mode,
        ];
    }

    public function reply(int $userId, string $mode, string $message, ?int $conversationId = null, ?array $guidedIssue = null): array
    {
        $mode = in_array($mode, ['single', 'multi', 'guided'], true) ? $mode : 'single';
        $message = trim($message);
        if ($message === '' && $guidedIssue === null) {
            throw new \InvalidArgumentException('请输入咨询内容');
        }
        if (mb_strlen($message, 'UTF-8') > 2000) {
            throw new \InvalidArgumentException('单条消息不能超过 2000 字');
        }

        $sentiment = $this->sentiment($message);
        $history = [];

        $conversation = null;
        if ($conversationId) {
            $conversation = Database::one('SELECT * FROM conversations WHERE id = ? AND user_id = ?', [$conversationId, $userId]);
        }
        if (!$conversation) {
            $titleSource = $guidedIssue['title'] ?? $message;
            $conversationId = Database::insert('conversations', [
                'user_id'    => $userId,
                'mode'       => $mode,
                'title'      => str_limit($titleSource, 40),
                'status'     => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $conversationId = (int) $conversation['id'];
            if ($mode === 'multi') {
                $history = Database::all(
                    'SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 12',
                    [$conversationId]
                );
                $history = array_reverse($history);
            }
        }

        if ($guidedIssue !== null) {
            $answer = GuidedFlow::render($guidedIssue);
            $articles = KnowledgeBase::retrieve($guidedIssue['title'] . ' ' . $guidedIssue['symptom'], 2);
            $suggestions = [];
            $needTicket = false;
            $provider = 'guided';
        } else {
            $articles = KnowledgeBase::retrieve($message, 3);
            // 多轮追问（如“那还是不行”）没有命中时，用上一轮用户问题补足语境
            if (!$articles && $mode === 'multi' && $history) {
                foreach (array_reverse($history) as $h) {
                    if (($h['role'] ?? '') === 'user') {
                        $articles = KnowledgeBase::retrieve($h['content'] . ' ' . $message, 3);
                        break;
                    }
                }
            }
            $provider = 'mock';
            $answer = '';
            $suggestions = [];
            $needTicket = false;

            $status = $this->providerStatus();
            if ($status['provider'] === 'spark') {
                try {
                    $result = $this->spark->chat($this->buildMessages($message, $history, $articles, $mode), $userId);
                    $answer = $result['content'];
                    $provider = 'spark';
                    $suggestions = $this->defaultSuggestions($articles);
                } catch (\Throwable $e) {
                    Logger::log('ai.spark.failed', $e->getMessage(), 'error');
                    $provider = 'mock';
                }
            }

            if ($answer === '') {
                $mock = $this->mock->answer($mode, $message, $articles, $history, $sentiment);
                $answer = $mock['answer'];
                $suggestions = $mock['suggestions'];
                $needTicket = $mock['need_ticket'];
            }

            if (preg_match('/(转人工|人工客服|找老师|投诉)/u', $message)) {
                $needTicket = true;
            }
        }

        $userMessageId = Database::insert('messages', [
            'conversation_id' => $conversationId,
            'user_id'         => $userId,
            'role'            => 'user',
            'content'         => $message !== '' ? $message : ($guidedIssue['title'] ?? ''),
            'meta'            => json_encode(['mode' => $mode, 'guided' => $guidedIssue['id'] ?? null], JSON_UNESCAPED_UNICODE),
            'created_at'      => now(),
        ]);

        $sources = array_map(static fn($a) => [
            'title'    => $a['article']['title'],
            'category' => $a['article']['category'],
        ], $articles);

        $messageId = Database::insert('messages', [
            'conversation_id' => $conversationId,
            'user_id'         => null,
            'role'            => 'assistant',
            'content'         => $answer,
            'meta'            => json_encode([
                'provider'    => $provider,
                'sentiment'   => $sentiment,
                'sources'     => $sources,
                'suggestions' => $suggestions,
                'need_ticket' => $needTicket,
                'guided'      => $guidedIssue ? ['category' => $guidedIssue['category'], 'title' => $guidedIssue['title']] : null,
            ], JSON_UNESCAPED_UNICODE),
            'created_at'      => now(),
        ]);

        Database::run('UPDATE conversations SET updated_at = ? WHERE id = ?', [now(), $conversationId]);

        Logger::log('chat.' . $mode, str_limit($message !== '' ? $message : ($guidedIssue['title'] ?? ''), 120), 'info', $userId);

        return [
            'ok'              => true,
            'conversation_id' => $conversationId,
            'user_message_id' => $userMessageId,
            'message_id'      => $messageId,
            'answer'          => $answer,
            'sources'         => $sources,
            'suggestions'     => $suggestions,
            'sentiment'       => $sentiment,
            'need_ticket'     => $needTicket,
            'provider'        => $provider,
        ];
    }

    private function buildMessages(string $message, array $history, array $articles, string $mode): array
    {
        $messages = [['role' => 'system', 'content' => $this->systemPrompt($articles)]];
        if ($mode === 'multi') {
            foreach ($history as $item) {
                if (in_array($item['role'], ['user', 'assistant'], true)) {
                    $messages[] = ['role' => $item['role'], 'content' => $item['content']];
                }
            }
        }
        $messages[] = ['role' => 'user', 'content' => $message];
        return $messages;
    }

    private function systemPrompt(array $articles): string
    {
        $prompt = "你是东莞理工学院校园智能客服「智服」。面向在校学生，解答教务选课、成绩考试、校园网络、宿舍报修、奖助学金、校园卡、图书馆、转专业等校园事务。\n"
            . "回答要求：\n"
            . "1. 先给结论或安抚，再给出分点、可执行的步骤，尽量写明办理入口、电话或网址；\n"
            . "2. 优先依据【参考资料】，不要编造政策、金额、时间等细节；\n"
            . "3. 如果资料不足或涉及个人隐私、具体学籍处理，明确说明并建议转人工；\n"
            . "4. 使用简体中文，300 字以内，语气友好专业，不要使用 Markdown 表格或代码块。";

        if ($articles) {
            $prompt .= "\n\n【参考资料】";
            foreach ($articles as $i => $item) {
                $prompt .= "\n[" . ($i + 1) . '] ' . $item['article']['title'] . '（' . $item['article']['category'] . "）：" . $item['article']['content'];
            }
        } else {
            $prompt .= "\n\n【参考资料】暂无完全匹配的条目。请说明暂时没有查到，并引导用户补充信息或转人工。";
        }
        return $prompt;
    }

    private function defaultSuggestions(array $articles): array
    {
        if ($articles) {
            return KnowledgeBase::suggestionsFor($articles[0]['article']);
        }
        return ['选课冲突怎么办？', '校园网认证失败', '宿舍报修流程'];
    }

    public function sentiment(string $text): string
    {
        $negative = ['投诉', '太差', '垃圾', '生气', '愤怒', '崩溃', '气死', '无语', '恶心', '烦死', '坑人',
            '骗子', '曝光', '举报', '退钱', '赔偿', '紧急', '急死', '受不了', '什么破', '没人管', '一直没人'];
        $positive = ['谢谢', '感谢', '不错', '很好', '满意', '太棒', '给力', '解决了', '有用'];
        foreach ($negative as $w) {
            if (mb_strpos($text, $w) !== false) {
                return 'negative';
            }
        }
        foreach ($positive as $w) {
            if (mb_strpos($text, $w) !== false) {
                return 'positive';
            }
        }
        return 'neutral';
    }
}

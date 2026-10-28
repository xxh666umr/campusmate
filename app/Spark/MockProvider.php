<?php

namespace App\Spark;

use App\KnowledgeBase;

/**
 * 本地演示引擎：没有星火密钥或网络不可用时，仍然可以完整演示三种咨询方式。
 * 采用「知识库检索 + 模板组织」的方式生成回答，并非真实大模型推理。
 */
class MockProvider
{
    public function answer(string $mode, string $message, array $articles, array $history, string $sentiment): array
    {
        $text = trim($message);
        $plain = preg_replace('/[\s\p{P}]+/u', '', $text) ?? '';

        if ($plain === '') {
            return ['answer' => '我在的，请把你想咨询的问题告诉我，例如「选课冲突怎么办」。', 'suggestions' => ['选课冲突怎么办？', '校园网认证失败', '宿舍怎么报修？'], 'need_ticket' => false];
        }

        // 1. 寒暄与能力询问
        if (preg_match('/^(你好|您好|hi|hello|嗨|在吗|有人吗)/iu', $text)) {
            return [
                'answer' => "你好，我是校园智能客服「智服」👋\n我可以帮你处理教务选课、成绩考试、校园网络、宿舍报修、奖助学金、校园卡和图书馆等校园事务。\n你可以直接描述问题，也可以切换到「信息选择」，按类别一步步找到处理办法。",
                'suggestions' => ['选课冲突怎么办？', '校园网认证失败怎么排查？', '国家助学金需要哪些材料？'],
                'need_ticket' => false,
            ];
        }
        if (preg_match('/(谢谢|感谢|辛苦了|thx|thanks)/iu', $text)) {
            return [
                'answer' => "不客气～如果问题已经解决，可以给这次回答点个评价；如果还有后续，随时继续问我。",
                'suggestions' => ['还有其他校园问题吗？', '我想转人工'],
                'need_ticket' => false,
            ];
        }
        if (preg_match('/(你能做什么|有什么功能|怎么用|帮助|使用说明)/u', $text)) {
            return [
                'answer' => "我支持三种咨询方式：\n1. 单轮问答：每个问题独立回答，适合快速查询；\n2. 多轮对话：会结合上下文，适合逐步排查问题；\n3. 信息选择：按「类别 → 具体问题」选择，我给出推荐处理办法。\n另外，遇到我解决不了的问题，可以一键转人工并生成工单，管理员会在后台跟进。",
                'suggestions' => ['单轮和多轮有什么区别？', '怎么生成工单？', '校园网认证失败'],
                'need_ticket' => false,
            ];
        }

        // 2. 多轮语境：对“那怎么办”“还是不行”这类追问，补一句承接
        $carry = '';
        if ($mode === 'multi' && $history) {
            $lastAssistant = '';
            foreach (array_reverse($history) as $h) {
                if (($h['role'] ?? '') === 'assistant') {
                    $lastAssistant = $h['content'] ?? '';
                    break;
                }
            }
            if ($lastAssistant !== '' && preg_match('/^(那|还是|然后|接着|这个|它|怎么办|为什么)/u', $text)) {
                $carry = "接着刚才的问题继续看：\n\n";
            }
        }

        // 3. 知识库命中
        if ($articles) {
            $top = $articles[0]['article'];
            $openings = [
                "关于「{$top['title']}」，我帮你整理了处理办法：",
                "这个问题我来帮你梳理一下 👇",
                "已为你找到「{$top['title']}」的相关说明：",
            ];
            $opening = $openings[abs(crc32($text)) % count($openings)];

            $body = $top['content'];
            $answer = $carry . ($sentiment === 'negative' ? '先别着急，' : '') . $opening . "\n\n" . $body;

            if (count($articles) > 1) {
                $others = array_slice(array_map(static fn($a) => '「' . $a['article']['title'] . '」', $articles), 1, 2);
                $answer .= "\n\n你可能还需要：" . implode('、', $others) . "。";
            }

            if ($sentiment === 'negative') {
                $answer .= "\n\n如果这个问题已经影响到你正常学习生活，建议同时转人工，让老师跟进处理。";
            }

            $suggestions = KnowledgeBase::suggestionsFor($top);
            return [
                'answer'   => $answer,
                'suggestions' => $suggestions,
                'need_ticket' => $sentiment === 'negative',
            ];
        }

        // 4. 未命中：澄清 + 引导
        $cats = ['教务教学', '校园网络', '宿舍生活', '奖助学金', '校园卡', '图书馆'];
        return [
            'answer' => $carry . "我暂时没有在知识库里找到完全匹配的答案。\n你可以补充一点信息，比如具体场景、页面提示或办理入口；也可以从下面这些常见类别里选一个：\n• " . implode("\n• ", $cats) . "\n\n如果比较着急，可以直接转人工，我会把本次对话一起提交给客服老师。",
            'suggestions' => ['选课冲突怎么办？', '校园网认证失败怎么排查？', '宿舍报修流程', '转人工'],
            'need_ticket' => $sentiment === 'negative',
        ];
    }
}

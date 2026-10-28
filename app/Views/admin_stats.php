<?php
/** @var array $overview */ /** @var array $daily */ /** @var array $modes */
/** @var array $ratings */ /** @var array $tickets */ /** @var array $topics */ /** @var array $recent */
?>
<div class="page-head">
    <div>
        <h2>数据看板</h2>
        <p class="muted">从会话、满意度、工单三个维度观察智能客服的运行情况。</p>
    </div>
    <div class="page-actions">
        <span class="badge badge-gray">最近 7 天</span>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi">
        <span class="kpi-icon indigo"><?= icon('users', 20) ?></span>
        <div><strong><?= (int) $overview['users'] ?></strong><span>注册用户</span></div>
        <em class="kpi-trend">累计</em>
    </div>
    <div class="kpi">
        <span class="kpi-icon cyan"><?= icon('chat', 20) ?></span>
        <div><strong><?= (int) $overview['conversations'] ?></strong><span>咨询会话</span></div>
        <em class="kpi-trend up">今日 +<?= (int) $overview['today_chats'] ?></em>
    </div>
    <div class="kpi">
        <span class="kpi-icon violet"><?= icon('sparkles', 20) ?></span>
        <div><strong><?= number_format((float) $overview['avg_rating'], 1) ?></strong><span>平均评分</span></div>
        <em class="kpi-trend">好评率 <?= (int) $overview['positive_rate'] ?>%</em>
    </div>
    <div class="kpi">
        <span class="kpi-icon amber"><?= icon('ticket', 20) ?></span>
        <div><strong><?= (int) $overview['tickets_open'] ?></strong><span>待处理工单</span></div>
        <em class="kpi-trend">解决率 <?= (int) $overview['resolution_rate'] ?>%</em>
    </div>
</div>

<div class="chart-grid">
    <div class="card chart-card wide">
        <div class="card-head">
            <span class="card-icon"><?= icon('stats', 17) ?></span>
            <h3>近 7 天咨询趋势</h3>
            <div class="legend">
                <span><i class="legend-dot indigo"></i>会话数</span>
                <span><i class="legend-dot cyan"></i>用户消息</span>
            </div>
        </div>
        <div class="chart-box"><canvas id="chartDaily"></canvas></div>
    </div>

    <div class="card chart-card">
        <div class="card-head">
            <span class="card-icon"><?= icon('chat', 17) ?></span>
            <h3>咨询方式占比</h3>
        </div>
        <div class="chart-box donut"><canvas id="chartModes"></canvas><div class="donut-center" id="donutCenter"></div></div>
        <div class="donut-legend" id="modeLegend"></div>
    </div>

    <div class="card chart-card">
        <div class="card-head">
            <span class="card-icon"><?= icon('star', 17) ?></span>
            <h3>满意度分布</h3>
        </div>
        <div class="chart-box"><canvas id="chartRatings"></canvas></div>
    </div>

    <div class="card chart-card">
        <div class="card-head">
            <span class="card-icon"><?= icon('shield', 17) ?></span>
            <h3>工单状态</h3>
        </div>
        <div class="chart-box"><canvas id="chartTickets"></canvas></div>
    </div>

    <div class="card chart-card">
        <div class="card-head">
            <span class="card-icon"><?= icon('search', 17) ?></span>
            <h3>热门咨询主题</h3>
        </div>
        <div class="chart-box topics"><canvas id="chartTopics"></canvas></div>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <span class="card-icon"><?= icon('clock', 17) ?></span>
        <h3>最近会话</h3>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th style="width: 70px">ID</th><th>标题</th><th style="width: 110px">用户</th><th style="width: 90px">方式</th><th style="width: 80px">消息</th><th style="width: 150px">时间</th></tr></thead>
            <tbody>
                <?php foreach ($recent as $c): ?>
                    <tr>
                        <td class="mono">#<?= (int) $c['id'] ?></td>
                        <td><?= e($c['title']) ?></td>
                        <td class="small"><?= e($c['display_name'] ?: '—') ?></td>
                        <td><span class="badge badge-gray"><?= ['single' => '单轮', 'multi' => '多轮', 'guided' => '信息选择'][$c['mode']] ?? e($c['mode']) ?></span></td>
                        <td class="mono"><?= (int) $c['msg_count'] ?></td>
                        <td class="muted small"><?= fmt_dt($c['updated_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script type="application/json" id="statsData"><?= json_encode([
    'daily' => $daily, 'modes' => $modes, 'ratings' => $ratings, 'tickets' => $tickets, 'topics' => $topics,
], JSON_UNESCAPED_UNICODE) ?></script>

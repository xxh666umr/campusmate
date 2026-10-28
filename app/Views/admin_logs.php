<?php /** @var array $logs */ ?>
<?php
$counts = ['info' => 0, 'warning' => 0, 'error' => 0, 'danger' => 0];
foreach ($logs as $l) {
    $counts[$l['level']] = ($counts[$l['level']] ?? 0) + 1;
}
$levelMeta = [
    'info' => ['label' => '信息', 'badge' => 'badge-gray'],
    'warning' => ['label' => '警告', 'badge' => 'badge-amber'],
    'error' => ['label' => '错误', 'badge' => 'badge-red'],
    'danger' => ['label' => '高危', 'badge' => 'badge-purple'],
];
?>
<div class="page-head">
    <div>
        <h2>日志管理</h2>
        <p class="muted">登录、咨询、权限与后台操作全程留痕；支持正则筛选与 CSV 导出。</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= url('/export?type=logs') ?>"><?= icon('download', 16) ?> 导出日志</a>
    </div>
</div>

<div class="stat-row">
    <div class="stat-mini"><span class="dot-badge gray"></span><div><strong><?= count($logs) ?></strong><small>当前结果</small></div></div>
    <div class="stat-mini"><span class="dot-badge amber"></span><div><strong><?= (int) $counts['warning'] ?></strong><small>警告</small></div></div>
    <div class="stat-mini"><span class="dot-badge red"></span><div><strong><?= (int) $counts['error'] ?></strong><small>错误</small></div></div>
    <div class="stat-mini"><span class="dot-badge purple"></span><div><strong><?= (int) $counts['danger'] ?></strong><small>高危</small></div></div>
</div>

<?php if (!empty($regexError)): ?>
    <div class="alert alert-warning"><?= icon('alert', 17) ?><span><?= e($regexError) ?></span></div>
<?php endif; ?>

<div class="card">
    <form class="filter-bar" method="get" action="<?= url('/admin/logs') ?>">
        <div class="filter-group">
            <label class="mini-field">
                <span>级别</span>
                <select name="level">
                    <option value="">全部</option>
                    <?php foreach ($levelMeta as $key => $meta): ?>
                        <option value="<?= $key ?>" <?= $level === $key ? 'selected' : '' ?>><?= $meta['label'] ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="mini-field grow">
                <span>关键词 / 正则</span>
                <input type="text" name="q" value="<?= e($q ?? '') ?>" placeholder="如：login、chat\..*、admin\.user">
            </label>
            <label class="mini-field">
                <span>日期</span>
                <input type="date" name="date" value="<?= e($date ?? '') ?>">
            </label>
        </div>
        <div class="filter-group">
            <label class="switch">
                <input type="checkbox" name="regex" value="1" <?= !empty($regex) ? 'checked' : '' ?>>
                <span class="switch-track"></span>
                <span class="switch-label">正则模式</span>
            </label>
            <button class="btn btn-primary" type="submit"><?= icon('search', 16) ?> 筛选</button>
            <a class="btn btn-ghost" href="<?= url('/admin/logs') ?>">重置</a>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table logs-table">
            <thead>
                <tr>
                    <th style="width: 150px">时间</th>
                    <th style="width: 80px">级别</th>
                    <th style="width: 110px">用户</th>
                    <th style="width: 170px">动作</th>
                    <th>详情</th>
                    <th style="width: 120px">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$logs): ?>
                    <tr><td colspan="6" class="empty-cell">没有符合条件的日志</td></tr>
                <?php endif; ?>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="muted small mono"><?= e($log['created_at']) ?></td>
                        <td><span class="badge <?= $levelMeta[$log['level']]['badge'] ?? 'badge-gray' ?>"><?= $levelMeta[$log['level']]['label'] ?? e($log['level']) ?></span></td>
                        <td class="small"><?= e($log['username'] ?: '系统') ?></td>
                        <td><code class="code"><?= e($log['action']) ?></code></td>
                        <td class="small"><?= e($log['detail']) ?></td>
                        <td class="muted small mono"><?= e($log['ip']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <span class="card-icon"><?= icon('file', 17) ?></span>
        <h3>今日日志文件（尾部 30 行）</h3>
        <span class="badge badge-gray">storage/logs/<?= date('Y-m-d') ?>.log</span>
    </div>
    <pre class="log-file"><?php
        if (!$fileLines) {
            echo '今日暂无文件日志。';
        }
        foreach ($fileLines as $line) {
            echo e($line) . "\n";
        }
    ?></pre>
</div>

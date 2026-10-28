<?php /** @var array $tickets */ ?>
<div class="page-head">
    <div>
        <h2>我的工单</h2>
        <p class="muted">转人工后生成的工单都在这里，可查看处理进度。</p>
    </div>
    <a class="btn btn-primary" href="<?= url('/chat') ?>"><?= icon('plus', 16) ?> 发起新咨询</a>
</div>

<?php if (!$tickets): ?>
    <div class="card empty-state">
        <span class="empty-orb"><?= icon('ticket', 30) ?></span>
        <h3>还没有工单</h3>
        <p class="muted">在智能客服里点击「转人工 / 生成工单」即可提交。</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 70px">编号</th>
                        <th>问题</th>
                        <th style="width: 90px">优先级</th>
                        <th style="width: 100px">状态</th>
                        <th style="width: 150px">提交时间</th>
                        <th style="width: 150px">最近更新</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td class="mono">#<?= (int) $t['id'] ?></td>
                            <td>
                                <strong><?= e($t['title']) ?></strong>
                                <div class="muted small clamp-1"><?= e($t['detail']) ?></div>
                            </td>
                            <td><span class="badge <?= ['urgent' => 'badge-red', 'high' => 'badge-amber', 'normal' => 'badge-blue', 'low' => 'badge-gray'][$t['priority']] ?? 'badge-gray' ?>"><?= ['urgent' => '紧急', 'high' => '较急', 'normal' => '一般', 'low' => '不急'][$t['priority']] ?? $t['priority'] ?></span></td>
                            <td><span class="badge <?= ['open' => 'badge-amber', 'processing' => 'badge-blue', 'resolved' => 'badge-green', 'closed' => 'badge-gray'][$t['status']] ?? 'badge-gray' ?>"><?= ['open' => '待处理', 'processing' => '处理中', 'resolved' => '已解决', 'closed' => '已关闭'][$t['status']] ?? $t['status'] ?></span></td>
                            <td class="muted small"><?= fmt_dt($t['created_at']) ?></td>
                            <td class="muted small"><?= fmt_dt($t['updated_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

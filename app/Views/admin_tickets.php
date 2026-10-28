<?php /** @var array $tickets */ ?>
<div class="page-head">
    <div>
        <h2>工单管理</h2>
        <p class="muted">处理用户转人工提交的问题，更新进度后用户端即可看到。</p>
    </div>
    <div class="page-actions">
        <div class="tabs">
            <?php
            $tabs = ['' => '全部', 'open' => '待处理', 'processing' => '处理中', 'resolved' => '已解决', 'closed' => '已关闭'];
            foreach ($tabs as $key => $label):
                $url = '/admin/tickets' . ($key !== '' ? '?status=' . $key : '');
                ?>
                <a class="tab <?= ($status ?? '') === $key ? 'is-active' : '' ?>" href="<?= url($url) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 70px">编号</th>
                    <th>问题</th>
                    <th style="width: 110px">提交人</th>
                    <th style="width: 80px">优先级</th>
                    <th style="width: 100px">状态</th>
                    <th style="width: 140px">提交时间</th>
                    <th style="width: 190px">处理</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tickets): ?>
                    <tr><td colspan="7" class="empty-cell">暂无工单</td></tr>
                <?php endif; ?>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="mono">#<?= (int) $t['id'] ?></td>
                        <td>
                            <strong><?= e($t['title']) ?></strong>
                            <div class="muted small clamp-2"><?= e($t['detail']) ?></div>
                            <?php if ($t['conversation_id']): ?>
                                <a class="tiny link" href="<?= url('/history') ?>?conversation=<?= (int) $t['conversation_id'] ?>">查看关联会话 #<?= (int) $t['conversation_id'] ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($t['display_name'] ?: '—') ?></td>
                        <td><span class="badge <?= ['urgent' => 'badge-red', 'high' => 'badge-amber', 'normal' => 'badge-blue', 'low' => 'badge-gray'][$t['priority']] ?? 'badge-gray' ?>"><?= ['urgent' => '紧急', 'high' => '较急', 'normal' => '一般', 'low' => '不急'][$t['priority']] ?? e($t['priority']) ?></span></td>
                        <td><span class="badge <?= ['open' => 'badge-amber', 'processing' => 'badge-blue', 'resolved' => 'badge-green', 'closed' => 'badge-gray'][$t['status']] ?? 'badge-gray' ?>"><?= ['open' => '待处理', 'processing' => '处理中', 'resolved' => '已解决', 'closed' => '已关闭'][$t['status']] ?? e($t['status']) ?></span></td>
                        <td class="muted small"><?= fmt_dt($t['created_at']) ?></td>
                        <td>
                            <form method="post" action="<?= url('/admin/tickets/update') ?>" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                <select name="status" class="mini-select">
                                    <?php foreach (['open' => '待处理', 'processing' => '处理中', 'resolved' => '已解决', 'closed' => '已关闭'] as $k => $v): ?>
                                        <option value="<?= $k ?>" <?= $t['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-small btn-primary" type="submit">更新</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

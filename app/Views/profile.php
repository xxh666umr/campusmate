<?php /** @var array $me */ /** @var array $stats */ ?>
<div class="page-head">
    <div>
        <h2>个人中心</h2>
        <p class="muted">管理你的账号信息与登录密码。</p>
    </div>
</div>

<div class="grid-2">
    <div class="card profile-card">
        <div class="profile-hero">
            <span class="avatar avatar-xl"><?= e(mb_substr($me['display_name'], 0, 1, 'UTF-8')) ?></span>
            <div>
                <h3><?= e($me['display_name']) ?></h3>
                <p class="muted">@<?= e($me['username']) ?> · <?= e(\App\Auth::roleLabel($me['role'])) ?></p>
                <span class="badge <?= $me['status'] === 'active' ? 'badge-green' : 'badge-red' ?>"><?= $me['status'] === 'active' ? '账号正常' : '已停用' ?></span>
            </div>
        </div>
        <div class="profile-stats">
            <div><strong><?= (int) $stats['conversations'] ?></strong><span>咨询会话</span></div>
            <div><strong><?= (int) $stats['messages'] ?></strong><span>消息条数</span></div>
            <div><strong><?= (int) $stats['tickets'] ?></strong><span>工单</span></div>
        </div>
        <dl class="facts">
            <div><dt>注册时间</dt><dd><?= fmt_dt($me['created_at']) ?></dd></div>
            <div><dt>最近登录</dt><dd><?= fmt_dt($me['last_login_at']) ?></dd></div>
            <div><dt>邮箱</dt><dd><?= e($me['email'] ?: '未填写') ?></dd></div>
        </dl>
    </div>

    <div class="stack">
        <div class="card">
            <div class="card-head"><span class="card-icon"><?= icon('user', 17) ?></span><h3>基本资料</h3></div>
            <form method="post" action="<?= url('/profile') ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="profile">
                <label class="field"><span>昵称</span><input type="text" name="display_name" value="<?= e($me['display_name']) ?>" required></label>
                <label class="field"><span>邮箱</span><input type="email" name="email" value="<?= e($me['email']) ?>" placeholder="选填"></label>
                <button class="btn btn-primary" type="submit">保存资料</button>
            </form>
        </div>

        <div class="card">
            <div class="card-head"><span class="card-icon"><?= icon('shield', 17) ?></span><h3>修改密码</h3></div>
            <form method="post" action="<?= url('/profile') ?>" class="form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <label class="field"><span>当前密码</span><input type="password" name="current_password" required></label>
                <div class="field-row">
                    <label class="field"><span>新密码</span><input type="password" name="new_password" required></label>
                    <label class="field"><span>确认新密码</span><input type="password" name="new_password_confirm" required></label>
                </div>
                <button class="btn btn-ghost" type="submit">更新密码</button>
            </form>
        </div>
    </div>
</div>

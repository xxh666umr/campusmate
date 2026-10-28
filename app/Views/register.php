<?php
$flashError = flash('error');
$config = $GLOBALS['APP_CONFIG'];
?>
<div class="auth">
    <section class="auth-hero">
        <div class="auth-hero-inner">
            <div class="auth-brand">
                <span class="brand-mark lg"><?= icon('spark', 26) ?></span>
                <span>
                    <strong>智服</strong>
                    <small>CampusMate · 校园智能客服</small>
                </span>
            </div>
            <h1>加入智服，<br>让校园事务更简单。</h1>
            <p class="auth-lead">注册后即可使用智能问答、历史记录、工单跟踪等全部功能。</p>
            <ul class="auth-points">
                <li><?= icon('check', 16) ?>三种咨询方式，覆盖常见校园场景</li>
                <li><?= icon('check', 16) ?>历史会话随时回看，支持关键词 / 正则检索</li>
                <li><?= icon('check', 16) ?>一键转人工，工单进度可追踪</li>
            </ul>
        </div>
        <div class="auth-hero-glow"></div>
    </section>

    <section class="auth-panel">
        <div class="auth-form-wrap">
            <h2>创建账号</h2>
            <p class="muted">只需几秒钟</p>

            <?php if ($flashError): ?>
                <div class="alert alert-error"><?= icon('alert', 17) ?><span><?= e($flashError) ?></span></div>
            <?php endif; ?>

            <form method="post" action="<?= url('/register') ?>" class="form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>用户名</span>
                    <input type="text" name="username" value="<?= e(old('username')) ?>" placeholder="字母开头，4-20 位" required>
                </label>
                <label class="field">
                    <span>昵称</span>
                    <input type="text" name="display_name" value="<?= e(old('display_name')) ?>" placeholder="展示在系统里的名字" required>
                </label>
                <label class="field">
                    <span>邮箱 <em class="optional">选填</em></span>
                    <input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="you@example.com">
                </label>
                <div class="field-row">
                    <label class="field">
                        <span>密码</span>
                        <input type="password" name="password" placeholder="至少 6 位" required>
                    </label>
                    <label class="field">
                        <span>确认密码</span>
                        <input type="password" name="password_confirm" placeholder="再输入一次" required>
                    </label>
                </div>
                <button class="btn btn-primary btn-block" type="submit">注册</button>
            </form>

            <p class="auth-switch">已有账号？<a href="<?= url('/login') ?>">返回登录</a></p>
        </div>
        <footer class="auth-foot">© <?= date('Y') ?> <?= e($config['school']) ?> · PHP 程序设计综合设计</footer>
    </section>
</div>

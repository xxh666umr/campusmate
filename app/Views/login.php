<?php
$flashError = flash('error');
$flashSuccess = flash('success');
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

            <h1>有问题，<br>先问「智服」。</h1>
            <p class="auth-lead">基于大语言模型的校园服务助手，把选课、成绩、网络、报修、资助这些事，一次说清楚。</p>

            <ul class="auth-points">
                <li><?= icon('check', 16) ?>单轮问答 / 多轮对话 / 信息选择，三种咨询方式</li>
                <li><?= icon('check', 16) ?>校园知识库检索，回答有依据、可追溯</li>
                <li><?= icon('check', 16) ?>解决不了自动转人工，全程留痕可统计</li>
            </ul>

            <div class="hero-card">
                <div class="hero-card-head">
                    <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                    <em>智服 · 在线</em>
                </div>
                <div class="mini-msg user">校园网一直认证失败怎么办？</div>
                <div class="mini-msg bot">
                    <span class="mini-avatar"><?= icon('robot', 16) ?></span>
                    <div>先检查账号是否为学号、密码是否身份证后 6 位；再确认有没有欠费或超过 3 台设备。仍不行可以到自助服务台重置密码。</div>
                </div>
            </div>
        </div>
        <div class="auth-hero-glow"></div>
    </section>

    <section class="auth-panel">
        <div class="auth-form-wrap">
            <h2>欢迎回来</h2>
            <p class="muted">登录后继续你的校园咨询</p>

            <?php if ($flashError): ?>
                <div class="alert alert-error"><?= icon('alert', 17) ?><span><?= e($flashError) ?></span></div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success"><?= icon('check', 17) ?><span><?= e($flashSuccess) ?></span></div>
            <?php endif; ?>

            <form method="post" action="<?= url('/login') ?>" class="form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>用户名</span>
                    <input type="text" name="username" value="<?= e(old('username')) ?>" placeholder="请输入用户名" autocomplete="username" required autofocus>
                </label>
                <label class="field">
                    <span>密码</span>
                    <input type="password" name="password" placeholder="请输入密码" autocomplete="current-password" required>
                </label>
                <button class="btn btn-primary btn-block" type="submit">登录</button>
            </form>

            <p class="auth-switch">还没有账号？<a href="<?= url('/register') ?>">立即注册</a></p>

            <?php if (!empty($config['demo_mode'])): ?>
            <div class="demo-box">
                <div class="demo-title"><?= icon('sparkles', 15) ?>演示账号（点击自动填入）</div>
                <div class="demo-grid">
                    <button type="button" class="demo-account" data-user="admin" data-pass="admin123">
                        <strong>管理员</strong><span>admin / admin123</span>
                    </button>
                    <button type="button" class="demo-account" data-user="teacher" data-pass="teacher123">
                        <strong>客服专员</strong><span>teacher / teacher123</span>
                    </button>
                    <button type="button" class="demo-account" data-user="student" data-pass="student123">
                        <strong>普通用户</strong><span>student / student123</span>
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <footer class="auth-foot">© <?= date('Y') ?> <?= e($config['school']) ?> · PHP 程序设计综合设计</footer>
    </section>
</div>

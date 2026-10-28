<?php
require_once __DIR__ . '/icons.php';
$me = \App\Auth::user();
$config = $GLOBALS['APP_CONFIG'];
$active = $active ?? '';
$bodyClass = $bodyClass ?? '';
$pageTitle = $title ?? '智服';

$nav = [];
if ($me) {
    $nav[] = ['key' => 'chat', 'url' => '/chat', 'icon' => 'chat', 'label' => '智能客服'];
    $nav[] = ['key' => 'history', 'url' => '/history', 'icon' => 'history', 'label' => '咨询历史'];
    $nav[] = ['key' => 'tickets', 'url' => '/tickets', 'icon' => 'ticket', 'label' => '我的工单'];
    if (in_array($me['role'], ['admin', 'agent'], true)) {
        $nav[] = ['key' => 'stats', 'url' => '/admin/stats', 'icon' => 'stats', 'label' => '数据看板'];
        $nav[] = ['key' => 'tickets_admin', 'url' => '/admin/tickets', 'icon' => 'shield', 'label' => '工单管理'];
    }
    if ($me['role'] === 'admin') {
        $nav[] = ['key' => 'users', 'url' => '/admin/users', 'icon' => 'users', 'label' => '用户管理'];
        $nav[] = ['key' => 'logs', 'url' => '/admin/logs', 'icon' => 'logs', 'label' => '日志管理'];
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($pageTitle) ?> · <?= e($config['name']) ?></title>
    <link rel="stylesheet" href="<?= asset('css/app.css') ?>">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop stop-color='%236d5cf6'/%3E%3Cstop offset='1' stop-color='%2322d3ee'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cpath d='M20 24h24v14a4 4 0 0 1-4 4H28l-8 6v-6a4 4 0 0 1-4-4z' fill='white'/%3E%3C/svg%3E">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if ($me): ?>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= url('/chat') ?>">
            <span class="brand-mark"><?= icon('spark', 22) ?></span>
            <span class="brand-text">
                <strong>智服</strong>
                <small>CampusMate</small>
            </span>
        </a>
        <nav class="nav">
            <?php foreach ($nav as $item): ?>
                <a class="nav-item <?= $active === $item['key'] ? 'is-active' : '' ?>" href="<?= url($item['url']) ?>">
                    <?= icon($item['icon'], 19) ?>
                    <span><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <a class="user-card" href="<?= url('/profile') ?>">
                <span class="avatar avatar-sm"><?= e(mb_substr($me['display_name'], 0, 1, 'UTF-8')) ?></span>
                <span class="user-meta">
                    <strong><?= e($me['display_name']) ?></strong>
                    <small><?= e(\App\Auth::roleLabel($me['role'])) ?></small>
                </span>
            </a>
            <a class="logout" href="<?= url('/logout') ?>" title="退出登录"><?= icon('logout', 18) ?></a>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn only-mobile" id="menuBtn" type="button" aria-label="菜单"><?= icon('menu', 20) ?></button>
            <div class="topbar-title">
                <h1><?= e($pageTitle) ?></h1>
                <p class="topbar-sub"><?= e($config['school']) ?> · 校园智能服务</p>
            </div>
            <div class="topbar-actions">
                <span class="date-chip"><?= icon('clock', 15) ?><?= date('m 月 d 日') ?></span>
                <a class="avatar avatar-sm" href="<?= url('/profile') ?>" title="<?= e($me['display_name']) ?>"><?= e(mb_substr($me['display_name'], 0, 1, 'UTF-8')) ?></a>
            </div>
        </header>

        <main class="content">
            <?php require $viewFile; ?>
        </main>
    </div>
</div>
<?php else: ?>
<?php require $viewFile; ?>
<?php endif; ?>

<div class="toast-wrap" id="toastWrap"></div>
<?php
$flashSuccess = flash('success');
$flashError = flash('error');
?>
<?php if ($flashSuccess || $flashError): ?>
<script>
window.__FLASH__ = <?= json_encode(['success' => $flashSuccess, 'error' => $flashError], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?php endif; ?>
<script>
window.APP = {
    base: <?= json_encode(base_path()) ?>,
    csrf: <?= json_encode(csrf_token()) ?>,
    me: <?= json_encode($me ? ['id' => (int) $me['id'], 'name' => $me['display_name'], 'role' => $me['role']] : null, JSON_UNESCAPED_UNICODE) ?>,
    page: <?= json_encode($active) ?>
};
</script>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>

<?php /** @var int $code */ /** @var string $title */ /** @var string $message */ ?>
<div class="error-page">
    <div class="error-code"><?= (int) $code ?></div>
    <h2><?= e($title) ?></h2>
    <p class="muted"><?= e($message) ?></p>
    <div class="error-actions">
        <a class="btn btn-primary" href="<?= url('/chat') ?>">回到智能客服</a>
        <a class="btn btn-ghost" href="<?= url('/') ?>">返回首页</a>
    </div>
</div>

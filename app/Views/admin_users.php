<?php /** @var array $users */ ?>
<div class="page-head">
    <div>
        <h2>用户管理</h2>
        <p class="muted">管理系统账号、角色与状态。角色决定可见功能与权限。</p>
    </div>
    <div class="page-actions">
        <form class="inline-search" method="get" action="<?= url('/admin/users') ?>">
            <input type="text" name="q" value="<?= e($q ?? '') ?>" placeholder="搜索用户名 / 昵称 / 邮箱">
            <button class="btn btn-ghost" type="submit"><?= icon('search', 16) ?> 搜索</button>
        </form>
        <button type="button" class="btn btn-primary" id="addUserBtn"><?= icon('plus', 16) ?> 新增用户</button>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 60px">ID</th>
                    <th>用户</th>
                    <th style="width: 110px">角色</th>
                    <th style="width: 90px">状态</th>
                    <th style="width: 150px">最近登录</th>
                    <th style="width: 150px">注册时间</th>
                    <th style="width: 120px">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="mono">#<?= (int) $u['id'] ?></td>
                        <td>
                            <div class="user-cell">
                                <span class="avatar avatar-xs"><?= e(mb_substr($u['display_name'], 0, 1, 'UTF-8')) ?></span>
                                <div>
                                    <strong><?= e($u['display_name']) ?></strong>
                                    <small class="muted">@<?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></small>
                                </div>
                            </div>
                        </td>
                        <td><span class="badge <?= ['admin' => 'badge-purple', 'agent' => 'badge-blue', 'user' => 'badge-gray'][$u['role']] ?? 'badge-gray' ?>"><?= e(\App\Auth::roleLabel($u['role'])) ?></span></td>
                        <td><span class="badge <?= $u['status'] === 'active' ? 'badge-green' : 'badge-red' ?>"><?= $u['status'] === 'active' ? '正常' : '停用' ?></span></td>
                        <td class="muted small"><?= fmt_dt($u['last_login_at']) ?></td>
                        <td class="muted small"><?= fmt_dt($u['created_at'], 'Y-m-d') ?></td>
                        <td>
                            <div class="row-actions">
                                <button type="button" class="icon-btn ghost" title="编辑"
                                    data-edit-user
                                    data-id="<?= (int) $u['id'] ?>"
                                    data-username="<?= e($u['username']) ?>"
                                    data-name="<?= e($u['display_name']) ?>"
                                    data-email="<?= e($u['email']) ?>"
                                    data-role="<?= e($u['role']) ?>"
                                    data-status="<?= e($u['status']) ?>"><?= icon('edit', 17) ?></button>
                                <?php if ((int) $u['id'] !== (int) $me['id']): ?>
                                    <form method="post" action="<?= url('/admin/users/delete') ?>" class="inline-form" data-confirm="确定删除用户 <?= e($u['username']) ?> 吗？">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                                        <button type="submit" class="icon-btn ghost danger" title="删除"><?= icon('trash', 17) ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal" id="userModal" hidden>
    <div class="modal-mask" data-close></div>
    <div class="modal-card">
        <div class="modal-head">
            <h3 id="userModalTitle">新增用户</h3>
            <button type="button" class="icon-btn ghost" data-close><?= icon('close', 18) ?></button>
        </div>
        <form method="post" action="<?= url('/admin/users/save') ?>" class="form" id="userForm">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="userId" value="0">
            <div class="field-row">
                <label class="field"><span>用户名</span><input type="text" name="username" id="userUsername" required placeholder="字母开头，4-20 位"></label>
                <label class="field"><span>昵称</span><input type="text" name="display_name" id="userName" required></label>
            </div>
            <label class="field"><span>邮箱</span><input type="email" name="email" id="userEmail" placeholder="选填"></label>
            <div class="field-row">
                <label class="field"><span>角色</span>
                    <select name="role" id="userRole">
                        <option value="user">普通用户</option>
                        <option value="agent">客服专员</option>
                        <option value="admin">管理员</option>
                    </select>
                </label>
                <label class="field"><span>状态</span>
                    <select name="status" id="userStatus">
                        <option value="active">正常</option>
                        <option value="disabled">停用</option>
                    </select>
                </label>
            </div>
            <label class="field"><span>密码 <em class="optional" id="pwdHint">新增必填</em></span><input type="password" name="password" id="userPassword" placeholder="至少 6 位"></label>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>取消</button>
                <button type="submit" class="btn btn-primary">保存</button>
            </div>
        </form>
    </div>
</div>

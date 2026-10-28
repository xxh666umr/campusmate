<?php /** @var array $me */ ?>
<div class="page-head">
    <div>
        <h2>咨询历史</h2>
        <p class="muted"><?= !empty($showUser) ? '管理员可查看全部用户的会话，支持关键词与正则检索。' : '回看每一次咨询，支持关键词与正则表达式检索。' ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= url('/export?type=history') ?>"><?= icon('download', 16) ?> 导出 CSV</a>
        <a class="btn btn-primary" href="<?= url('/chat') ?>"><?= icon('plus', 16) ?> 新建咨询</a>
    </div>
</div>

<div class="card">
    <form class="filter-bar" id="historyFilter">
        <div class="filter-group">
            <label class="mini-field">
                <span>方式</span>
                <select name="mode">
                    <option value="">全部</option>
                    <option value="single">单轮问答</option>
                    <option value="multi">多轮对话</option>
                    <option value="guided">信息选择</option>
                </select>
            </label>
            <label class="mini-field grow">
                <span>关键词 / 正则</span>
                <input type="text" name="q" placeholder="如：选课、校园网、^宿舍.*报修$">
            </label>
            <label class="mini-field">
                <span>日期</span>
                <input type="date" name="date">
            </label>
        </div>
        <div class="filter-group">
            <label class="switch">
                <input type="checkbox" name="regex" value="1">
                <span class="switch-track"></span>
                <span class="switch-label">正则模式</span>
            </label>
            <button type="submit" class="btn btn-primary"><?= icon('search', 16) ?> 搜索</button>
            <button type="reset" class="btn btn-ghost">重置</button>
        </div>
    </form>

    <div class="table-wrap">
        <table class="table" id="historyTable" data-show-user="<?= !empty($showUser) ? '1' : '0' ?>">
            <thead>
                <tr>
                    <th style="width: 90px">方式</th>
                    <th>会话标题</th>
                    <?php if (!empty($showUser)): ?><th style="width: 110px">用户</th><?php endif; ?>
                    <th style="width: 90px">消息数</th>
                    <th style="width: 180px">更新时间</th>
                    <th style="width: 90px"></th>
                </tr>
            </thead>
            <tbody id="historyBody">
                <tr><td colspan="<?= !empty($showUser) ? 6 : 5 ?>" class="empty-cell">正在加载…</td></tr>
            </tbody>
        </table>
    </div>
    <div class="pager" id="historyPager"></div>
</div>

<div class="drawer" id="convDrawer" hidden>
    <div class="drawer-mask" data-close></div>
    <aside class="drawer-panel">
        <header class="drawer-head">
            <div>
                <h3 id="drawerTitle">会话详情</h3>
                <p class="muted small" id="drawerMeta"></p>
            </div>
            <button type="button" class="icon-btn ghost" data-close><?= icon('close', 18) ?></button>
        </header>
        <div class="drawer-body" id="drawerMessages"></div>
    </aside>
</div>

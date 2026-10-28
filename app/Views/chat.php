<?php
/** @var array $provider */
/** @var array $guided */
/** @var array $recent */
/** @var array $categories */
$provider = $provider ?? ['provider' => 'mock', 'label' => '本地演示引擎', 'configured' => false];
$isSpark = ($provider['provider'] ?? 'mock') === 'spark';
?>
<div class="chat-layout" id="chatApp">
    <section class="chat-panel">
        <header class="chat-head">
            <div class="chat-identity">
                <span class="bot-avatar"><?= icon('robot', 22) ?></span>
                <div>
                    <strong>智服助手</strong>
                    <span class="status-line">
                        <i class="status-dot <?= $isSpark ? 'online' : 'local' ?>"></i>
                        <?= $isSpark ? '讯飞星火在线' : '本地演示引擎（未配置密钥）' ?>
                    </span>
                </div>
            </div>

            <div class="mode-switch" id="modeSwitch" role="tablist" aria-label="咨询方式">
                <button type="button" class="mode-btn is-active" data-mode="single" role="tab">
                    <b>单轮问答</b><small>独立提问</small>
                </button>
                <button type="button" class="mode-btn" data-mode="multi" role="tab">
                    <b>多轮对话</b><small>结合上下文</small>
                </button>
                <button type="button" class="mode-btn" data-mode="guided" role="tab">
                    <b>信息选择</b><small>选项式引导</small>
                </button>
            </div>

            <button type="button" class="icon-btn ghost" id="newChatBtn" title="开始新会话"><?= icon('plus', 20) ?></button>
        </header>

        <div class="chat-body" id="chatBody">
            <div class="chat-empty" id="chatEmpty">
                <span class="empty-orb"><?= icon('robot', 34) ?></span>
                <h2>你好，我是智服 👋</h2>
                <p>校园里的大小事，都可以先问我。选择一种方式开始吧：</p>
                <div class="empty-modes">
                    <div class="empty-mode">
                        <span class="em-icon"><?= icon('chat', 18) ?></span>
                        <strong>单轮问答</strong>
                        <small>一次一个问题，快速得到答案</small>
                    </div>
                    <div class="empty-mode">
                        <span class="em-icon"><?= icon('history', 18) ?></span>
                        <strong>多轮对话</strong>
                        <small>接着上文继续追问，逐步排查</small>
                    </div>
                    <div class="empty-mode">
                        <span class="em-icon"><?= icon('sparkles', 18) ?></span>
                        <strong>信息选择</strong>
                        <small>按类别点选，获得推荐处理办法</small>
                    </div>
                </div>
                <div class="quick-asks">
                    <button type="button" class="chip" data-ask="选课冲突了怎么办？">选课冲突了怎么办？</button>
                    <button type="button" class="chip" data-ask="校园网一直认证失败">校园网认证失败</button>
                    <button type="button" class="chip" data-ask="宿舍空调坏了怎么报修？">宿舍报修流程</button>
                    <button type="button" class="chip" data-ask="国家助学金需要哪些材料？">助学金材料</button>
                </div>
            </div>
        </div>

        <div class="guided-panel" id="guidedPanel" hidden>
            <div class="guided-step" id="guidedStepCats">
                <div class="guided-head">
                    <h3>先选择问题类别</h3>
                    <p>机器人会根据你的选择，给出对应的处理办法</p>
                </div>
                <div class="cat-grid" id="catGrid"></div>
            </div>
            <div class="guided-step" id="guidedStepIssues" hidden>
                <button type="button" class="back-link" id="guidedBack"><?= icon('arrow', 15) ?> 重新选择类别</button>
                <div class="guided-head"><h3 id="guidedCatTitle"></h3><p>请选择最接近你情况的一项</p></div>
                <div class="issue-list" id="issueList"></div>
            </div>
        </div>

        <form class="composer" id="composer" autocomplete="off">
            <textarea id="messageInput" rows="1" placeholder="描述你遇到的问题，Enter 发送，Shift + Enter 换行…" maxlength="2000"></textarea>
            <div class="composer-bar">
                <span class="composer-hint" id="composerHint">单轮模式：本次提问不会带入上文</span>
                <button type="submit" class="btn btn-primary" id="sendBtn">
                    <?= icon('send', 17) ?> 发送
                </button>
            </div>
        </form>
    </section>

    <aside class="chat-rail">
        <div class="card engine-card">
            <div class="card-head">
                <span class="card-icon"><?= icon('spark', 17) ?></span>
                <h3>当前引擎</h3>
                <span class="badge <?= $isSpark ? 'badge-green' : 'badge-amber' ?>"><?= $isSpark ? '星火' : '演示' ?></span>
            </div>
            <p class="muted small">
                <?php if ($isSpark): ?>
                    已接入讯飞星火大模型，回答由大模型实时生成，并附带知识库检索结果。
                <?php else: ?>
                    未检测到星火密钥，正在使用本地演示引擎。配置 <code>app/config.local.php</code> 后即可切换为真实大模型。
                <?php endif; ?>
            </p>
            <dl class="engine-facts">
                <div><dt>知识库</dt><dd><?= count($categories) ?> 个分类</dd></div>
                <div><dt>回答依据</dt><dd>检索 + 生成</dd></div>
                <div><dt>转人工</dt><dd>支持工单</dd></div>
            </dl>
        </div>

        <div class="card">
            <div class="card-head">
                <span class="card-icon"><?= icon('history', 17) ?></span>
                <h3>最近会话</h3>
                <a class="card-link" href="<?= url('/history') ?>">全部</a>
            </div>
            <div class="recent-list" id="recentList">
                <?php if (!$recent): ?>
                    <p class="muted small">还没有会话，从右侧问一句试试。</p>
                <?php endif; ?>
                <?php foreach ($recent as $row): ?>
                    <button type="button" class="recent-item" data-conv="<?= (int) $row['id'] ?>">
                        <span class="ri-mode"><?= ['single' => '单轮', 'multi' => '多轮', 'guided' => '选择'][$row['mode']] ?? $row['mode'] ?></span>
                        <span class="ri-title"><?= e($row['title']) ?></span>
                        <span class="ri-time"><?= fmt_dt($row['updated_at'], 'm-d') ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head">
                <span class="card-icon"><?= icon('book', 17) ?></span>
                <h3>知识库覆盖</h3>
            </div>
            <div class="kb-chips">
                <?php foreach ($categories as $cat): ?>
                    <span class="kb-chip"><?= e($cat['category']) ?><em><?= (int) $cat['n'] ?></em></span>
                <?php endforeach; ?>
            </div>
            <p class="muted tiny">回答优先依据知识库条目，减少模型编造。</p>
        </div>
    </aside>
</div>

<script type="application/json" id="guidedData"><?= json_encode($guided, JSON_UNESCAPED_UNICODE) ?></script>

<!-- 工单弹窗 -->
<div class="modal" id="ticketModal" hidden>
    <div class="modal-mask" data-close></div>
    <div class="modal-card">
        <div class="modal-head">
            <h3>转人工 · 提交工单</h3>
            <button type="button" class="icon-btn ghost" data-close><?= icon('close', 18) ?></button>
        </div>
        <p class="muted small">提交后，客服老师会在后台看到你的问题与本次对话。</p>
        <form id="ticketForm" class="form">
            <label class="field"><span>问题标题</span><input type="text" name="title" id="ticketTitle" required maxlength="100" placeholder="一句话说明问题"></label>
            <label class="field"><span>补充说明</span><textarea name="detail" id="ticketDetail" rows="4" placeholder="发生了什么、在哪个页面、已经尝试过什么"></textarea></label>
            <label class="field"><span>紧急程度</span>
                <select name="priority" id="ticketPriority">
                    <option value="normal">一般</option>
                    <option value="high">较急</option>
                    <option value="urgent">紧急</option>
                    <option value="low">不急</option>
                </select>
            </label>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" data-close>取消</button>
                <button type="submit" class="btn btn-primary">提交工单</button>
            </div>
        </form>
    </div>
</div>

<!-- 评价弹窗 -->
<div class="modal" id="ratingModal" hidden>
    <div class="modal-mask" data-close></div>
    <div class="modal-card narrow">
        <div class="modal-head">
            <h3>这次回答对你有帮助吗？</h3>
            <button type="button" class="icon-btn ghost" data-close><?= icon('close', 18) ?></button>
        </div>
        <div class="rating-stars" id="ratingStars">
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <button type="button" data-score="<?= $i ?>" aria-label="<?= $i ?> 分"><?= icon('star', 30) ?></button>
            <?php endfor; ?>
        </div>
        <textarea id="ratingComment" rows="3" placeholder="补充说明（选填）"></textarea>
        <button type="button" class="btn btn-primary btn-block" id="ratingSubmit">提交评价</button>
    </div>
</div>

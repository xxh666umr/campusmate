/* ============================================================
   智服 CampusMate — 前端交互
   ============================================================ */
(function () {
    'use strict';

    const APP = window.APP || { base: '', csrf: '', me: null, page: '' };

    /* ------------------------------ 基础工具 */

    function qs(sel, root) { return (root || document).querySelector(sel); }
    function qsa(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatText(text) {
        let html = escapeHtml(text);
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/`([^`]+)`/g, '<code class="code">$1</code>');
        return html;
    }

    function toast(message, type) {
        const wrap = qs('#toastWrap');
        if (!wrap || !message) return;
        const el = document.createElement('div');
        el.className = 'toast ' + (type || '');
        el.innerHTML = '<span>' + escapeHtml(message) + '</span>';
        wrap.appendChild(el);
        setTimeout(() => {
            el.style.transition = 'opacity .25s, transform .25s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(6px)';
            setTimeout(() => el.remove(), 260);
        }, 3200);
    }

    async function api(path, options) {
        options = options || {};
        const init = {
            method: options.method || 'GET',
            headers: { 'X-CSRF-Token': APP.csrf, 'Accept': 'application/json' },
            credentials: 'same-origin'
        };
        if (options.data !== undefined && options.data !== null) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.data);
        }
        const res = await fetch(APP.base + path, init);
        if (res.status === 401) {
            window.location.href = APP.base + '/login';
            throw new Error('未登录');
        }
        const data = await res.json().catch(() => ({ ok: false, error: '服务器返回格式错误' }));
        return data;
    }

    function openModal(el) { if (el) el.hidden = false; }
    function closeModal(el) { if (el) el.hidden = true; }

    document.addEventListener('click', (e) => {
        const closer = e.target.closest('[data-close]');
        if (closer) {
            const modal = closer.closest('.modal, .drawer');
            if (modal) modal.hidden = true;
        }
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            qsa('.modal:not([hidden]), .drawer:not([hidden])').forEach((m) => { m.hidden = true; });
        }
    });

    // 需要确认的表单
    qsa('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            if (!window.confirm(form.dataset.confirm)) e.preventDefault();
        });
    });

    // 演示账号快速填入
    qsa('.demo-account').forEach((btn) => {
        btn.addEventListener('click', () => {
            const form = qs('form.form');
            if (!form) return;
            form.querySelector('input[name="username"]').value = btn.dataset.user;
            form.querySelector('input[name="password"]').value = btn.dataset.pass;
            form.querySelector('button[type="submit"]').focus();
        });
    });

    // 移动端菜单
    const menuBtn = qs('#menuBtn');
    const sidebar = qs('#sidebar');
    if (menuBtn && sidebar) {
        menuBtn.addEventListener('click', () => sidebar.classList.toggle('is-open'));
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 860 && sidebar.classList.contains('is-open')
                && !sidebar.contains(e.target) && e.target !== menuBtn) {
                sidebar.classList.remove('is-open');
            }
        });
    }

    if (window.__FLASH__) {
        if (window.__FLASH__.success) toast(window.__FLASH__.success, 'success');
        if (window.__FLASH__.error) toast(window.__FLASH__.error, 'error');
    }

    /* ============================================================
       聊天页
       ============================================================ */

    const chatApp = qs('#chatApp');
    if (chatApp) {
        const body = qs('#chatBody');
        const empty = qs('#chatEmpty');
        const input = qs('#messageInput');
        const composer = qs('#composer');
        const sendBtn = qs('#sendBtn');
        const hint = qs('#composerHint');
        const guidedPanel = qs('#guidedPanel');
        const guidedData = JSON.parse(qs('#guidedData')?.textContent || '[]');

        const state = {
            mode: 'single',
            conversationId: null,
            lastMessageId: null,
            lastQuestion: '',
            sending: false
        };

        const modeHints = {
            single: '单轮模式：本次提问不会带入上文',
            multi: '多轮模式：会结合本会话最近 12 条消息',
            guided: '信息选择：按类别找到推荐处理办法'
        };

        function nowTime() {
            const d = new Date();
            return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }

        function scrollBottom() { body.scrollTop = body.scrollHeight; }

        function addUserMessage(text) {
            if (empty) empty.style.display = 'none';
            const row = document.createElement('div');
            row.className = 'msg user';
            row.innerHTML = `
                <div class="msg-avatar">我</div>
                <div class="msg-main">
                    <div class="msg-bubble">${formatText(text)}</div>
                    <div class="msg-meta">${nowTime()}</div>
                </div>`;
            body.appendChild(row);
            scrollBottom();
        }

        function addTyping() {
            const row = document.createElement('div');
            row.className = 'msg bot';
            row.id = 'typingRow';
            row.innerHTML = `
                <div class="msg-avatar">AI</div>
                <div class="msg-main">
                    <div class="msg-bubble"><span class="typing"><i></i><i></i><i></i></span></div>
                </div>`;
            body.appendChild(row);
            scrollBottom();
        }

        function removeTyping() {
            const row = qs('#typingRow');
            if (row) row.remove();
        }

        function addBotMessage(payload) {
            const row = document.createElement('div');
            row.className = 'msg bot';
            row.dataset.messageId = payload.message_id || '';

            const sources = (payload.sources || []).map((s) =>
                `<span class="source-chip">依据 · ${escapeHtml(s.category)} / ${escapeHtml(s.title)}</span>`).join('');
            const suggestions = (payload.suggestions || []).map((s) =>
                `<button type="button" class="suggestion" data-suggest="${escapeHtml(s)}">${escapeHtml(s)}</button>`).join('');
            const tools = [];
            tools.push(`<button type="button" class="tool-btn" data-rate-up>👍 有帮助</button>`);
            tools.push(`<button type="button" class="tool-btn" data-open-rating>评价</button>`);
            tools.push(`<button type="button" class="tool-btn" data-ticket>转人工</button>`);

            row.innerHTML = `
                <div class="msg-avatar">AI</div>
                <div class="msg-main">
                    <div class="msg-bubble">${formatText(payload.answer || '')}</div>
                    ${sources ? `<div class="sources">${sources}</div>` : ''}
                    ${suggestions ? `<div class="suggestions">${suggestions}</div>` : ''}
                    <div class="msg-tools">${tools.join('')}</div>
                    <div class="msg-meta">
                        <span>智服</span><span>${nowTime()}</span>
                        ${payload.provider === 'spark' ? '<span>星火大模型</span>' : '<span>本地演示引擎</span>'}
                        ${payload.sentiment === 'negative' ? '<span class="sentiment-tag">检测到负面情绪</span>' : ''}
                    </div>
                </div>`;

            body.appendChild(row);
            state.lastMessageId = payload.message_id || null;
            if (payload.conversation_id) state.conversationId = payload.conversation_id;
            scrollBottom();

            const up = row.querySelector('[data-rate-up]');
            if (up) {
                up.addEventListener('click', () => {
                    currentRatingMessage = row.dataset.messageId;
                    submitRating(5, '');
                });
            }
            const openRating = row.querySelector('[data-open-rating]');
            if (openRating) {
                openRating.addEventListener('click', () => {
                    currentRatingMessage = row.dataset.messageId;
                    qsa('#ratingStars button').forEach((b) => b.classList.remove('on'));
                    qs('#ratingComment').value = '';
                    openModal(qs('#ratingModal'));
                });
            }
            const ticketBtn = row.querySelector('[data-ticket]');
            if (ticketBtn) {
                ticketBtn.addEventListener('click', () => openTicket(payload));
            }
        }

        let currentRatingMessage = null;

        function submitRating(score, comment) {
            if (!currentRatingMessage) return;
            api('/api/feedback', { method: 'POST', data: { message_id: Number(currentRatingMessage), rating: score, comment: comment } })
                .then((res) => {
                    if (res.ok) {
                        toast('感谢你的评价', 'success');
                        closeModal(qs('#ratingModal'));
                    } else {
                        toast(res.error || '评价失败', 'error');
                    }
                })
                .catch(() => toast('网络异常，请稍后重试', 'error'));
        }

        // 评价弹窗
        const ratingStars = qs('#ratingStars');
        if (ratingStars) {
            qsa('button', ratingStars).forEach((btn) => {
                btn.addEventListener('click', () => {
                    const score = Number(btn.dataset.score);
                    qsa('button', ratingStars).forEach((b) => b.classList.toggle('on', Number(b.dataset.score) <= score));
                    ratingStars.dataset.score = score;
                });
            });
        }
        const ratingSubmit = qs('#ratingSubmit');
        if (ratingSubmit) {
            ratingSubmit.addEventListener('click', () => {
                const score = Number(ratingStars.dataset.score || 0);
                if (!score) { toast('请先选择星级', 'error'); return; }
                submitRating(score, qs('#ratingComment').value.trim());
            });
        }

        // 工单
        function openTicket(payload) {
            const title = qs('#ticketTitle');
            const detail = qs('#ticketDetail');
            title.value = state.lastQuestion || '咨询转人工';
            const answer = (payload && payload.answer) ? payload.answer.slice(0, 300) : '';
            detail.value = '用户问题：' + (state.lastQuestion || '') + (answer ? '\n\nAI 回复摘要：' + answer : '');
            openModal(qs('#ticketModal'));
        }

        const ticketForm = qs('#ticketForm');
        if (ticketForm) {
            ticketForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const data = {
                    title: qs('#ticketTitle').value.trim(),
                    detail: qs('#ticketDetail').value.trim(),
                    priority: qs('#ticketPriority').value,
                    conversation_id: state.conversationId || null
                };
                const res = await api('/api/ticket', { method: 'POST', data });
                if (res.ok) {
                    toast('工单 #' + res.ticket_id + ' 已提交', 'success');
                    closeModal(qs('#ticketModal'));
                } else {
                    toast(res.error || '提交失败', 'error');
                }
            });
        }

        // 事件委托：建议问题 / 引导
        body.addEventListener('click', (e) => {
            const sug = e.target.closest('[data-suggest]');
            if (sug) {
                const text = sug.dataset.suggest;
                if (text.includes('转人工')) {
                    openTicket({ answer: '' });
                } else {
                    send(text);
                }
            }
        });

        function setMode(mode) {
            state.mode = mode;
            state.conversationId = null;
            qsa('.mode-btn').forEach((b) => b.classList.toggle('is-active', b.dataset.mode === mode));
            hint.textContent = modeHints[mode] || '';
            if (mode === 'guided') {
                composer.style.display = 'none';
                guidedPanel.hidden = false;
                body.style.display = 'none';
                renderCategories();
            } else {
                composer.style.display = '';
                guidedPanel.hidden = true;
                body.style.display = '';
                if (empty) empty.style.display = '';
                qsa('.msg').forEach((m) => m.remove());
            }
        }

        qsa('.mode-btn').forEach((btn) => {
            btn.addEventListener('click', () => setMode(btn.dataset.mode));
        });

        async function send(text) {
            text = (text || input.value).trim();
            if (!text || state.sending) return;
            if (state.mode === 'guided') return;
            state.sending = true;
            sendBtn.disabled = true;
            input.value = '';
            input.style.height = 'auto';
            state.lastQuestion = text;

            addUserMessage(text);
            addTyping();

            try {
                const res = await api('/api/chat', {
                    method: 'POST',
                    data: {
                        mode: state.mode,
                        message: text,
                        conversation_id: state.mode === 'multi' ? state.conversationId : null
                    }
                });
                removeTyping();
                if (res.ok) {
                    addBotMessage(res);
                } else {
                    addBotMessage({ answer: res.error || '服务暂时不可用，请稍后重试。', message_id: 0, provider: 'mock' });
                }
            } catch (err) {
                removeTyping();
                addBotMessage({ answer: '网络异常，请检查服务是否启动。', message_id: 0, provider: 'mock' });
            } finally {
                state.sending = false;
                sendBtn.disabled = false;
                input.focus();
            }
        }

        if (composer) {
            composer.addEventListener('submit', (e) => { e.preventDefault(); send(); });
        }
        if (input) {
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    send();
                }
            });
            input.addEventListener('input', () => {
                input.style.height = 'auto';
                input.style.height = Math.min(input.scrollHeight, 160) + 'px';
            });
        }
        qsa('[data-ask]').forEach((btn) => {
            btn.addEventListener('click', () => send(btn.dataset.ask));
        });

        const newChatBtn = qs('#newChatBtn');
        if (newChatBtn) {
            newChatBtn.addEventListener('click', () => {
                state.conversationId = null;
                state.lastMessageId = null;
                qsa('.msg').forEach((m) => m.remove());
                if (empty) empty.style.display = '';
                input.value = '';
                input.focus();
            });
        }

        // 最近会话加载
        qsa('.recent-item').forEach((btn) => {
            btn.addEventListener('click', () => loadConversation(Number(btn.dataset.conv)));
        });

        async function loadConversation(id) {
            const res = await api('/api/conversation?id=' + id);
            if (!res.ok) { toast(res.error || '会话加载失败', 'error'); return; }
            state.conversationId = id;
            state.mode = res.conversation.mode;
            qsa('.mode-btn').forEach((b) => b.classList.toggle('is-active', b.dataset.mode === state.mode));
            qsa('.msg').forEach((m) => m.remove());
            if (empty) empty.style.display = 'none';
            if (res.conversation.mode === 'guided') {
                composer.style.display = '';
            }
            res.messages.forEach((m) => {
                if (m.role === 'user') {
                    addUserMessage(m.content);
                } else {
                    const meta = m.meta || {};
                    addBotMessage({
                        answer: m.content,
                        message_id: m.id,
                        conversation_id: id,
                        sources: meta.sources || [],
                        suggestions: meta.suggestions || [],
                        sentiment: meta.sentiment || 'neutral',
                        provider: meta.provider || 'mock'
                    });
                }
            });
            body.scrollTop = body.scrollHeight;
        }

        // 信息选择
        function renderCategories() {
            const grid = qs('#catGrid');
            qs('#guidedStepCats').hidden = false;
            qs('#guidedStepIssues').hidden = true;
            grid.innerHTML = guidedData.map((cat) => `
                <button type="button" class="cat-card" data-cat="${escapeHtml(cat.id)}">
                    <span class="cat-icon">${catIcon(cat.icon)}</span>
                    <strong>${escapeHtml(cat.name)}</strong>
                    <small>${escapeHtml(cat.desc)}</small>
                    <em>${cat.count} 个常见问题</em>
                </button>`).join('');
            qsa('.cat-card', grid).forEach((btn) => {
                btn.addEventListener('click', () => renderIssues(btn.dataset.cat));
            });
        }

        function catIcon(name) {
            const icons = {
                book: '📘', wifi: '📶', home: '🏠', award: '🏅', card: '💳', library: '📚'
            };
            return icons[name] || '💬';
        }

        function renderIssues(catId) {
            const cat = guidedData.find((c) => c.id === catId);
            if (!cat) return;
            qs('#guidedStepCats').hidden = true;
            qs('#guidedStepIssues').hidden = false;
            qs('#guidedCatTitle').textContent = cat.name;
            const list = qs('#issueList');
            list.innerHTML = cat.issues.map((issue) => `
                <button type="button" class="issue-item" data-issue="${escapeHtml(issue.id)}">
                    <span><strong>${escapeHtml(issue.title)}</strong><small>${escapeHtml(issue.symptom)}</small></span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 6l6 6-6 6"/></svg>
                </button>`).join('');
            qsa('.issue-item', list).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    qs('#guidedStepIssues').hidden = true;
                    body.style.display = '';
                    composer.style.display = 'none';
                    if (empty) empty.style.display = 'none';
                    const issue = cat.issues.find((i) => i.id === btn.dataset.issue);
                    addUserMessage(issue.title);
                    addTyping();
                    try {
                        const res = await api('/api/chat', {
                            method: 'POST',
                            data: { mode: 'guided', category: cat.id, issue: issue.id, message: issue.title }
                        });
                        removeTyping();
                        if (res.ok) {
                            addBotMessage(res);
                            renderGuidedActions();
                        } else {
                            toast(res.error || '获取失败', 'error');
                        }
                    } catch (e) {
                        removeTyping();
                        toast('网络异常', 'error');
                    }
                });
            });
        }

        function renderGuidedActions() {
            const actions = document.createElement('div');
            actions.className = 'guided-actions';
            actions.style.cssText = 'display:flex;gap:8px;margin-top:12px;flex-wrap:wrap';
            actions.innerHTML = `
                <button type="button" class="btn btn-small btn-ghost" data-guided-back>← 换一个问题</button>
                <button type="button" class="btn btn-small btn-primary" data-guided-ticket>没解决，转人工</button>`;
            body.appendChild(actions);
            actions.querySelector('[data-guided-back]').addEventListener('click', () => {
                actions.remove();
                body.style.display = 'none';
                guidedPanel.hidden = false;
                renderCategories();
            });
            actions.querySelector('[data-guided-ticket]').addEventListener('click', () => openTicket({ answer: '' }));
            scrollBottom();
        }

        const backBtn = qs('#guidedBack');
        if (backBtn) backBtn.addEventListener('click', renderCategories);

        setMode('single');

        // 从历史页跳转时自动打开指定会话
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('conversation')) {
            loadConversation(Number(urlParams.get('conversation')));
        }
    }

    /* ============================================================
       历史记录
       ============================================================ */

    const historyBody = qs('#historyBody');
    if (historyBody) {
        const form = qs('#historyFilter');
        const pager = qs('#historyPager');
        const showUser = qs('#historyTable').dataset.showUser === '1';
        const cols = showUser ? 6 : 5;
        let page = 1;

        const modeLabel = { single: '单轮', multi: '多轮', guided: '信息选择' };
        const modeBadge = { single: 'badge-blue', multi: 'badge-purple', guided: 'badge-green' };

        async function load(p) {
            page = p || 1;
            historyBody.innerHTML = `<tr><td colspan="${cols}" class="empty-cell">正在加载…</td></tr>`;
            const fd = new FormData(form);
            const params = new URLSearchParams();
            ['mode', 'q', 'date'].forEach((k) => { if (fd.get(k)) params.set(k, fd.get(k)); });
            if (fd.get('regex')) params.set('regex', '1');
            params.set('page', page);

            const res = await api('/api/history?' + params.toString());
            if (!res.ok) {
                historyBody.innerHTML = `<tr><td colspan="${cols}" class="empty-cell">加载失败</td></tr>`;
                return;
            }
            if (res.regexError) toast(res.regexError, 'error');

            if (!res.items.length) {
                historyBody.innerHTML = `<tr><td colspan="${cols}" class="empty-cell">没有找到符合条件的会话</td></tr>`;
                pager.innerHTML = '';
                return;
            }

            historyBody.innerHTML = res.items.map((item) => `
                <tr>
                    <td><span class="badge ${modeBadge[item.mode] || 'badge-gray'}">${modeLabel[item.mode] || item.mode}</span></td>
                    <td>
                        <strong>${escapeHtml(item.title)}</strong>
                        <div class="muted small clamp-1">${escapeHtml(item.last_message || '')}</div>
                    </td>
                    ${showUser ? `<td class="small">${escapeHtml(item.display_name || '—')}</td>` : ''}
                    <td class="mono">${item.msg_count}</td>
                    <td class="muted small nowrap">${escapeHtml(item.updated_at)}</td>
                    <td><button type="button" class="btn btn-small btn-ghost" data-view="${item.id}">查看</button></td>
                </tr>`).join('');

            qsa('[data-view]', historyBody).forEach((btn) => {
                btn.addEventListener('click', () => openConversation(btn.dataset.view));
            });

            renderPager(res.pages || 1, page);
        }

        function renderPager(pages, current) {
            if (!pager) return;
            let html = `<button type="button" ${current <= 1 ? 'disabled' : ''} data-page="${current - 1}">上一页</button>`;
            for (let i = 1; i <= pages; i++) {
                if (pages > 7 && Math.abs(i - current) > 2 && i !== 1 && i !== pages) {
                    if (i === 2 || i === pages - 1) html += '<span class="muted">…</span>';
                    continue;
                }
                html += `<button type="button" class="${i === current ? 'is-active' : ''}" data-page="${i}">${i}</button>`;
            }
            html += `<button type="button" ${current >= pages ? 'disabled' : ''} data-page="${current + 1}">下一页</button>`;
            pager.innerHTML = html;
            qsa('button[data-page]', pager).forEach((btn) => {
                btn.addEventListener('click', () => load(Number(btn.dataset.page)));
            });
        }

        async function openConversation(id) {
            const drawer = qs('#convDrawer');
            const box = qs('#drawerMessages');
            box.innerHTML = '<p class="muted small">正在加载…</p>';
            openModal(drawer);
            const res = await api('/api/conversation?id=' + id);
            if (!res.ok) { box.innerHTML = '<p class="muted small">加载失败</p>'; return; }
            qs('#drawerTitle').textContent = res.conversation.title;
            qs('#drawerMeta').textContent = '会话 #' + res.conversation.id + ' · ' + res.conversation.created_at + ' · ' + (modeLabel[res.conversation.mode] || res.conversation.mode);
            box.innerHTML = res.messages.map((m) => `
                <div class="msg ${m.role === 'user' ? 'user' : 'bot'}">
                    <div class="msg-avatar">${m.role === 'user' ? '我' : 'AI'}</div>
                    <div class="msg-main">
                        <div class="msg-bubble">${formatText(m.content)}</div>
                        <div class="msg-meta">${escapeHtml(m.created_at)}
                        ${m.meta && m.meta.provider ? '<span>· ' + escapeHtml(m.meta.provider) + '</span>' : ''}</div>
                    </div>
                </div>`).join('');
            box.scrollTop = 0;
        }

        form.addEventListener('submit', (e) => { e.preventDefault(); load(1); });
        qs('button[type="reset"]', form).addEventListener('click', () => setTimeout(() => load(1), 30));
        load(1).then(() => {
            const conv = new URLSearchParams(window.location.search).get('conversation');
            if (conv) openConversation(conv);
        });
    }

    /* ============================================================
       用户管理弹窗
       ============================================================ */

    const userModal = qs('#userModal');
    if (userModal) {
        const form = qs('#userForm');
        const addBtn = qs('#addUserBtn');

        function fill(data) {
            qs('#userId').value = data.id || 0;
            qs('#userUsername').value = data.username || '';
            qs('#userName').value = data.name || '';
            qs('#userEmail').value = data.email || '';
            qs('#userRole').value = data.role || 'user';
            qs('#userStatus').value = data.status || 'active';
            qs('#userPassword').value = '';
            qs('#userModalTitle').textContent = data.id ? '编辑用户' : '新增用户';
            qs('#pwdHint').textContent = data.id ? '留空则不修改' : '至少 6 位';
        }

        if (addBtn) {
            addBtn.addEventListener('click', () => { fill({}); openModal(userModal); });
        }
        qsa('[data-edit-user]').forEach((btn) => {
            btn.addEventListener('click', () => {
                fill({
                    id: btn.dataset.id,
                    username: btn.dataset.username,
                    name: btn.dataset.name,
                    email: btn.dataset.email,
                    role: btn.dataset.role,
                    status: btn.dataset.status
                });
                openModal(userModal);
            });
        });
    }

    /* ============================================================
       图表（Canvas）
       ============================================================ */

    const statsData = qs('#statsData');
    if (statsData) {
        const data = JSON.parse(statsData.textContent);
        const palette = ['#6d5cf6', '#22b8cf', '#7a5af8', '#f79009', '#12b76a', '#f04438'];

        function setup(canvas) {
            if (!canvas) return null;
            const dpr = window.devicePixelRatio || 1;
            const rect = canvas.getBoundingClientRect();
            if (!rect.width) return null;
            canvas.width = Math.round(rect.width * dpr);
            canvas.height = Math.round(rect.height * dpr);
            const ctx = canvas.getContext('2d');
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            return { ctx: ctx, w: rect.width, h: rect.height };
        }

        function lineChart(canvas, labels, series) {
            const s = setup(canvas);
            if (!s) return;
            const { ctx, w, h } = s;
            const pad = { l: 34, r: 14, t: 14, b: 28 };
            const iw = w - pad.l - pad.r;
            const ih = h - pad.t - pad.b;
            const max = Math.max(1, ...series.flatMap((x) => x.values));
            const step = Math.ceil(max / 5) || 1;
            const top = step * 5;

            ctx.clearRect(0, 0, w, h);
            ctx.font = '10px Inter, "PingFang SC", sans-serif';
            ctx.fillStyle = '#98a2b3';
            ctx.strokeStyle = '#eef0f6';
            ctx.lineWidth = 1;

            for (let i = 0; i <= 5; i++) {
                const y = pad.t + ih - (ih * i / 5);
                ctx.beginPath();
                ctx.moveTo(pad.l, y);
                ctx.lineTo(pad.l + iw, y);
                ctx.stroke();
                ctx.fillText(String(step * i), 6, y + 3);
            }

            const n = labels.length;
            const xAt = (i) => pad.l + (n === 1 ? iw / 2 : iw * i / (n - 1));
            labels.forEach((label, i) => {
                ctx.fillText(label, xAt(i) - 12, h - 8);
            });

            series.forEach((serie, si) => {
                const color = serie.color || palette[si];
                const points = serie.values.map((v, i) => [xAt(i), pad.t + ih - ih * v / top]);

                if (si === 0) {
                    const grad = ctx.createLinearGradient(0, pad.t, 0, pad.t + ih);
                    grad.addColorStop(0, 'rgba(109,92,246,.22)');
                    grad.addColorStop(1, 'rgba(109,92,246,0)');
                    ctx.beginPath();
                    ctx.moveTo(points[0][0], pad.t + ih);
                    points.forEach((p) => ctx.lineTo(p[0], p[1]));
                    ctx.lineTo(points[points.length - 1][0], pad.t + ih);
                    ctx.closePath();
                    ctx.fillStyle = grad;
                    ctx.fill();
                }

                ctx.beginPath();
                points.forEach((p, i) => i ? ctx.lineTo(p[0], p[1]) : ctx.moveTo(p[0], p[1]));
                ctx.strokeStyle = color;
                ctx.lineWidth = 2.2;
                ctx.lineJoin = 'round';
                ctx.stroke();

                points.forEach((p) => {
                    ctx.beginPath();
                    ctx.arc(p[0], p[1], 3.2, 0, Math.PI * 2);
                    ctx.fillStyle = '#fff';
                    ctx.fill();
                    ctx.lineWidth = 2;
                    ctx.strokeStyle = color;
                    ctx.stroke();
                });
            });
        }

        function barChart(canvas, labels, values, color) {
            const s = setup(canvas);
            if (!s) return;
            const { ctx, w, h } = s;
            const pad = { l: 30, r: 12, t: 14, b: 30 };
            const iw = w - pad.l - pad.r;
            const ih = h - pad.t - pad.b;
            const max = Math.max(1, ...values);
            const top = Math.ceil(max / 5) * 5 || 5;

            ctx.clearRect(0, 0, w, h);
            ctx.font = '10px Inter, "PingFang SC", sans-serif';
            ctx.fillStyle = '#98a2b3';
            ctx.strokeStyle = '#eef0f6';
            for (let i = 0; i <= 5; i++) {
                const y = pad.t + ih - ih * i / 5;
                ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + iw, y); ctx.stroke();
                ctx.fillText(String(top * i / 5), 4, y + 3);
            }

            const n = labels.length;
            const slot = iw / n;
            const bw = Math.min(34, slot * 0.5);
            labels.forEach((label, i) => {
                const left = pad.l + slot * i + (slot - bw) / 2;
                const bh = ih * values[i] / top;
                const grad = ctx.createLinearGradient(0, pad.t + ih - bh, 0, pad.t + ih);
                grad.addColorStop(0, color);
                grad.addColorStop(1, color + '99');
                ctx.fillStyle = values[i] ? grad : '#f2f4f8';
                const radius = Math.min(6, bh / 2);
                const y = pad.t + ih - bh;
                ctx.beginPath();
                ctx.moveTo(left, pad.t + ih);
                ctx.lineTo(left, y + radius);
                ctx.quadraticCurveTo(left, y, left + radius, y);
                ctx.lineTo(left + bw - radius, y);
                ctx.quadraticCurveTo(left + bw, y, left + bw, y + radius);
                ctx.lineTo(left + bw, pad.t + ih);
                ctx.closePath();
                ctx.fill();

                if (values[i]) {
                    ctx.fillStyle = '#475467';
                    ctx.font = '10px Inter, sans-serif';
                    ctx.fillText(String(values[i]), left + bw / 2 - 4, y - 5);
                }
                ctx.fillStyle = '#98a2b3';
                ctx.font = '10px Inter, "PingFang SC", sans-serif';
                ctx.fillText(label, pad.l + slot * i + slot / 2 - 11, h - 8);
            });
        }

        function donutChart(canvas, labels, values, centerEl) {
            const s = setup(canvas);
            if (!s) return;
            const { ctx, w, h } = s;
            const cx = w / 2, cy = h / 2;
            const r = Math.min(w, h) / 2 - 6;
            const inner = r * 0.62;
            const total = values.reduce((a, b) => a + b, 0) || 1;
            let start = -Math.PI / 2;

            ctx.clearRect(0, 0, w, h);
            values.forEach((v, i) => {
                const angle = (v / total) * Math.PI * 2;
                ctx.beginPath();
                ctx.arc(cx, cy, r, start, start + angle);
                ctx.arc(cx, cy, inner, start + angle, start, true);
                ctx.closePath();
                ctx.fillStyle = palette[i % palette.length];
                ctx.fill();
                start += angle;
            });

            ctx.beginPath();
            ctx.arc(cx, cy, inner - 1, 0, Math.PI * 2);
            ctx.fillStyle = '#fff';
            ctx.fill();

            if (centerEl) {
                centerEl.innerHTML = '<strong>' + total + '</strong><span>总会话</span>';
            }
        }

        function hBarChart(canvas, labels, values) {
            const s = setup(canvas);
            if (!s) return;
            const { ctx, w, h } = s;
            const pad = { l: 62, r: 30, t: 8, b: 8 };
            const iw = w - pad.l - pad.r;
            const rowH = Math.min(30, (h - pad.t - pad.b) / Math.max(1, labels.length));
            const max = Math.max(1, ...values);

            ctx.clearRect(0, 0, w, h);
            labels.forEach((label, i) => {
                const y = pad.t + i * rowH + rowH / 2;
                ctx.fillStyle = '#475467';
                ctx.font = '11px Inter, "PingFang SC", sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(label, pad.l - 10, y + 4);
                ctx.textAlign = 'left';

                const bw = Math.max(6, iw * values[i] / max);
                const grad = ctx.createLinearGradient(pad.l, 0, pad.l + bw, 0);
                grad.addColorStop(0, '#6d5cf6');
                grad.addColorStop(1, '#22b8cf');
                ctx.fillStyle = grad;
                ctx.beginPath();
                ctx.roundRect ? ctx.roundRect(pad.l, y - 6, bw, 12, 6) : ctx.rect(pad.l, y - 6, bw, 12);
                ctx.fill();

                ctx.fillStyle = '#667085';
                ctx.font = '10px Inter, sans-serif';
                ctx.fillText(String(values[i]), pad.l + bw + 6, y + 4);
            });
        }

        function drawAll() {
            lineChart(qs('#chartDaily'), data.daily.labels, [
                { values: data.daily.conversations, color: '#6d5cf6' },
                { values: data.daily.messages, color: '#22b8cf' }
            ]);
            donutChart(qs('#chartModes'), data.modes.labels, data.modes.values, qs('#donutCenter'));
            barChart(qs('#chartRatings'), data.ratings.labels, data.ratings.values, '#7a5af8');
            barChart(qs('#chartTickets'), data.tickets.labels, data.tickets.values, '#f79009');
            hBarChart(qs('#chartTopics'), data.topics.labels, data.topics.values);

            const legend = qs('#modeLegend');
            if (legend) {
                legend.innerHTML = data.modes.labels.map((label, i) =>
                    `<span><i style="background:${palette[i % palette.length]}"></i>${label} ${data.modes.values[i]}</span>`).join('');
            }
        }

        drawAll();
        let resizeTimer = null;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(drawAll, 160);
        });
    }
})();

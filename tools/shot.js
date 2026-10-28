/* 用 Chrome DevTools Protocol 登录并批量截图 */
const fs = require('fs');
const path = require('path');

const CDP_PORT = 9223;
const BASE = 'http://127.0.0.1:8899';
const OUT = process.argv[2] || path.join(process.cwd(), 'shots');

fs.mkdirSync(OUT, { recursive: true });

async function json(pathname, opts) {
    const res = await fetch(`http://127.0.0.1:${CDP_PORT}${pathname}`, opts);
    return res.json();
}

function connect(wsUrl) {
    return new Promise((resolve, reject) => {
        const ws = new WebSocket(wsUrl);
        const pending = new Map();
        let id = 0;
        ws.onopen = () => resolve({
            send(method, params = {}) {
                return new Promise((res, rej) => {
                    const msgId = ++id;
                    pending.set(msgId, { res, rej });
                    ws.send(JSON.stringify({ id: msgId, method, params }));
                });
            },
            close() { ws.close(); }
        });
        ws.onmessage = (ev) => {
            const msg = JSON.parse(ev.data);
            if (msg.id && pending.has(msg.id)) {
                const { res, rej } = pending.get(msg.id);
                pending.delete(msg.id);
                msg.error ? rej(new Error(JSON.stringify(msg.error))) : res(msg.result);
            }
        };
        ws.onerror = () => reject(new Error('ws error'));
    });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
    const targets = await json('/json/list');
    let target = targets.find((t) => t.type === 'page');
    if (!target) target = await json('/json/new?about:blank', { method: 'PUT' });
    const client = await connect(target.webSocketDebuggerUrl);
    await client.send('Page.enable');
    await client.send('Runtime.enable');
    await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });

    async function goto(url, wait = 900) {
        await client.send('Page.navigate', { url });
        await sleep(wait);
    }

    async function evaluate(expression) {
        await client.send('Runtime.evaluate', { expression, awaitPromise: false });
    }

    async function login(user, pass) {
        await goto(BASE + '/login', 800);
        await evaluate(`document.querySelector('input[name=username]').value='${user}';
                        document.querySelector('input[name=password]').value='${pass}';
                        document.querySelector('form.form').submit();`);
        await sleep(1800);
    }

    async function shot(name, fullPage = false) {
        if (fullPage) {
            const metrics = await client.send('Page.getLayoutMetrics');
            const height = Math.ceil(metrics.cssContentSize.height);
            await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height, deviceScaleFactor: 1, mobile: false });
            await sleep(350);
        }
        const { data } = await client.send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(data, 'base64'));
        console.log('saved', name);
        if (fullPage) {
            await client.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
            await sleep(200);
        }
    }

    await goto(BASE + '/login', 1000);
    await shot('01-login');

    // 学生视角：有历史数据
    await login('student', 'student123');
    await goto(BASE + '/chat', 1200);
    await shot('02-chat');

    await evaluate(`document.querySelector('[data-ask="校园网一直认证失败"]').click();`);
    await sleep(1800);
    await shot('03-chat-answer');

    await evaluate(`document.querySelector('.mode-btn[data-mode=guided]').click();`);
    await sleep(500);
    await shot('04-guided');

    await evaluate(`document.querySelector('.cat-card[data-cat=network]').click();`);
    await sleep(400);
    await shot('04b-guided-issues');

    await evaluate(`document.querySelector('.issue-item[data-issue=renzheng]').click();`);
    await sleep(1600);
    await shot('05-guided-answer');

    await goto(BASE + '/history', 1400);
    await shot('06-history');

    await goto(BASE + '/tickets', 1000);
    await shot('06b-tickets');

    // 管理员视角
    await goto(BASE + '/logout', 900);
    await login('admin', 'admin123');
    await goto(BASE + '/admin/stats', 1800);
    await shot('07-stats', true);

    await goto(BASE + '/admin/users', 1000);
    await shot('08-users');

    await goto(BASE + '/admin/logs', 1000);
    await shot('09-logs');

    await goto(BASE + '/admin/tickets', 1000);
    await shot('10-tickets');

    await goto(BASE + '/profile', 900);
    await shot('11-profile');

    client.close();
}

main().catch((e) => { console.error(e); process.exit(1); });

import json
import re
import urllib.parse
import urllib.request
import urllib.error
import http.cookiejar

BASE = 'http://127.0.0.1:8899'
ok_all = True


def new_session(user, pwd):
    jar = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
    html = op.open(BASE + '/login', timeout=10).read().decode()
    csrf = re.search(r'name="csrf-token" content="([^"]+)"', html).group(1)
    req = urllib.request.Request(BASE + '/login', data=urllib.parse.urlencode(
        {'username': user, 'password': pwd, '_csrf': csrf}).encode(), method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    page = op.open(req, timeout=10).read().decode()
    csrf = re.search(r'name="csrf-token" content="([^"]+)"', page).group(1)
    return op, csrf


def post(op, path, data, csrf):
    req = urllib.request.Request(BASE + path, data=json.dumps(data, ensure_ascii=False).encode(), method='POST')
    req.add_header('Content-Type', 'application/json')
    req.add_header('X-CSRF-Token', csrf)
    try:
        with op.open(req, timeout=20) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode())


def get(op, path):
    try:
        with op.open(BASE + path, timeout=15) as r:
            return r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode()


def check(name, cond, extra=''):
    global ok_all
    print(('PASS' if cond else 'FAIL'), name, extra)
    ok_all = ok_all and cond


op, csrf = new_session('student', 'student123')

status, data = post(op, '/api/chat', {'mode': 'single', 'message': '选课冲突了怎么办？'}, csrf)
check('single answer + source', status == 200 and data['sources'] and '选课' in data['answer'], data['sources'][:1])

status, data = post(op, '/api/chat', {'mode': 'guided', 'category': 'dorm', 'issue': 'baoxiu', 'message': '报修'}, csrf)
check('guided answer', status == 200 and '推荐处理步骤' in data['answer'])

status, first = post(op, '/api/chat', {'mode': 'multi', 'message': '校园网一直认证失败'}, csrf)
status, second = post(op, '/api/chat', {'mode': 'multi', 'message': '那还是不行怎么办', 'conversation_id': first['conversation_id']}, csrf)
check('multi context', second['answer'].startswith('接着刚才'), second['answer'][:20])

status, data = post(op, '/api/chat', {'mode': 'single', 'message': '我要投诉，太差了'}, csrf)
check('negative sentiment -> ticket', data['sentiment'] == 'negative' and data['need_ticket'] is True)

status, data = post(op, '/api/feedback', {'message_id': second['message_id'], 'rating': 5, 'comment': 'ok'}, csrf)
check('feedback', status == 200 and data['ok'])

status, data = post(op, '/api/ticket', {'title': '验收测试工单', 'detail': '测试', 'priority': 'high'}, csrf)
check('ticket', status == 200 and data.get('ticket_id'))

status, body = get(op, '/api/history?page=1&regex=1&q=' + urllib.parse.quote('^校园网.*失败$'))
check('history regex', status == 200 and json.loads(body)['ok'])

status, body = get(op, '/api/guided/tree')
cats = json.loads(body)['categories']
check('guided tree has issues', all('issues' in c and c['issues'] for c in cats), str(len(cats)))

status, body = get(op, '/admin/users')
check('student 403', status == 403)

# 无 CSRF 应被拒绝
req = urllib.request.Request(BASE + '/api/chat', data=b'{"mode":"single","message":"hi"}', method='POST')
req.add_header('Content-Type', 'application/json')
try:
    op.open(req, timeout=10)
    check('csrf reject', False)
except urllib.error.HTTPError as e:
    check('csrf reject', e.code == 419, str(e.code))

admin, acsrf = new_session('admin', 'admin123')
for path in ['/admin/users', '/admin/logs', '/admin/tickets', '/admin/stats', '/chat', '/history']:
    status, body = get(admin, path)
    check('admin page ' + path, status == 200)

status, body = get(admin, '/export?type=history')
check('export history', status == 200 and '咨询方式' in body)

status, body = get(admin, '/export?type=logs')
check('export logs', status == 200)

print('\nRESULT:', 'ALL PASS' if ok_all else 'HAS FAILURES')

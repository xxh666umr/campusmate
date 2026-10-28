import json
import re
import urllib.parse
import urllib.request
import http.cookiejar

BASE = 'http://127.0.0.1:8899'


def session():
    jar = http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def get(op, path):
    try:
        with op.open(BASE + path, timeout=15) as r:
            return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


def login(op, user, pwd):
    _, html = get(op, '/login')
    csrf = re.search(r'name="csrf-token" content="([^"]+)"', html).group(1)
    body = urllib.parse.urlencode({'username': user, 'password': pwd, '_csrf': csrf}).encode()
    req = urllib.request.Request(BASE + '/login', data=body, method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    with op.open(req, timeout=15) as r:
        page = r.read().decode('utf-8', 'replace')
    return re.search(r'name="csrf-token" content="([^"]+)"', page).group(1)


def post_json(op, path, data, csrf):
    body = json.dumps(data, ensure_ascii=False).encode()
    req = urllib.request.Request(BASE + path, data=body, method='POST')
    req.add_header('Content-Type', 'application/json')
    req.add_header('X-CSRF-Token', csrf)
    try:
        with op.open(req, timeout=15) as r:
            return r.status, r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode('utf-8', 'replace')


admin = session()
csrf = login(admin, 'admin', 'admin123')
print('admin login ok')

for path in ['/admin/users', '/admin/logs', '/admin/tickets', '/admin/stats', '/profile', '/history', '/tickets', '/chat']:
    status, html = get(admin, path)
    print(path, status, len(html))

status, body = post_json(admin, '/api/chat', {'mode': 'single', 'message': '宿舍空调坏了怎么报修？'}, csrf)
data = json.loads(body)
print('chat', status, data.get('answer', '')[:80], '| need_ticket', data.get('need_ticket'))
mid = data.get('message_id')
status, body = post_json(admin, '/api/feedback', {'message_id': mid, 'rating': 5, 'comment': '答复很清楚'}, csrf)
print('feedback', status, body[:120])
status, body = post_json(admin, '/api/ticket', {'title': '测试工单：宿舍报修', 'detail': '用于验证工单流程', 'priority': 'high'}, csrf)
print('ticket', status, body[:150])

status, csv = get(admin, '/export?type=logs')
print('export logs', status, 'bytes', len(csv))

status, body = get(admin, '/api/logs/file')
print('log file api', status, len(json.loads(body).get('lines', [])))

student = session()
csrf2 = login(student, 'student', 'student123')
status, html = get(student, '/admin/users')
print('student -> /admin/users', status, 'contains 没有权限' if '没有权限' in html else 'unexpected')

status, body = post_json(student, '/api/chat', {'mode': 'single', 'message': ''}, csrf2)
print('empty message ->', status, body[:100])

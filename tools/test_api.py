import json
import re
import sys
import urllib.parse
import urllib.request
import http.cookiejar

BASE = 'http://127.0.0.1:8899'
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def get(path):
    with opener.open(BASE + path, timeout=15) as r:
        return r.status, r.read().decode('utf-8', 'replace')


def post_form(path, data):
    body = urllib.parse.urlencode(data).encode()
    req = urllib.request.Request(BASE + path, data=body, method='POST')
    req.add_header('Content-Type', 'application/x-www-form-urlencoded')
    with opener.open(req, timeout=15) as r:
        return r.status, r.read().decode('utf-8', 'replace')


def post_json(path, data, csrf):
    body = json.dumps(data, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(BASE + path, data=body, method='POST')
    req.add_header('Content-Type', 'application/json; charset=utf-8')
    req.add_header('X-CSRF-Token', csrf)
    with opener.open(req, timeout=30) as r:
        return r.status, r.read().decode('utf-8', 'replace')


status, html = get('/login')
csrf = re.search(r'name="csrf-token" content="([^"]+)"', html).group(1)
print('login page', status, 'csrf', csrf[:12])

status, html = post_form('/login', {'username': 'student', 'password': 'student123', '_csrf': csrf})
print('login', status, 'len', len(html))
csrf = re.search(r'name="csrf-token" content="([^"]+)"', html).group(1)

for mode, msg in [('single', '选课冲突了怎么办？'), ('multi', '校园网一直认证失败'), ('multi', '那还是不行怎么办')]:
    status, body = post_json('/api/chat', {'mode': mode, 'message': msg}, csrf)
    data = json.loads(body)
    print('\n== chat', mode, status, '==')
    print('answer:', data.get('answer', '')[:300])
    print('sources:', data.get('sources'))
    print('need_ticket:', data.get('need_ticket'), 'provider:', data.get('provider'))

status, body = post_json('/api/chat', {'mode': 'guided', 'category': 'network', 'issue': 'renzheng', 'message': '校园网认证失败'}, csrf)
data = json.loads(body)
print('\n== guided ==', status)
print(data.get('answer', '')[:300])

status, body = get('/api/history?page=1')
print('\nhistory', status, json.loads(body).get('total'))

"""Run against an EMPTY pcf_test_* DB; copy the app to a temporary directory.
Required: PHP CLI with pdo_mysql/mysqli/mbstring/xml, Python 3, MySQL/MariaDB.
PCF_TEST_DB_NAME/USER/PASS are required; HOST/PORT and PCF_PHP_BIN are optional.
The runner does not delete or reset any database. It leaves test data in that DB.
"""
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PCF_PHP_BIN', 'php')

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

with tempfile.TemporaryDirectory(prefix='pcf-repair-') as temporary:
    work = Path(temporary)
    app = work / 'app'
    shutil.copytree(ROOT, app, ignore=shutil.ignore_patterns('.git', 'config.local.php', 'storage', 'cache', 'logs', '__pycache__'))
    (work / 'sessions').mkdir()
    subprocess.run([PHP, str(app / 'tests/repair_fixture.php'), 'check-empty'], check=True)
    # The PHP development server needs the root-to-public mapping supplied by Apache in deployment.
    router = work / 'router.php'
    router.write_text('''<?php
$root = __DIR__ . '/app';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$target = $root . ($path === '/' ? '/index.php' : $path);
if ($path === '/robots.txt') $target = $root . '/public/robots.php';
if (!is_file($target)) $target = $root . '/public' . $path;
if (!is_file($target)) { http_response_code(404); exit; }
if (pathinfo($target, PATHINFO_EXTENSION) !== 'php') {
    $types = ['css'=>'text/css', 'js'=>'application/javascript'];
    header('Content-Type: '.($types[pathinfo($target, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($target); exit;
}
$_SERVER['SCRIPT_NAME'] = str_replace($root, '', $target);
$_SERVER['SCRIPT_FILENAME'] = $target;
// Simulate TLS termination only for the home page's existing HTTPS canonical redirect.
if ($path === '/') $_SERVER['HTTPS'] = 'on';
require $target;
''')
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    env = dict(os.environ, BASE_URL=origin)
    with (work / 'server.log').open('w') as log:
        server = subprocess.Popen([PHP, '-d', f'session.save_path={work / "sessions"}', '-S', f'127.0.0.1:{port}', '-t', str(app), str(router)], env=env, stdout=log, stderr=log)
        try:
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1', port), .1):
                        break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('PHP server did not start')
            admin = client()
            public = client()
            def request(path, data=None, who=public, headers=None):
                encoded = urllib.parse.urlencode(data).encode() if data is not None else None
                req = urllib.request.Request(origin + path, data=encoded, headers={'User-Agent': 'Mozilla/5.0 Repair QA', **(headers or {})})
                try:
                    response = who.open(req, timeout=20)
                except urllib.error.HTTPError as error:
                    response = error
                return response.code, response.read().decode('utf-8'), response.headers
            def csrf(body):
                found = re.search(r'name="_csrf" value="([^"]+)"', body)
                assert found, 'Missing CSRF token'
                return html.unescape(found.group(1))
            setup = '/public/setup_check.php'
            status, body, _ = request(setup, who=admin)
            assert status == 200
            fields = dict(action='save_db_config', db_host=os.environ.get('PCF_TEST_DB_HOST', '127.0.0.1'), db_port=os.environ.get('PCF_TEST_DB_PORT', '3306'), db_name=os.environ['PCF_TEST_DB_NAME'], db_user=os.environ['PCF_TEST_DB_USER'], db_pass=os.environ['PCF_TEST_DB_PASS'])
            _, body, _ = request(setup, {**fields, '_csrf': 'invalid'}, admin)
            assert not (app / 'config.local.php').exists(), 'Invalid CSRF saved config'
            _, body, _ = request(setup, {**fields, '_csrf': csrf(body), 'db_pass': 'wrong-fixture-password'}, admin)
            assert '1045' in body and not (app / 'config.local.php').exists(), 'Bad credentials were saved or diagnosis missing'
            _, body, _ = request(setup, {**fields, '_csrf': csrf(body)}, admin)
            assert 'DB接続テストに成功' in body, 'Valid DB config was not saved'
            saved = (app / 'config.local.php').read_bytes()
            _, body, _ = request(setup, {**fields, '_csrf': csrf(body), 'db_pass': ''}, admin)
            assert (app / 'config.local.php').read_bytes() == saved, 'Blank password did not preserve config'
            _, body, _ = request(setup, {**fields, '_csrf': csrf(body), 'db_pass': 'wrong-fixture-password'}, admin)
            assert '1045' in body and (app / 'config.local.php').read_bytes() == saved, 'Failed connection replaced working config'
            status, _, headers = request(setup, {'action': 'run_installer', '_csrf': csrf(body)}, admin)
            assert status == 302 and headers['Location'].endswith('/public/login0718.php'), 'Fresh install failed in the same HTTP request'
            _, body, _ = request('/public/login0718.php', who=admin)
            initial = re.search(r'パスワード: <code>([^<]+)</code>', body)
            assert initial, 'Initial admin password was not delivered'
            status, _, headers = request('/public/login0718.php', {'_csrf': csrf(body), 'username': 'admin', 'password': html.unescape(initial.group(1))}, admin)
            assert status == 302 and '/admin/' in headers['Location'], 'Initial admin cannot log in'
            subprocess.run([PHP, str(app / 'tests/repair_fixture.php')], env=env, check=True)
            status, about, _ = request('/page.php?slug=about')
            assert status == 200 and 'Privacy Policy</a>' in about and 'Privacy Policy</a>ページ' not in about and '下記のページをご覧下さい' in about
            status, covers, _ = request('/recent_images.php?ids=101&v=portrait-2')
            assert status == 200 and json.loads(covers)['images']['101'].endswith('fixtureps.jpg')
            print('PASS: saved about text and portrait history endpoint')
            print('PASS: CSRF, credential validation, blank-password preservation, fresh HTTP setup and admin login')
            pages = {'/': '動作確認作品', '/items.php': '動作確認作品', '/item.php?id=101': '動作確認作品', '/genres.php': '検証ジャンル', '/genre.php?id=101': '動作確認作品', '/makers.php': '検証メーカー', '/maker.php?id=101': '動作確認作品', '/series_list.php': '検証シリーズ', '/series_detail.php?id=101': '動作確認作品', '/labels.php': '検証レーベル', '/label.php?id=9001': '動作確認作品', '/actresses.php': 'data-actress-lazy-group', '/actress.php?id=101': '動作確認作品', '/author.php?id=101': '動作確認作品', '/search.php?q=' + urllib.parse.quote('動作確認'): '動作確認作品'}
            for path, expected in pages.items():
                status, body, _ = request(path)
                assert status == 200 and expected in body and '</html>' in body, (path, status, 'Incomplete page')
            for actress_id, name in [(190903, 'プロフィール検証女優'), (1611931, '出演作品未登録の女優')]:
                status, body, _ = request(f'/actress.php?id={actress_id}')
                assert status == 200 and name in body and '現在、公開中の出演作品はありません。' in body
            status, body, _ = request('/')
            notice = '18+：当サイトはアダルトサイトで18歳未満の方はご利用出来ません。'
            assert body.index(notice) < body.index('当サイトはアフィリエイト広告を利用しています。')
            assert re.search(r'Copyright ©2020-\d{4} <a[^>]+>[^<]+</a> All Rights Reserved\.', body)
            assert re.search(r'<a[^>]+href="[^"]*actress.php\?id=190903"[^>]+>\s*<img', body)
            print('PASS: actress profiles without works, linked portraits, age notice, copyright start year')
            status, body, _ = request('/actresses_group.php?group=' + urllib.parse.quote('kana:か'))
            assert status == 200 and json.loads(body)['rows'][0][1] == '検証出演者'
            status, body, _ = request('/actresses_group.php?group=other')
            assert status == 200 and any(row[1] == '漢字名出演者' for row in json.loads(body)['rows'])
            for path in ['/admin/index.php', '/admin/site_settings.php', '/admin/pages_index.php']:
                status, body, _ = request(path, who=admin)
                assert status == 200 and '</html>' in body, (path, status)
            status, body, headers = request('/item.php?id=101')
            assert headers.get('X-PCF-Page-Cache') == 'HIT'
            assert 'data-recent-front-cover="https://img.sokmil.com/image/capture/ss_fixture001.jpg"' in body
            assert len(re.findall(r'property="og:title"', body)) == 1
            assert len(re.findall(r'property="og:image"', body)) == 1
            assert len(re.findall(r'rel="canonical"', body)) == 1
            for key in ['cid', 'content_id']:
                status, _, headers = request('/item.php?' + key + '=fixture001')
                assert status == 301 and headers['Location'] == origin + '/item.php?id=101'
            for path in ['/item.php?id=999999', '/genre.php?id=999999']:
                assert request(path)[0] == 404
            for path, mime in [('/feed.php', 'application/rss+xml'), ('/sitemap.php', 'application/xml'), ('/sitemap_index.php', 'application/xml'), ('/robots.txt', 'text/plain')]:
                for _ in range(2):
                    status, body, headers = request(path)
                    assert status == 200 and headers['Content-Type'].startswith(mime), (path, status)
                    if path == '/robots.txt': assert 'Sitemap: ' + origin + '/sitemap.php' in body.splitlines(), body
                    if path == '/sitemap.php' and _ == 1: assert headers.get('X-PCF-Page-Cache') == 'HIT'
            assert request('/admin/index.php')[0] == 302
            assert request(setup)[0] == 302, 'Anonymous user can reopen completed setup'
            print(f'PASS: {len(pages)} public pages, 3 admin pages, cache, canonical/OGP, redirects, 404, feeds and setup protection')
            subprocess.run([PHP, str(app / 'tests/search_recovery_fixture.php')], check=True, env=env)
            subprocess.run([PHP, str(app / 'tests/search_recovery_fixture.php'), 'ranking_failure'], check=True, env=env)
            status, body, _ = request('/admin/search_settings.php', who=admin)
            assert status == 200 and 'SEO・IndexNow' in body
            token = csrf(body)
            assert request('/admin/search_settings.php', dict(action='enable'), who=admin)[0] == 419
            assert request('/admin/search_settings.php')[0] == 302
            status, body, _ = request('/admin/search_settings.php', dict(action='enable', _csrf=token, confirmed_origin=origin), who=admin)
            assert status == 200 and '有効にしました' in body
            status, key, headers = request('/indexnow-key.php')
            assert status == 200 and re.fullmatch(r'[a-zA-Z0-9-]{8,128}', key) and headers['Content-Type'].startswith('text/plain')
            assert request('/public/indexnow-key.php')[1] == key
            status, body, _ = request('/admin/search_settings.php', dict(action='gone', _csrf=token, item_id=101, reason='配信終了をテスト確認'), who=admin)
            assert status == 200 and '掲載終了（410）に設定しました' in body
            for path in ['/item.php?id=101', '/item.php?cid=fixture001']:
                status, body, headers = request(path)
                assert status == 410 and 'noindex' in body and headers.get('Cache-Control') == 'no-store'
            status, body, _ = request('/sitemap.php?part=1')
            assert status == 200 and '/item.php?id=101' not in body
            assert request('/item.php?id=999999')[0] == 404
            status, body, _ = request('/admin/search_settings.php', dict(action='restore', _csrf=token, item_id=101), who=admin)
            assert status == 200 and '解除しました' in body
            status, body, headers = request('/item.php?id=101')
            assert status == 200 and body.count('name="rating"') == 1
            assert headers.get('Referrer-Policy') == 'strict-origin-when-cross-origin'
            assert 70 <= len(html.unescape(re.search(r'name="description" content="([^"]+)"', body)[1])) <= 160
            assert body.count('property="og:image"') == 1 and body.count('rel="canonical"') == 1
            assert request('/item.php?id[]=101')[0] == 404
            request('/admin/search_settings.php', dict(action='disable', _csrf=token), who=admin)
            assert request('/indexnow-key.php')[0] == 404
            subprocess.run([PHP, str(app / 'tests/search_recovery_fixture.php'), 'outage_on'], check=True, env=env)
            try:
                status, body, headers = request('/item.php?id=101')
                assert status == 503 and headers.get('Retry-After') == '300' and 'noindex' not in body
            finally:
                subprocess.run([PHP, str(app / 'tests/search_recovery_fixture.php'), 'outage_off'], check=True, env=env)
            assert request('/item.php?id=101')[0] == 200
            print('PASS: admin auth/CSRF, IndexNow key, cached 200→410→200, sitemap exclusion, 404/503, metadata headers')
            if os.environ.get('PCF_BROWSER_CHECK'):
                subprocess.run([os.environ.get('PCF_NODE_BIN', 'node'), os.environ['PCF_BROWSER_CHECK'], origin, str(work)], check=True)
            log.flush()
            errors = (work / 'server.log').read_text()
            assert not re.search(r'PHP (?:Fatal error|Warning|Parse error)|Uncaught', errors), errors
            print('PASS: no PHP runtime errors')
        finally:
            server.terminate()
            server.wait(timeout=10)

"""HTTP cookie authentication + genuine PHP multipart upload against the disposable QA server."""
import concurrent.futures
import http.cookiejar
import json
import pathlib
import re
import urllib.error
import urllib.parse
import urllib.request
import uuid
import os
import socket
import subprocess
import sys
import shutil
import time
import threading
import struct

runtime = pathlib.Path(__file__).resolve().parent / 'runtime'
if '--serve' in sys.argv and not os.environ.get('FILEHUB_HTTP_CHILD'):
    target = runtime / 'wordpress/wp-content/plugins/gnn-filehub'
    if not target.is_symlink():
        shutil.copytree(pathlib.Path(__file__).resolve().parents[1]/'gnn-filehub',target,dirs_exist_ok=True)
    php = shutil.which('php')
    command = [php]
    if os.name == 'nt':
        command += ['-d', 'extension_dir=' + (pathlib.Path(php).parent / 'ext').as_posix()]
        for extension in ['fileinfo','pdo_sqlite','sqlite3','mysqli','zip','mbstring']:
            command += ['-d', 'extension=php_' + extension + '.dll']
    log = (runtime / 'http-server.log').open('wb')
    server = subprocess.Popen(command + ['-S','127.0.0.1:9461','-t',str(runtime/'wordpress')], stdout=log, stderr=log)
    try:
        for attempt in range(100):
            if server.poll() is not None:
                raise RuntimeError('PHP server exited; inspect http-server.log')
            try:
                with socket.create_connection(('127.0.0.1',9461), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.1)
        result = subprocess.run([sys.executable,__file__], env=dict(os.environ, FILEHUB_HTTP_CHILD='1'))
        raise SystemExit(result.returncode)
    finally:
        server.terminate()
        server.wait(timeout=10)
        log.close()

fixture = json.loads((runtime / 'http-fixtures.json').read_text(encoding='utf-8'))
root = 'http://127.0.0.1:9461'
cookies = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
client.open(root + '/wp-login.php', timeout=20).read()
login = urllib.parse.urlencode({'log': fixture['username'], 'pwd': fixture['password'], 'wp-submit': 'Log In', 'testcookie': '1', 'redirect_to': root + '/?page_id=' + str(fixture['page'])}).encode()
html = client.open(urllib.request.Request(root + '/wp-login.php', data=login), timeout=20).read().decode()
match = re.search(r'var filehub_vars\s*=\s*(\{.+?\});', html, re.S)
assert match, 'Frontend did not localize the API contract after login'
config = json.loads(match.group(1))
assert int(config['user_id']) == int(fixture['owner']), 'Login cookie did not resolve to the expected owner'


def endpoint(path):
    parsed = urllib.parse.urlsplit(config['api_url'])
    query = dict(urllib.parse.parse_qsl(parsed.query))
    route, _, suffix = path.partition('?')
    if 'rest_route' in query:
        query['rest_route'] = query['rest_route'].rstrip('/') + '/' + route
        pathname = parsed.path
    else:
        pathname = parsed.path.rstrip('/') + '/' + route
    query.update(urllib.parse.parse_qsl(suffix))
    return urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, pathname, urllib.parse.urlencode(query), ''))


def request(method, path, data=None, token='', content_type='application/json', authenticated=True, nonce=None):
    headers = {'X-WP-Nonce': config['nonce'] if nonce is None else nonce} if authenticated else {}
    if token:
        headers['X-FileHub-Token'] = token
    if data is not None:
        headers['Content-Type'] = content_type
        if isinstance(data, dict):
            data = json.dumps(data).encode()
    req = urllib.request.Request(endpoint(path), data=data, headers=headers, method=method)
    opener = client if authenticated else urllib.request.build_opener()
    try:
        with opener.open(req, timeout=30) as response:
            return response.status, response.read(), dict(response.headers)
    except urllib.error.HTTPError as error:
        return error.code, error.read(), dict(error.headers)


results = []


def check(name, condition):
    if not condition:
        raise AssertionError(name)
    results.append(name)


code, body, _ = request('POST', 'uploads', {'filename': 'real-http.pdf', 'size': 9}, authenticated=False)
check('HTTP anonymous upload is denied', code in (401, 403))
pdf = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n' + b' ' * (2 * 1048576 + 113) + b'%%EOF\n'
code, body, begin_headers = request('POST', 'uploads', {'filename': 'real-http.pdf', 'size': len(pdf)})
assert code == 200 and 'no-store' in begin_headers.get('Cache-Control',''), (code, body)
check('Cookie-authenticated session is created', True)
session = json.loads(body)
upload_id, token = session['upload_id'], session['upload_token']
boundary = 'FileHubQA' + uuid.uuid4().hex
for index in range(session['total_chunks']):
    chunk = pdf[index * session['chunk_size']:(index + 1) * session['chunk_size']]
    multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="real-http.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode() + chunk + f'\r\n--{boundary}--\r\n'.encode())
    code, body, _ = request('POST', f'uploads/{upload_id}/chunks/{index}', multipart, token, 'multipart/form-data; boundary=' + boundary)
    assert code == 200, body
check('Genuine PHP multipart uploads validate all chunks', True)
code, body, _ = request('POST', f'uploads/{upload_id}/complete', token=token)
done = json.loads(body)
check('HTTP completion has a committed attachment ID', code == 200 and done.get('status') == 'done' and done.get('attachment_id', 0) > 0)
file_id = done['attachment_id']
code, body, _ = request('GET', 'download/' + str(file_id), authenticated=False)
check('Anonymous download of a real member file is denied', code in (401, 403))
code, body, headers = request('GET', 'download/' + str(file_id))
check('Owner receives byte-identical content with private cache policy', code == 200 and body == pdf and 'no-store' in headers.get('Cache-Control', ''))
code, body, _ = request('GET', 'uploads/' + upload_id, token='0' * 64)
check('A wrong upload token cannot read session state', code == 404)
code, body, _ = request('GET', 'files?search=real-http.pdf')
items = json.loads(body)
check('Server search finds the committed file', code == 200 and any(item['id'] == file_id for item in items))
code, body, _ = request('DELETE', 'files/' + str(file_id))
check('HTTP delete removes physical and indexed file', code == 200 and json.loads(body).get('success'))
code, body, _ = request('GET', 'download/' + str(file_id))
check('Deleted file no longer downloads', code == 404)

# Exercise the optional scanner wire protocol with a local stand-in, not a real malware engine.
php = shutil.which('php')
php_command = [php]
if os.name == 'nt':
    php_command += ['-d','extension_dir=' + (pathlib.Path(php).parent/'ext').as_posix()]
    for extension in ['fileinfo','pdo_sqlite','sqlite3','mysqli','zip','mbstring']:
        php_command += ['-d','extension=php_' + extension + '.dll']
worker = str(pathlib.Path(__file__).resolve().parent/'concurrency-worker.php')
scanner = socket.socket()
scanner.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
scanner.bind(('127.0.0.1',3310)); scanner.listen(1); scanner.settimeout(15)
scanner_errors = []
def fake_scanner():
    try:
        connection, _ = scanner.accept()
        with connection:
            connection.settimeout(10)
            def read_exact(length):
                data = b''
                while len(data) < length:
                    part = connection.recv(length-len(data))
                    if not part: raise RuntimeError('Incomplete scanner frame')
                    data += part
                return data
            assert read_exact(10) == b'zINSTREAM\0'
            while True:
                size = struct.unpack('!I',read_exact(4))[0]
                if not size: break
                read_exact(size)
            connection.sendall(b'stream: Synthetic.Test FOUND\0')
    except Exception as error:
        scanner_errors.append(str(error))
thread = threading.Thread(target=fake_scanner,daemon=True); thread.start()
try:
    subprocess.run(php_command+[worker,'scanner','on'],check=True,capture_output=True)
    sample = b'%PDF-1.4\n%%EOF\n'
    code, body, _ = request('POST','uploads',{'filename':'scanned.pdf','size':len(sample)})
    assert code == 200, body
    scanned = json.loads(body)
    multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="chunk"; filename="scanned.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+sample+f'\r\n--{boundary}--\r\n'.encode())
    code, body, _ = request('POST',f"uploads/{scanned['upload_id']}/chunks/0",multipart,scanned['upload_token'],'multipart/form-data; boundary='+boundary)
    assert code == 200, body
    code, body, _ = request('POST',f"uploads/{scanned['upload_id']}/complete",token=scanned['upload_token'])
    check('ClamAV protocol malware result blocks publication',code == 400 and b'attachment_id' not in body)
    code, body, _ = request('DELETE',f"uploads/{scanned['upload_id']}",token=scanned['upload_token'])
    check('Rejected scanner file can be cancelled and cleaned',code == 200)
    thread.join(timeout=2)
    assert not scanner_errors, scanner_errors
finally:
    subprocess.run(php_command+[worker,'scanner','off'],check=True,capture_output=True)
    scanner.close()

old_nonce = config['nonce']
new_password = fixture['password'] + '-HTTP'
code, body, _ = request('POST','update-profile',{'current_password':fixture['password'],'new_password':new_password,'confirm_password':new_password})
check('HTTP password change signals required session reload',code == 200 and json.loads(body).get('reload') is True)
fixture['password'] = new_password
(runtime/'http-fixtures.json').write_text(json.dumps(fixture),encoding='utf-8')
html = client.open(root+'/?page_id='+str(fixture['page']),timeout=20).read().decode()
config = json.loads(re.search(r'var filehub_vars\s*=\s*(\{.+?\});',html,re.S).group(1))
code, body, _ = request('POST','update-profile',{'display_name':'Stale session'},nonce=old_nonce)
check('Previous REST nonce is rejected after password session change',code == 403 and config['nonce'] != old_nonce)
code, body, _ = request('POST','update-profile',{'display_name':'HTTP QA'})
check('Reloaded session can immediately update profile',code == 200 and json.loads(body).get('success'))

report = {'mode': 'real HTTP, WordPress cookies, PHP multipart upload and private stream', 'passed': len(results), 'checks': results}
(runtime / 'http-results.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))

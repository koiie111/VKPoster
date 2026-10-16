"""Offline renderer HTTP contract: only synthetic signed HTML, never app credentials."""
import hashlib
import hmac
import os
import time
import urllib.error
import urllib.request

url = os.environ.get('PDF_TEST_URL', 'http://127.0.0.1:18187')
key = os.environ['PDF_TEST_KEY'].encode()

def send(body, signature=''):
    request = urllib.request.Request(url + '/pdf', body, headers={'X-Report-Signature': signature, 'Content-Type': 'text/html'})
    try:
        with urllib.request.urlopen(request, timeout=40) as response:
            return response.status, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.read()

for attempt in range(30):
    try:
        status, _ = send(b'<p>Unauthorized</p>')
        assert status == 403
        break
    except urllib.error.URLError:
        time.sleep(1)
else:
    raise AssertionError('Renderer did not become ready')
html = '<html lang="ru"><meta charset="utf-8"><h1>Тестовый отчёт</h1><p>0 != нет данных</p><img src="http://example.invalid/never-load"><script>throw Error("must not run")</script></html>'.encode()
signature = hmac.new(key, html, hashlib.sha256).hexdigest()
status, body = send(html, signature)
assert status == 200, status
assert body.startswith(b'%PDF-')
assert b'%%EOF' in body[-100:]
status, _ = send(b'x' * (2 * 1024 * 1024 + 1))
assert status == 413, status
print('PDF contract: unsigned=403, signed=PDF, oversized=413; passed')

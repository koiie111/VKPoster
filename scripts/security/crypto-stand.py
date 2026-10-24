#!/usr/bin/env python3
"""Real, disposable OpenBao/TLS contract; never reads the owner's environment."""
from pathlib import Path
import subprocess
import os
import secrets
import json
import time
import urllib.request
import urllib.error
from crypto_fixture import CryptoFixture

ROOT = Path(__file__).resolve().parents[2]
TEST_IMAGE = os.environ.get('CRYPTO_TEST_IMAGE', 'ezposter-ops:stage19')
network = 'stage19-crypto-contract-' + secrets.token_hex(4)
subprocess.run(['docker','network','create',network],check=True,stdout=subprocess.DEVNULL)
fixture = CryptoFixture(network)
try:
    fixture.start()
    result = subprocess.run([
        'docker', 'run', '--rm', '--network', fixture.network,
        '-v', str(ROOT) + ':/var/www/html', '-v', str(fixture.path) + ':/fixture:ro',
        '-e', 'CRYPTO_FIXTURE_URL=' + fixture.endpoint, TEST_IMAGE,
        'vendor/bin/phpunit', '--do-not-cache-result', '--bootstrap', 'vendor/autoload.php',
        'tests/Integration/Kernel/RealTransitTest.php',
    ], check=False)
    if result.returncode:
        raise RuntimeError('Real isolated crypto regression failed')
    renderer = fixture.name + '-pdf'
    output = fixture.path / 'output'
    output.mkdir(mode=0o777)
    output.chmod(0o777)
    try:
        fixture.run(['docker','run','-d','--name',renderer,'--network',fixture.network,
            '--read-only','--tmpfs','/tmp:size=256m,mode=1777','--shm-size','256m',
            '-p','127.0.0.1::3000','-e','CRYPTO_BACKEND=vault',
            '-e','VAULT_PDF_VERIFY_URL='+fixture.endpoint+'/v1/transit/verify/vkposter-pdf/sha2-256',
            '-e','VAULT_PDF_TOKEN_FILE=/run/secrets/transit-pdf.token',
            '-e','NODE_EXTRA_CA_CERTS=/run/secrets/transit-ca.pem',
            '-v',str(fixture.path/'renderer.token')+':/run/secrets/transit-pdf.token:ro',
            '-v',str(fixture.path/'ca.pem')+':/run/secrets/transit-ca.pem:ro',
            '-v',str(ROOT/'tools/analytics-pdf/server.mjs')+':/opt/analytics-pdf/server.mjs:ro',
            'ezposter-pdf:stage19'])
        port=json.loads(fixture.run(['docker','inspect',renderer,'--format','{{json .NetworkSettings.Ports}}']).stdout)['3000/tcp'][0]['HostPort']
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for _ in range(100):
            try:
                opener.open(urllib.request.Request('http://127.0.0.1:'+port+'/pdf',data=b'synthetic',method='POST'),timeout=2)
            except urllib.error.HTTPError as error:
                if error.code==403: break
                raise RuntimeError('Renderer did not fail closed') from None
            except OSError:
                time.sleep(0.1)
        else: raise RuntimeError('Isolated renderer startup failed')
        fixture.run(['docker','run','--rm','--network',fixture.network,
            '-v',str(ROOT)+':/var/www/html','-v',str(fixture.path/'app.token')+':/run/secrets/transit-app.token:ro',
            '-v',str(fixture.path/'ca.pem')+':/run/secrets/transit-ca.pem:ro','-v',str(output)+':/output',
            '-e','APP_ENV=testing','-e','REDIS_HOST=fixture.invalid','-e','DB_HOST=fixture.invalid','-e','DB_DATABASE=synthetic','-e','DB_USERNAME=synthetic','-e','DB_PASSWORD=synthetic','-e','CRYPTO_BACKEND=vault','-e','VAULT_TRANSIT_URL='+fixture.endpoint,
            '-e','VAULT_TOKEN_FILE=/run/secrets/transit-app.token','-e','VAULT_CA_FILE=/run/secrets/transit-ca.pem',
            '-e','ANALYTICS_PDF_URL=http://'+renderer+':3000',TEST_IMAGE,'php','-r',
            "require 'vendor/autoload.php';$a=App\\Kernel\\Application::create(getcwd());$p=$a->container()->get(App\\Domain\\Analytics\\AnalyticsPdf::class)->render('<h1>Synthetic isolated crypto report</h1>');if(!str_starts_with($p,'%PDF-'))exit(1);file_put_contents('/output/report.pdf',$p);"])
        if not (output/'report.pdf').read_bytes().startswith(b'%PDF-'):
            raise RuntimeError('Actual Chromium PDF missing')
        print('PASS actual Chromium PDF: Transit sign -> verify-only capability -> rendered PDF, no shared signing key.')
    finally:
        subprocess.run(['docker','rm','-f',renderer],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    print('PASS: real TLS OpenBao purpose separation, rotation/rewrap, old-version verification, pepper and non-export.')
finally:
    fixture.close()
    subprocess.run(['docker','network','rm',network],check=True,stdout=subprocess.DEVNULL)

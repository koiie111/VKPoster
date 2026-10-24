#!/usr/bin/env python3
"""10k existing publishing pipeline on the labeled disposable MySQL84 fixture only."""
from pathlib import Path
import base64
import json
import secrets
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
MYSQL = 'stage19-mysql84-compat'
IMAGE = 'ezposter-ops:oracle84'

def run(args, **kwargs):
    result = subprocess.run(args, capture_output=True, **kwargs)
    if result.returncode:
        Path('/tmp/stage19-mysql84-load-error.log').write_bytes(result.stderr + result.stdout)
        raise RuntimeError('Isolated load command failed; fixture-only evidence saved outside Git.')
    return result.stdout

label = run(['docker', 'inspect', MYSQL, '--format', '{{index .Config.Labels "stage19.fixture"}}']).decode().strip()
if label != 'mysql84-patched':
    raise RuntimeError('Only the labeled disposable MySQL84 fixture is allowed')
# Synthetic root credential for this disposable fixture; never connected to the owner DB.
run(['docker', 'exec', '-i', MYSQL, 'mysql', '-uroot', '-proot'], input=b"CREATE DATABASE IF NOT EXISTS app_load_mysql84; GRANT ALL ON app_load_mysql84.* TO 'app'@'%';")
redis_name = 'stage19-mysql84-load-redis'
exists = subprocess.run(['docker','inspect',redis_name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode == 0
if not exists:
    run(['docker','run','-d','--name',redis_name,'--label','stage19.fixture=mysql84-load','--network','vkposter_default','--memory','128m','--tmpfs','/data','redis:7-alpine'])
elif run(['docker','inspect',redis_name,'--format','{{index .Config.Labels "stage19.fixture"}}']).decode().strip() != 'mysql84-load':
    raise RuntimeError('Refusing a Redis fixture not owned by this check')
with tempfile.TemporaryDirectory(prefix='stage19-mysql84-load-') as temporary:
    env = Path(temporary) / 'app.env'
    values = {'APP_ENV':'local', 'APP_KEY':base64.b64encode(secrets.token_bytes(32)).decode(), 'APP_URL':'http://fixture.invalid',
              'DB_HOST':MYSQL, 'DB_DATABASE':'app_load_mysql84', 'DB_USERNAME':'app', 'DB_PASSWORD':'app',
              'REDIS_HOST':'stage19-mysql84-load-redis', 'REDIS_DB':'15', 'MAIL_DSN':'null://null',
              'PLATFORMS_ENABLED':'fake', 'OPS_MONITOR_ENABLED':'false', 'SOURCES_ENABLED':'false'}
    env.write_text(''.join(k+'='+v+'\n' for k,v in values.items())); env.chmod(0o600)
    base=['docker','run','--rm','--network','vkposter_default','--env-file',str(env),'--read-only','--tmpfs','/tmp:size=128m,mode=1777','--tmpfs','/var/www/html/storage:size=64m,mode=1777','-v',str(ROOT/'tools/load')+':/var/www/html/tools/load:ro']
    def call(args): return run(base+[IMAGE,'php']+args).decode()
    call(['bin/console','migrate'])
    print(call(['bin/console','bench:publish','--fake','--count=10000','--spread=1','--prepare-only']), flush=True)
    Path('/tmp/stage19-mysql84-explain.json').write_text(call(['tools/load/burst.php','explain']))
    print(call(['tools/load/burst.php','arm']), flush=True)
    workers=[]
    try:
        for _ in range(4):
            name='stage19-mysql84-worker-'+secrets.token_hex(4); workers.append(name)
            run(base[:2]+['-d','--name',name,'--cpus','1','--memory','384m']+base[2:]+[IMAGE,'php','bin/console','queue:work','--queue=publish','--sleep=1'])
        deadline=time.monotonic()+180
        while time.monotonic()<deadline:
            call(['tools/load/burst.php','tick'])
            result=json.loads(call(['tools/load/burst.php','status']))
            if result['states'].get('sent')==10000: break
            time.sleep(3)
        else: raise RuntimeError('10k fixture did not complete')
        Path('/tmp/stage19-mysql84-10k.json').write_text(json.dumps(result))
        print(json.dumps(result),flush=True)
        if result['count']!=10000 or result['p95']>=60: raise RuntimeError('10k p95 gate failed')
        print('PASS MySQL84: 10000 sent through existing Scheduler/Queue/SKIP LOCKED/Publisher, four workers, p95<60s')
    finally:
        for name in workers:
            subprocess.run(['docker','stop','-t','10',name],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

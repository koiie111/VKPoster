#!/usr/bin/env python3
"""Cold-copy engine upgrade test on exclusively owned volumes; never mounts an owner database."""
from pathlib import Path
import json
import os
import secrets
import subprocess
import tempfile
import time

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('DB_COMPAT_TEST_IMAGE','ezposter-ops:stage19')
BASELINE='8f01fbe31f7b52c8be0bafd9ca8f4e9f668a33b5'
name='stage19-upgrade-'+secrets.token_hex(4)
volumes=[name+'-80',name+'-84']
containers=[]
network=name+'-network'
run_network=False

def run(args, **kwargs):
    result=subprocess.run(args,capture_output=True,**kwargs)
    if result.returncode:
        Path('/tmp/stage19-engine-upgrade-error.log').write_bytes(result.stderr+result.stdout)
        raise RuntimeError('Disposable engine upgrade command failed; fixture-only evidence saved outside Git.')
    return result.stdout

def start(container,image,volume):
    run(['docker','run','-d','--name',container,'--label','stage19.fixture=engine-upgrade','--network',network,'--memory','1g','--cpus','2',
         '-e','MYSQL_ROOT_PASSWORD=root','-e','MYSQL_DATABASE=app_test','-e','MYSQL_USER=app','-e','MYSQL_PASSWORD=app','-v',volume+':/var/lib/mysql',
         '-v',str(ROOT/'docker/mysql/my.cnf')+':/etc/mysql/conf.d/app.cnf:ro',image])
    containers.append(container)
    for _ in range(120):
        p=subprocess.run(['docker','exec',container,'mysql','-uroot','-proot','-N','-e','SELECT VERSION() WHERE @@skip_networking = 0;'],capture_output=True)
        # The entrypoint's socket-only initialization server is not ready for the following TCP migration.
        if p.returncode==0 and p.stdout.strip():return p.stdout.decode().strip()
        time.sleep(0.5)
    raise RuntimeError('Disposable engine startup/upgrade failed')

try:
    run(['docker','network','create',network]);run_network=True
    for volume in volumes:run(['docker','volume','create',volume])
    before=start(name+'-80','mysql:8.0',volumes[0])
    with tempfile.TemporaryDirectory(prefix='stage19-schema-baseline-') as temporary:
        baseline=Path(temporary)
        # Public Git archive only: Linux runner UID1001 must permit ops UID1000 traversal.
        # Never apply this to token/key/environment fixture directories.
        baseline.chmod(0o755)
        archive=run(['git','archive',BASELINE,'database/migrations','config'],cwd=ROOT)
        run(['tar','-xf','-','-C',str(baseline)],input=archive)
        run(['docker','run','--rm','--network','none','-v',str(baseline)+':/baseline:ro',IMAGE,'php','-r',
             'if (!is_readable("/baseline/database/migrations") || !is_readable("/baseline/config/billing.php")) { throw new RuntimeException("Public baseline archive is not readable by the non-root ops user"); }'])
        php="require 'vendor/autoload.php';$db=new App\\Kernel\\Database\\Connection($argv[1],3306,'app_test','app','app');(new App\\Kernel\\Database\\Migrator($db,'/baseline/database/migrations'))->migrate();$db->execute('INSERT INTO users(email,name,created_at,updated_at)VALUES(?,?,NOW(6),NOW(6))',['upgrade@example.test','Кириллица 😀']);"
        run(['docker','run','--rm','--network',network,'-v',str(baseline)+':/baseline:ro',IMAGE,'php','-r',php,name+'-80'])
        run(['docker','stop',name+'-80'])
        run(['docker','run','--rm','--network','none','--user','0:0','--entrypoint','sh','-v',volumes[0]+':/source:ro','-v',volumes[1]+':/target','ezposter-redis:stage19','-ec','cp -a /source/. /target/'])
        after=start(name+'-84','ezposter-mysql:stage19',volumes[1])
        php="require 'vendor/autoload.php';$db=new App\\Kernel\\Database\\Connection($argv[1],3306,'app_test','app','app');(new App\\Kernel\\Database\\Migrator($db,'database/migrations'))->migrate();$row=$db->select('SELECT name FROM users WHERE email=?',['upgrade@example.test'])[0]??[];if(($row['name']??'')!=='Кириллица 😀')throw new RuntimeException('Upgrade lost UTF8 data');$s=$db->select('SELECT @@session.time_zone AS tz, @@character_set_connection AS cs, @@collation_connection AS co')[0];if($s['tz']!=='+00:00'||$s['cs']!=='utf8mb4')throw new RuntimeException('Session semantics changed');echo json_encode($s);"
        semantics=run(['docker','run','--rm','--network',network,IMAGE,'php','-r',php,name+'-84']).decode()
        # The rollback route is the untouched cold source volume, not an unsupported dictionary downgrade.
        run(['docker','start',name+'-80'])
        for _ in range(120):
            check=subprocess.run(['docker','exec',name+'-80','mysql','-uroot','-proot','-N','-e','SELECT 1 WHERE @@skip_networking = 0'],capture_output=True)
            if check.returncode==0 and check.stdout.strip()==b'1':break
            time.sleep(0.5)
        else:raise RuntimeError('Preserved source did not restart')
        retained=run(['docker','exec',name+'-80','mysql','-uroot','-proot','-N','-e',"SELECT COUNT(*) FROM app_test.users WHERE email='upgrade@example.test';"]).decode().strip()
        if retained!='1':raise RuntimeError('Preserved rollback source lost data')
        print('PASS cold-copy MySQL engine upgrade:',before,'->',after,'; migration files '+str(len(list((baseline/'database/migrations').glob('*.php'))))+'->'+str(len(list((ROOT/'database/migrations').glob('*.php'))))+'; existing UTF8 preserved;',semantics,'; original8.0 volume retained intact during validation.')
finally:
    for container in containers:subprocess.run(['docker','rm','-f',container],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    for volume in volumes:subprocess.run(['docker','volume','rm',volume],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    if run_network:subprocess.run(['docker','network','rm',network],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)

#!/usr/bin/env python3
"""Real encrypted backup/restore/PITR, guarded to the labeled disposable MySQL84 fixture."""
from pathlib import Path
import json
import os
import re
import secrets
import subprocess
import tempfile
from crypto_fixture import CryptoFixture

ROOT=Path(__file__).resolve().parents[2]
IMAGE=os.environ.get('PITR_TEST_IMAGE','ezposter-ops:stage19')
MYSQL=os.environ.get('PITR_MYSQL_FIXTURE','stage19-mysql84-compat')
if re.fullmatch(r'stage19-[a-z0-9-]+',MYSQL) is None:raise RuntimeError('Invalid isolated fixture name')
sensitive=[]

def run(args,data=None):
    p=subprocess.run(args,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if p.returncode:
        diagnostic=(p.stderr+p.stdout).decode(errors='replace')[-32768:]
        for value in sensitive:diagnostic=diagnostic.replace(value,'[redacted]')
        Path('/tmp/stage19-pitr84-error.log').write_text(diagnostic)
        raise RuntimeError('Isolated PITR command failed; redacted evidence saved outside Git.')
    return p.stdout

if run(['docker','inspect',MYSQL,'--format','{{index .Config.Labels "stage19.fixture"}}']).decode().strip()!='mysql84-patched':
    raise RuntimeError('Refusing a database not owned by the disposable MySQL84 fixture')
mounts=json.loads(run(['docker','inspect',MYSQL,'--format','{{json .Mounts}}']))
data_volume=next(m['Name'] for m in mounts if m['Destination']=='/var/lib/mysql')
socket=next(m['Name'] for m in mounts if m['Destination']=='/var/run/mysqld')
suffix=secrets.token_hex(6)
target='app_restore_pitr_'+suffix
probe='stage19_pitr_'+suffix
backup_user='pitr_backup_'+suffix
restore_user='pitr_restore_'+suffix
volume='stage19-pitr84-'+suffix
admin='/tmp/stage19-pitr-admin-'+suffix+'.cnf'
backup_pass=secrets.token_hex(24);restore_pass=secrets.token_hex(24)
sensitive.extend([backup_pass,restore_pass])
crypto=CryptoFixture(os.environ.get('PITR_TEST_NETWORK','vkposter_default'))
created=False
try:
    crypto.start()
    run(['docker','exec','-i',MYSQL,'sh','-c','umask 077; cat > '+admin],b'[client]\nuser=root\npassword=root\n')
    def sql(text):return run(['docker','exec','-i',MYSQL,'mysql','--defaults-file='+admin,'-N'],text.encode())
    if sql("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='"+target+"';").strip()!=b'0':
        raise RuntimeError('Restore target already exists; no changes allowed')
    created=True
    grant_target=target if sql('SELECT @@partial_revokes;').strip()==b'1' else target.replace('_','\\_')
    sql("CREATE DATABASE "+target+"; CREATE USER '"+backup_user+"'@'%' IDENTIFIED BY '"+backup_pass+"'; GRANT SELECT,SHOW VIEW,TRIGGER,EVENT ON app_test.* TO '"+backup_user+"'@'%'; GRANT RELOAD,REPLICATION CLIENT,SHOW_ROUTINE ON *.* TO '"+backup_user+"'@'%'; CREATE USER '"+restore_user+"'@'%' IDENTIFIED BY '"+restore_pass+"'; GRANT ALL ON `"+grant_target+"`.* TO '"+restore_user+"'@'%'; CREATE TABLE app_test."+probe+"(id INT PRIMARY KEY,value VARCHAR(32)); INSERT INTO app_test."+probe+" VALUES(1,'before');")
    run(['docker','volume','create',volume])
    run(['docker','run','--rm','--network','none','--user','0','--entrypoint','php','-v',volume+':/fixture',IMAGE,'-r',"mkdir('/fixture/keys',0700);file_put_contents('/fixture/keys/backup',base64_encode(random_bytes(32)));chmod('/fixture/keys/backup',0600);chown('/fixture/keys/backup',1000);chown('/fixture/keys',1000);chown('/fixture',1000);chmod('/fixture',0700);"])
    with tempfile.TemporaryDirectory(prefix='stage19-pitr84-') as temporary:
        env=Path(temporary)/'fixture.env'
        values={'APP_ENV':'production','APP_URL':'https://fixture.invalid','TRUSTED_PROXIES':'127.0.0.1','EGRESS_IDENTITY_SALT':secrets.token_hex(24),
                'CRYPTO_BACKEND':'vault','VAULT_TRANSIT_URL':crypto.endpoint,'VAULT_TOKEN_FILE':'/run/secrets/transit-app.token','VAULT_CA_FILE':'/run/secrets/transit-ca.pem',
                'REDIS_HOST':os.environ.get('PITR_REDIS_HOST','redis-production-test'),'REDIS_PASSWORD':'r'*48,'DB_HOST':MYSQL,'DB_DATABASE':'app_test','DB_USERNAME':'app','DB_PASSWORD':'app',
                'BACKUP_DB_USERNAME':backup_user,'BACKUP_DB_PASSWORD':backup_pass,'BACKUP_DB_SOCKET':'/socket/mysqld.sock','BACKUP_KEY_FILE':'/fixture/keys/backup',
                'MALWARE_SCANNER':'clamd','BACKUP_BINLOG_ENABLED':'true','BACKUP_BINLOG_DIRECTORY':'/binlogs',
                'RESTORE_DB_SOCKET':'/socket/mysqld.sock','RESTORE_DB_USERNAME':restore_user,'RESTORE_DB_PASSWORD':restore_pass,'MAIL_DSN':'null://null'}
        env.write_text(''.join(k+'='+v+'\n' for k,v in values.items()));env.chmod(0o600)
        cmd=['docker','run','--rm','--network',crypto.network,'--env-file',str(env),'--group-add','999','--read-only','--tmpfs','/tmp:size=256m,mode=1777',
             '-v',volume+':/fixture','-v',socket+':/socket:ro','-v',data_volume+':/binlogs:ro',
             '-v',str(crypto.path/'app.token')+':/run/secrets/transit-app.token:ro','-v',str(crypto.path/'ca.pem')+':/run/secrets/transit-ca.pem:ro',IMAGE]
        archive=run(cmd+['php','bin/console','ops:backup','backup','/fixture','--local-only']).decode().strip().splitlines()[-1]
        if not re.fullmatch(r'backup-[a-zA-Z0-9.-]+\.ezb',archive):raise RuntimeError('Unexpected snapshot name')
        php=r'''require 'vendor/autoload.php';$a=new App\Kernel\Operations\BackupArchive();$a->transform('/fixture/'.$argv[1],'/tmp/snapshot.tar',$a->loadKey('/fixture/keys/backup'),true);$t=new PharData('/tmp/snapshot.tar');$s=$t['database.sql']->openFile()->fread(65536);if(!preg_match("/SOURCE_LOG_FILE='(binlog\\.\\d{6})', SOURCE_LOG_POS=(\\d+)/",$s,$m))throw new RuntimeException('Coordinates absent');echo json_encode(['file'=>$m[1],'position'=>(int)$m[2]]);'''
        coords=json.loads(run(cmd+['php','-r',php,archive]))
        sql("UPDATE app_test."+probe+" SET value='after'; FLUSH BINARY LOGS;")
        run(cmd+['php','bin/console','ops:backup','binlogs','/fixture','--local-only'])
        glob=run(cmd+['sh','-c','printf "%s\\n" /fixture/binlog-'+coords['file']+'-*.ezb']).decode().strip().splitlines()
        if len(glob)!=1:raise RuntimeError('Ambiguous log archive')
        run(cmd+['php','bin/console','ops:backup','decrypt-binlog',glob[0],'/fixture/replay.binlog'])
        run(cmd+['php','bin/console','ops:backup','restore','/fixture/'+archive,target,'--confirm-isolated'])
        if sql('SELECT value FROM '+target+'.'+probe+';').strip()!=b'before':raise RuntimeError('Snapshot mismatch')
        producer=subprocess.Popen(['docker','run','--rm','--network','none','--entrypoint','/opt/mysql/mysqlbinlog','-v',volume+':/fixture:ro',IMAGE,
            '--start-position='+str(coords['position']),'--rewrite-db=app_test->'+target,'--database='+target,'/fixture/replay.binlog'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        consumer=subprocess.run(['docker','exec','-i',MYSQL,'mysql','--defaults-file='+admin],stdin=producer.stdout,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        producer.stdout.close();producer.stderr.read();code=producer.wait()
        if code or consumer.returncode:raise RuntimeError('Oracle binlog replay failed')
        if sql('SELECT value FROM '+target+'.'+probe+';').strip()!=b'after' or sql('SELECT value FROM app_test.'+probe+';').strip()!=b'after':
            raise RuntimeError('PITR/source-isolation mismatch')
        print('PASS: production env without APP_KEY/local pepper; Oracle8.4 encrypted coordinate snapshot, integrity, restricted isolated restore, authenticated binlog decrypt/replay; source unchanged by replay.')
finally:
    try:
        if created:
            sql("DROP TABLE IF EXISTS app_test."+probe+"; DROP DATABASE IF EXISTS "+target+"; DROP USER IF EXISTS '"+backup_user+"'@'%'; DROP USER IF EXISTS '"+restore_user+"'@'%';")
    finally:
        subprocess.run(['docker','exec',MYSQL,'rm','-f',admin],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        subprocess.run(['docker','volume','rm',volume],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        crypto.close()

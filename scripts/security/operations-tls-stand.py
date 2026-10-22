#!/usr/bin/env python3
"""Disposable real MySQL/PDO/Oracle TLS identity contract; no owner database or credentials."""
from pathlib import Path
import secrets
import subprocess
import time

ROOT = Path(__file__).resolve().parents[2]
name = "stage19-ops-tls-" + secrets.token_hex(6)
volume = name + "-certs"
image = "ezposter-crypto-test:stage19"


def run(*args, **kwargs):
    return subprocess.run(list(args), check=True, **kwargs)


try:
    run("docker", "network", "create", name, stdout=subprocess.DEVNULL)
    run("docker", "volume", "create", volume, stdout=subprocess.DEVNULL)
    run("docker", "run", "--rm", "--network", "none", "--user", "0:0", "-v", volume + ":/certs", image, "sh", "-ec",
        "umask 077; openssl req -x509 -newkey rsa:2048 -nodes -days 1 -keyout /certs/server.key -out /certs/ca.pem -subj /CN=mysql-valid -addext subjectAltName=DNS:mysql-valid >/dev/null 2>&1; chmod 644 /certs/ca.pem; chown 999:999 /certs/server.key")
    run("docker", "run", "-d", "--name", name, "--label", "stage19.fixture=operations-tls", "--network", name,
        "--network-alias", "mysql-valid", "--network-alias", "mysql-wrong", "--tmpfs", "/var/lib/mysql",
        "-e", "MYSQL_ROOT_PASSWORD=synthetic-root", "-e", "MYSQL_DATABASE=fixture", "-e", "MYSQL_USER=fixture", "-e", "MYSQL_PASSWORD=synthetic-only",
        "-v", volume + ":/certs:ro", "ezposter-mysql:stage19", "--ssl-ca=/certs/ca.pem", "--ssl-cert=/certs/ca.pem", "--ssl-key=/certs/server.key", "--require-secure-transport=ON", stdout=subprocess.DEVNULL)
    for _ in range(90):
        # The entrypoint's temporary Unix-socket server can answer SELECT before final TCP startup.
        # Wait for the same verified TCP transport and restricted fixture account used by the contract.
        probe = subprocess.run(["docker", "exec", name, "mysql", "--host=mysql-valid", "--protocol=TCP",
            "--user=fixture", "--password=synthetic-only", "--database=fixture", "--ssl-mode=VERIFY_IDENTITY",
            "--ssl-ca=/certs/ca.pem", "-N", "-e", "SELECT 1"], capture_output=True)
        if probe.returncode == 0:
            break
        time.sleep(1)
    else:
        raise RuntimeError("Isolated TLS MySQL did not become ready")
    common = ["docker", "run", "--rm", "--network", name, "-v", str(ROOT) + ":/var/www/html:ro", "-v", volume + ":/certs:ro", "-w", "/var/www/html", image]
    for host, success in (("mysql-valid", True), ("mysql-wrong", False)):
        php = """require 'vendor/autoload.php';
$c=App\\Tests\\Support\\TestEnv::config(['APP_ENV'=>'production','OPERATIONS_DB_TLS_CA_FILE'=>'/certs/ca.pem']);
try {$p=new PDO('mysql:host=HOST;dbname=fixture','fixture','synthetic-only',App\\Kernel\\Operations\\OperationsDbPolicy::pdoOptions($c,''));
App\\Kernel\\Operations\\OperationsDbPolicy::assertTransport($p,$c,''); exit(0);}catch(Throwable $e){fwrite(STDERR,'tls_fixture_failure:'.$e::class.':'.(int)$e->getCode().PHP_EOL);exit(7);}
""".replace("HOST", host)
        result = subprocess.run(common + ["php", "-r", php], capture_output=True)
        if (result.returncode == 0) != success:
            raise RuntimeError("PDO TLS identity contract failed for " + host + "; " + result.stderr.decode(errors="replace").strip()[:160])
        cli = subprocess.run(common + ["/opt/mysql/mysql", "--host=" + host, "--user=fixture", "--password=synthetic-only", "--ssl-mode=VERIFY_IDENTITY", "--ssl-ca=/certs/ca.pem", "--batch", "-e", "SELECT 1"], capture_output=True)
        if (cli.returncode == 0) != success:
            raise RuntimeError("Oracle TLS identity contract failed for " + host)
    print("PASS real verified TLS: PDO + Oracle valid hostname accepted; mismatch rejected by both.")
finally:
    subprocess.run(["docker", "rm", "-f", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["docker", "volume", "rm", volume], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    subprocess.run(["docker", "network", "rm", name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

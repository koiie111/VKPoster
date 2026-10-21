#!/usr/bin/env python3
"""Build-independent isolated production runtime/DAST verification; never reads the local .env."""

import json
import os
from pathlib import Path
import secrets
import re
import subprocess
import tempfile
from crypto_fixture import CryptoFixture, runtime_environment

ROOT = Path(__file__).resolve().parents[2]


def run(args, **kwargs):
    if args[:2] == ["docker", "compose"] and "--env-file" in args:
        path = Path(args[args.index("--env-file") + 1])
        fixture = dict(line.split("=", 1) for line in path.read_text().splitlines() if "=" in line)
        kwargs["env"] = runtime_environment(fixture)
    return subprocess.run(args, check=True, **kwargs)


def verify_image_rollback(command, folder, project, app_image):
    """Actual failed image/recovery on this disposable project; no SSH/production DDL."""
    image_id = subprocess.check_output(
        ["docker", "image", "inspect", app_image, "--format", "{{.Id}}"], text=True
    ).strip()
    if not image_id.startswith("sha256:") or len(image_id) != 71:
        raise RuntimeError("Unexpected immutable local image ID")
    if re.fullmatch(r"[A-Za-z0-9_./:@-]+", app_image) is None:
        raise RuntimeError("Invalid fixture image reference")
    bad_tag = project + "-unready:fixture"
    build = folder / "unready"
    build.mkdir()
    (build / "Dockerfile").write_text(
        "FROM " + app_image + '\nENTRYPOINT ["php", "-r", "exit(78);"]\n'
    )
    broken = folder / "unready.json"
    broken.write_text(
        json.dumps({"services": {"app": {"image": bad_tag, "restart": "no"}}})
    )
    try:
        run(
            ["docker", "build", "--pull=false", "-t", bad_tag, str(build)],
            stdout=subprocess.DEVNULL,
        )
        run(command + ["-f", str(broken), "up", "-d", "--no-build", "--no-deps", "app"])
        container = subprocess.check_output(
            command + ["ps", "-a", "-q", "app"], text=True
        ).strip()
        result = subprocess.check_output(
            ["docker", "wait", container], text=True
        ).strip()
        if result != "78":
            raise RuntimeError("Unready fixture did not fail as expected")
        probe = command + [
            "exec",
            "-T",
            "nginx",
            "wget",
            "-q",
            "-O",
            "/dev/null",
            "http://127.0.0.1/readyz",
        ]
        if (
            subprocess.run(
                probe, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            ).returncode
            == 0
        ):
            raise RuntimeError("Readiness incorrectly accepted an exited release")
        run(
            command
            + [
                "up",
                "-d",
                "--no-build",
                "--no-deps",
                "--wait",
                "--wait-timeout",
                "90",
                "app",
            ]
        )
        run(probe, stdout=subprocess.DEVNULL)
        print(
            "PASS: failed image is not ready; original image restored with existing DB/volumes"
        )
    finally:
        subprocess.run(
            ["docker", "image", "rm", bad_tag],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )


def verify(crypto):
    project = "stage19-security-" + secrets.token_hex(4)
    reports = Path(
        os.environ.get("ZAP_REPORT_DIRECTORY", "/tmp/vkposter-zap-production")
    ).resolve()
    reports.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="stage19-security-") as temporary:
        folder = Path(temporary)
        envfile = folder / "app.env"
        scanner = folder / "scanner"
        scanner.mkdir()
        (scanner / "fixture.ndb").write_text(
            "Stage19.Test.Signature:0:*:53796e7468657469632d737461676531392d6d616c77617265\n"
        )
        (scanner / "clamd.conf").write_text(
            "DatabaseDirectory /fixture\nTCPSocket 3310\nTCPAddr 0.0.0.0\nForeground yes\nUser clamav\nPidFile /tmp/clamd.pid\nLocalSocket /tmp/clamd.sock\nStreamMaxLength 50M\nMaxThreads 2\n"
        )
        values = {
            "APP_ENV": "production",
            "APP_DEBUG": "0",
            "APP_URL": "https://stage19-security-web.test",
            "CRYPTO_BACKEND": "vault",
            "EGRESS_IDENTITY_SALT": secrets.token_hex(24),
            "VAULT_TRANSIT_URL": crypto.endpoint,
            "VAULT_TRANSIT_KEY": "vkposter",
            "VAULT_APP_TOKEN_HOST_PATH": str(crypto.path / "app.token"),
            "VAULT_PDF_TOKEN_HOST_PATH": str(crypto.path / "renderer.token"),
            "VAULT_CA_HOST_PATH": str(crypto.path / "ca.pem"),
            "TRUSTED_PROXIES": "172.16.0.0/12",
            "REDIS_PASSWORD": secrets.token_hex(24),
            "DB_DATABASE": "app_security_stage19",
            "DB_USERNAME": "app",
            "DB_PASSWORD": secrets.token_hex(24),
            "DB_ROOT_PASSWORD": secrets.token_hex(24),
            "MAIL_DSN": "null://null",
            "HTTP_PORT": "0",
            "DEV_LOGIN": "0",
            "DEV_OAUTH_FAKE": "0",
            "SOURCES_ENABLED": "false",
            "ANALYTICS_COLLECTION_ENABLED": "false",
            "OPS_MONITOR_ENABLED": "false",
            "SENTRY_DSN": "",
            "PLATFORMS_ENABLED": "telegram,vk,max",
            "APP_IMAGE": os.environ.get("SECURITY_APP_IMAGE", "ezposter-app:stage19"),
            "WEB_IMAGE": os.environ.get("SECURITY_WEB_IMAGE", "ezposter-web:stage19"),
            "APP_ENV_FILE": str(envfile),
            "REDIS_IMAGE": "ezposter-redis:stage19",
            "MYSQL_IMAGE": "ezposter-mysql:stage19",
        }
        envfile.write_text("".join(k + "=" + v + "\n" for k, v in values.items()))
        envfile.chmod(0o600)
        run(
            [
                "openssl",
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-days",
                "2",
                "-subj",
                "/CN=stage19-security-web.test",
                "-addext",
                "subjectAltName=DNS:stage19-security-web.test",
                "-keyout",
                str(folder / "key.pem"),
                "-out",
                str(folder / "cert.pem"),
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        (folder / "key.pem").chmod(0o600)
        # Caddy runs UID0 without DAC_OVERRIDE: retain0600 and set ownership explicitly.
        run(["docker", "run", "--rm", "--network", "none", "--user", "0:0",
             "-v", str(folder) + ":/fixture", "--entrypoint", "sh", "ezposter-caddy:stage19",
             "-c", "chown 0:0 /fixture/key.pem"])
        caddy = (
            (ROOT / "docker/caddy/Caddyfile")
            .read_text()
            .replace(
                "example.com {",
                "https://stage19-security-web.test {\n    tls /fixture/cert.pem /fixture/key.pem",
            )
            .replace("127.0.0.1:8080", "nginx:80")
        )
        (folder / "Caddyfile").write_text(caddy)
        # JSON is a YAML subset; only test paths and public image refs enter this override.
        override = folder / "override.json"
        override.write_text(
            json.dumps(
                {
                    "networks": {"default": {"external": True, "name": crypto.network}},
                    "services": {
                        "mysql": {"image": "ezposter-mysql:stage19"},
                        "clamav": {
                            "entrypoint": [
                                "clamd",
                                "--foreground=true",
                                "--config-file=/fixture/clamd.conf",
                            ],
                            "user": "clamav",
                            "read_only": True,
                            "tmpfs": ["/tmp:size=16m,mode=1777"],
                            "cap_drop": ["ALL"],
                            "mem_limit": "256m",
                            "volumes": [str(scanner) + ":/fixture:ro"],
                            "healthcheck": {
                                "test": [
                                    "CMD",
                                    "clamdscan",
                                    "--config-file=/fixture/clamd.conf",
                                    "--ping=1:1",
                                ],
                                "interval": "2s",
                                "start_period": "5s",
                                "retries": 20,
                            },
                        },
                        "edge": {
                            "image": os.environ.get("SECURITY_CADDY_IMAGE", "ezposter-caddy:stage19"),
                            "read_only": True,
                            "tmpfs": ["/data", "/config", "/tmp"],
                            "cap_drop": ["ALL"],
                            "cap_add": ["NET_BIND_SERVICE"],
                            "security_opt": ["no-new-privileges:true"],
                            "mem_limit": "128m",
                            "volumes": [
                                str(folder / name) + ":" + target + ":ro"
                                for name, target in (
                                    ("Caddyfile", "/etc/caddy/Caddyfile"),
                                    ("cert.pem", "/fixture/cert.pem"),
                                    ("key.pem", "/fixture/key.pem"),
                                )
                            ],
                            "networks": {
                                "default": {"aliases": ["stage19-security-web.test"]}
                            },
                        },
                    }
                }
            )
        )
        command = [
            "docker",
            "compose",
            "-p",
            project,
            "--env-file",
            str(envfile),
            "-f",
            str(ROOT / "compose.prod.yaml"),
            "-f",
            str(override),
        ]
        try:
            run(
                command
                + [
                    "up",
                    "-d",
                    "--no-build",
                    "--wait",
                    "--wait-timeout",
                    "180",
                    "mysql",
                    "redis",
                ]
            )
            run(
                command
                + [
                    "run",
                    "--rm",
                    "--no-deps",
                    "-T",
                    "app",
                    "php",
                    "bin/console",
                    "migrate",
                ]
            )
            run(
                command
                + [
                    "up",
                    "-d",
                    "--no-build",
                    "--wait",
                    "--wait-timeout",
                    "180",
                    "app",
                    "nginx",
                    "worker",
                    "analytics-worker",
                    "scheduler",
                    "edge",
                ]
            )
            run(
                command
                + [
                    "exec",
                    "-T",
                    "app",
                    "php",
                    "-r",
                    'require "vendor/autoload.php"; $s=new App\\Kernel\\Security\\ClamdScanner("tcp://clamav:3310"); $s->scanBytes("synthetic-clean-fixture"); try {$s->scanBytes("Synthetic-stage19-malware"); throw new RuntimeException("Test signature accepted");} catch(App\\Kernel\\Exception\\HttpException $e) {if($e->status!==422) throw $e;} try {(new App\\Kernel\\Security\\ClamdScanner("unix:///missing-fixture.sock"))->scanBytes("fixture"); throw new RuntimeException("Scanner unavailable accepted");} catch(App\\Kernel\\Exception\\HttpException $e) {if($e->status!==503) throw $e;} echo "PASS real clamd protocol: clean/infected/unavailable, synthetic signatures only.\\n";',
                ]
            )
            # Verify the same production worker can scale; scheduler stays a singleton.
            run(
                command
                + [
                    "up",
                    "-d",
                    "--no-build",
                    "--no-deps",
                    "--scale",
                    "worker=2",
                    "worker",
                ]
            )
            env = dict(
                os.environ,
                ZAP_NETWORK=crypto.network,
                ZAP_TARGET="https://stage19-security-web.test",
                ZAP_REPORT_DIRECTORY=str(reports),
            )
            run(["sh", str(ROOT / "scripts/security/zap.sh")], env=env)
            verify_image_rollback(command, folder, project, values["APP_IMAGE"])
            print(
                "PASS: isolated production runtime, worker scaling, image rollback and HTTPS ZAP"
            )
        finally:
            # This random project was created above; never touches development/owner volumes.
            run(command + ["down", "-v", "--remove-orphans"])


def main():
    network = "stage19-crypto-runtime-" + secrets.token_hex(4)
    run(["docker", "network", "create", network], stdout=subprocess.DEVNULL)
    crypto = CryptoFixture(network)
    try:
        crypto.start()
        verify(crypto)
    finally:
        crypto.close()
        run(["docker", "network", "rm", network], stdout=subprocess.DEVNULL)


if __name__ == "__main__":
    main()

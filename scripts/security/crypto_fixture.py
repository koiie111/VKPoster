"""Disposable real OpenBao/TLS fixture. Synthetic capabilities only; no owner env/session."""

import base64
import json
import os
from pathlib import Path
import ssl
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import uuid

IMAGE = "ezposter-openbao:stage19"
PURPOSES = ("app", "webhook", "links", "pdf", "password", "clicks")


def runtime_environment(fixture, ambient=None):
    """Keep Docker host access; never let ambient app secrets override a synthetic Compose fixture."""
    ambient = os.environ if ambient is None else ambient
    allowed = {"PATH", "HOME", "DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_CONFIG", "DOCKER_API_VERSION", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH", "BUILDX_CONFIG", "XDG_RUNTIME_DIR", "SSH_AUTH_SOCK", "SYSTEMROOT"}
    return {**{key: value for key, value in ambient.items() if key in allowed}, **fixture}


class CryptoFixture:
    def __init__(self, network="vkposter_default"):
        self.network = network
        self.name = "stage19-bao-" + uuid.uuid4().hex[:10]
        self.temporary = tempfile.TemporaryDirectory(prefix="stage19-crypto-")
        self.path = Path(self.temporary.name)
        self.root = None

    def run(self, args, **kwargs):
        result = subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE, **kwargs)
        if result.returncode:
            diagnostic = (result.stderr + result.stdout).decode(errors='replace')[-32768:]
            for file in self.path.glob('*.token'):
                diagnostic = diagnostic.replace(file.read_text().strip(), '[redacted]')
            if self.root:
                diagnostic = diagnostic.replace(self.root, '[redacted]')
            Path('/tmp/stage19-crypto-command-error.log').write_text(diagnostic)
            raise RuntimeError('Isolated crypto fixture command failed; redacted evidence saved outside Git.')
        return result

    def start(self):
        self.path.chmod(0o755)  # Only synthetic CA/token fixtures, each capability remains 0600.
        self.run(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "2", "-keyout", str(self.path / "tls.key"), "-out", str(self.path / "ca.pem"), "-subj", "/CN=" + self.name, "-addext", "subjectAltName=DNS:" + self.name + ",DNS:localhost,IP:127.0.0.1"])
        (self.path / "tls.key").chmod(0o600)
        (self.path / "config.hcl").write_text('storage "raft" { path = "/tmp/bao-data" node_id = "fixture" }\ncluster_addr = "https://' + self.name + ':8201"\nlistener "tcp" { address = "0.0.0.0:8200" tls_cert_file = "/fixture/ca.pem" tls_key_file = "/fixture/tls.key" }\napi_addr = "https://' + self.name + ':8200"\n')
        self.run(["docker", "run", "-d", "--name", self.name, "--label", "stage19.fixture=crypto", "--network", self.network, "--user", "0:0", "--cap-drop", "ALL", "--security-opt", "no-new-privileges:true", "--read-only", "--tmpfs", "/tmp:size=64m,mode=1777", "-p", "127.0.0.1::8200", "-v", str(self.path) + ":/fixture:ro", "--entrypoint", "bao", IMAGE, "server", "-config=/fixture/config.hcl"])
        port = json.loads(self.run(["docker", "inspect", self.name, "--format", "{{json .NetworkSettings.Ports}}"]).stdout)["8200/tcp"][0]["HostPort"]
        self.url = "https://localhost:" + port
        self.endpoint = "https://" + self.name + ":8200"
        context = ssl.create_default_context(cafile=str(self.path / "ca.pem"))
        self.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPSHandler(context=context))
        for _ in range(100):
            try:
                self.api("GET", "sys/init")
                break
            except (OSError, urllib.error.URLError):
                time.sleep(0.1)
        else:
            logs = subprocess.run(["docker", "logs", self.name], stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
            Path("/tmp/stage19-crypto-startup.log").write_bytes(logs.stdout)
            raise RuntimeError("Isolated crypto fixture startup failed; bounded startup evidence in /tmp/stage19-crypto-startup.log")
        init = self.api("PUT", "sys/init", {"secret_shares": 1, "secret_threshold": 1})
        self.api("PUT", "sys/unseal", {"key": init["keys_base64"][0]})
        self.root = init["root_token"]
        self.api("POST", "sys/mounts/transit", {"type": "transit"})
        for purpose in PURPOSES:
            self.api("POST", "transit/keys/vkposter-" + purpose, {"type": "aes256-gcm96", "derived": True, "exportable": False, "allow_plaintext_backup": False})
        self.api("POST", "transit/keys/vkposter-legacy", {"type": "aes256-gcm96", "derived": True, "exportable": False, "allow_plaintext_backup": False})
        legacy = self.api("POST", "transit/encrypt/vkposter-legacy", {"plaintext": base64.b64encode(b"synthetic legacy value").decode(), "context": base64.b64encode(b"vkposter:secrets").decode()})
        (self.path / "legacy.cipher").write_text(legacy["data"]["ciphertext"])
        app = ['path "transit/decrypt/vkposter-legacy" { capabilities = ["update"] }']
        for purpose in PURPOSES:
            key = "vkposter-" + purpose
            app.append('path "transit/keys/' + key + '" { capabilities = ["read"] }')
            for op in ("encrypt", "decrypt", "rewrap", "hmac", "verify"):
                app.append('path "transit/' + op + '/' + key + ('/*' if op in ("hmac", "verify") else '') + '" { capabilities = ["update"] }')
        self.token("app", "\n".join(app))
        self.token("pdf", 'path "transit/verify/vkposter-pdf/sha2-256" { capabilities = ["update"] }')
        self.token("rotate", '\n'.join('path "transit/keys/vkposter-' + purpose + '/rotate" { capabilities = ["update"] }' for purpose in PURPOSES) + '\npath "transit/keys/vkposter-app/config" { capabilities = ["update"] }')
        return self

    def api(self, method, path, payload=None, token=None):
        request = urllib.request.Request(self.url + "/v1/" + path, data=None if payload is None else json.dumps(payload).encode(), method=method, headers={"Content-Type": "application/json", **({"X-Vault-Token": token or self.root} if token or self.root else {})})
        try:
            with self.opener.open(request, timeout=10) as response:
                body = response.read(1048576)
                return json.loads(body) if body else {}
        except urllib.error.HTTPError as error:
            raise RuntimeError("Isolated crypto API rejected operation: HTTP " + str(error.code)) from None

    def token(self, name, policy):
        self.api("PUT", "sys/policies/acl/fixture-" + name, {"policy": policy})
        value = self.api("POST", "auth/token/create", {"policies": ["fixture-" + name], "no_default_policy": True, "ttl": "1h"})["auth"]["client_token"]
        file = self.path / (name + ".token")
        file.write_text(value)
        file.chmod(0o600)
        # App runs UID1000; only the disposable capability file is changed.
        self.run(["docker", "run", "--rm", "--network", "none", "--user", "0:0", "-v", str(self.path) + ":/fixture", "--entrypoint", "sh", IMAGE, "-c", "chown 1000:1000 /fixture/" + name + ".token"])

    def close(self):
        try:
            subprocess.run(["docker", "rm", "-f", self.name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        finally:
            self.root = None
            self.temporary.cleanup()

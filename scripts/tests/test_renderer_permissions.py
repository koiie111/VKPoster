"""Actual Linux DAC regression: host bind-mount emulation must not mask renderer UID errors."""
import json
import secrets
import subprocess
import unittest


class RendererPermissionsTest(unittest.TestCase):
    def test_verify_only_capability_is_private_to_renderer_uid(self):
        name = "stage19-renderer-mode-" + secrets.token_hex(8)
        image = "ezposter-pdf:stage19"

        def run(args):
            return subprocess.run(args, capture_output=True, text=True, timeout=30)

        created = run(["docker", "volume", "create", "--label", "stage19.fixture=renderer-uid", name])
        self.assertEqual(created.returncode, 0, "Could not create isolated permission fixture")
        base = ["docker", "run", "--rm", "--pull=never", "--network", "none", "--read-only", "-v", name + ":/fixture", "--entrypoint", "node"]
        # A named Linux volume is deliberate: Docker Desktop bind mounts can allow
        # access which a Linux runner correctly denies, even for mode0600 files.
        try:
            init = "const f=require('node:fs'); f.writeFileSync('/fixture/token','fixture-only-verifier',{mode:0o600}); f.chownSync('/fixture/token',1000,1000);"
            self.assertEqual(run(base + ["--user", "0:0", image, "-e", init]).returncode, 0)
            denied = "try {require('node:fs').readFileSync('/fixture/token'); process.exit(2);} catch(e) {process.exit(e.code==='EACCES'?1:3);}"
            self.assertEqual(run(base + ["--cap-drop", "ALL", image, "-e", denied]).returncode, 1, "Wrong UID must fail closed")
            fix = "require('node:fs').chownSync('/fixture/token',1001,1001)"
            self.assertEqual(run(base + ["--user", "0:0", image, "-e", fix]).returncode, 0)
            allowed = "const f=require('node:fs'),s=f.statSync('/fixture/token'); if(process.getuid()!==1001||(s.mode&0o777)!==0o600||s.uid!==1001||!f.readFileSync('/fixture/token').length)process.exit(1);"
            self.assertEqual(run(base + ["--cap-drop", "ALL", image, "-e", allowed]).returncode, 0)
            self.assertEqual(run(base + ["--cap-drop", "ALL", "--user", "1000:1000", image, "-e", denied]).returncode, 1, "Application UID must not read renderer capability")
        finally:
            inspected = run(["docker", "volume", "inspect", name])
            if inspected.returncode == 0 and json.loads(inspected.stdout)[0].get("Labels", {}).get("stage19.fixture") == "renderer-uid":
                self.assertEqual(run(["docker", "volume", "rm", name]).returncode, 0)

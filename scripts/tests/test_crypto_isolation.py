"""A real owner's exported env must never redirect synthetic Compose credential mounts."""
import unittest
from unittest.mock import patch
import subprocess
import io
from scripts.security.crypto_fixture import CryptoFixture, runtime_environment


class CryptoIsolationTest(unittest.TestCase):
    def test_ambient_secrets_and_mounts_are_not_forwarded(self):
        result = runtime_environment(
            {"APP_ENV": "production", "VAULT_APP_TOKEN_HOST_PATH": "/synthetic/app.token"},
            {"PATH": "/bin", "HOME": "/runtime/home", "DOCKER_HOST": "unix:///runtime/socket", "APP_KEY": "must-not-forward", "OPENAI_API_KEY": "must-not-forward", "VAULT_APP_TOKEN_HOST_PATH": "/owner/real.token", "COMPOSE_PROFILES": "ops"},
        )
        self.assertNotIn("APP_KEY", result)
        self.assertNotIn("OPENAI_API_KEY", result)
        self.assertNotIn("COMPOSE_PROFILES", result)
        self.assertEqual(result["VAULT_APP_TOKEN_HOST_PATH"], "/synthetic/app.token")
        self.assertEqual(result["DOCKER_HOST"], "unix:///runtime/socket")
        self.assertEqual(result["PATH"], "/bin")


    def test_failure_redacts_capabilities_without_reading_other_uid_private_files(self):
        fixture = CryptoFixture()
        fixture.root = "synthetic-root-capability"
        fixture.capabilities.append("synthetic-app-capability")
        try:
            failed = subprocess.CompletedProcess(["fixture"], 1, b"synthetic-app-capability", b"synthetic-root-capability ")
            with patch("scripts.security.crypto_fixture.subprocess.run", return_value=failed), patch("scripts.security.crypto_fixture.Path.read_text", side_effect=PermissionError("private UID1000")), patch("scripts.security.crypto_fixture.Path.write_text") as write, patch("scripts.security.crypto_fixture.sys.stderr", new_callable=io.StringIO) as stderr:
                with self.assertRaisesRegex(RuntimeError, "redacted evidence"):
                    fixture.run(["fixture"])
                diagnostic = write.call_args.args[0]
                self.assertNotIn(fixture.root, diagnostic)
                self.assertNotIn(fixture.capabilities[0], diagnostic)
                self.assertIn("[redacted]", diagnostic)
                self.assertNotIn(fixture.root, stderr.getvalue())
                self.assertNotIn(fixture.capabilities[0], stderr.getvalue())
                self.assertIn("[redacted]", stderr.getvalue())
        finally:
            fixture.temporary.cleanup()

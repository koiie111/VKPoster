"""A real owner's exported env must never redirect synthetic Compose credential mounts."""
import unittest
from scripts.security.crypto_fixture import runtime_environment


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

"""Protected release reader configuration is validated without leaking credentials."""

import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ReleaseSourceConfigTest(unittest.TestCase):
    def test_valid_secrets_create_private_files(self):
        with tempfile.TemporaryDirectory() as directory:
            env = dict(
                os.environ,
                TELEGRAM_API_ID="12345",
                TELEGRAM_API_HASH="a" * 32,
                SOURCES_READER_SECRET="s" * 40,
                TELEGRAM_EGRESS_PROXY_URL="socks5://proxy.internal:1080",
            )
            result = subprocess.run(
                ["python3", str(ROOT / "scripts/release-source-config.py"), directory],
                env=env, capture_output=True, text=True,
            )
            self.assertEqual(result.returncode, 0)
            self.assertEqual(result.stdout + result.stderr, "")
            self.assertEqual((Path(directory) / "sources.env").stat().st_mode & 0o777, 0o600)
            self.assertEqual((Path(directory) / "reader.env").stat().st_mode & 0o777, 0o600)
            self.assertIn("SOURCES_ENABLED=true", (Path(directory) / "sources.env").read_text())

    def test_incomplete_or_loopback_proxy_is_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            env = dict(
                os.environ,
                TELEGRAM_API_ID="12345",
                TELEGRAM_API_HASH="a" * 32,
                SOURCES_READER_SECRET="s" * 40,
                TELEGRAM_EGRESS_PROXY_URL="socks5://127.0.0.1:1080",
            )
            result = subprocess.run(
                ["python3", str(ROOT / "scripts/release-source-config.py"), directory],
                env=env, capture_output=True, text=True,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(list(Path(directory).iterdir()), [])
            self.assertNotIn(env["SOURCES_READER_SECRET"], result.stderr)


if __name__ == "__main__":
    unittest.main()

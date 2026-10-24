"""Compose structural regressions use only an ephemeral synthetic env, never the owner's .env."""

import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class ProductionConfigTest(unittest.TestCase):
    def test_tmpfs_mounts_and_runtime_restrictions(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "fixture.env"
            config.write_text(
                "DB_PASSWORD=synthetic\nDB_ROOT_PASSWORD=synthetic\nREDIS_PASSWORD="
                + "r" * 48
                + "\n"
            )
            result = subprocess.run(
                [
                    "docker",
                    "compose",
                    "--env-file",
                    str(config),
                    "-f",
                    str(ROOT / "compose.prod.yaml"),
                    "config",
                    "--format",
                    "json",
                ],
                env=dict(os.environ, APP_ENV_FILE=str(config)),
                capture_output=True,
                text=True,
                check=True,
            )
            data = json.loads(result.stdout)
            for name in (
                "app",
                "worker",
                "scheduler",
                "analytics-worker",
                "redis",
                "nginx",
            ):
                service = data["services"][name]
                self.assertTrue(service["read_only"], name)
                self.assertIn("no-new-privileges:true", service["security_opt"], name)
                for mount in service.get("tmpfs", []):
                    self.assertTrue(mount.startswith("/"), (name, mount))
            self.assertIn("/tmp:size=128m,mode=1777", data["services"]["app"]["tmpfs"])
            self.assertIn("/tmp:size=8m,mode=1777", data["services"]["redis"]["tmpfs"])


if __name__ == "__main__":
    unittest.main()

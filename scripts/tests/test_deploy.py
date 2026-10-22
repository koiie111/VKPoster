"""Host release runner tests use fake commands, never SSH/production/Docker volumes."""

import os
import fcntl
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class DeployTest(unittest.TestCase):
    def run_release(self, mode, compatible="true", locked=False, analytics="false"):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            config = root / "app.env"
            config.write_text("APP_ENV=production\n")
            config.chmod(0o600)
            infra = root / "infra.env"
            infra.write_text("MYSQL_IMAGE=owner-mysql\nREDIS_IMAGE=owner-redis\n")
            infra.chmod(0o600)
            images = root / "release-images.env"
            images.write_text("".join(f"{name.upper()}_IMAGE=ghcr.io/fixture/vkposter-{name}@sha256:{'a' * 64}\n" for name in ("app", "web", "ops", "pdf")))
            (root / ".release.env").write_text("APP_IMAGE=previous\n")
            tools = root / "bin"
            tools.mkdir()
            if mode == "forged-manifest":
                images.write_text(images.read_text().replace("@sha256:", ":mutable-tag-"))
            scripts = {
                "docker": (
                    '#!/bin/sh\necho "$*" >> "$TRACE"\n'
                    'case "$*" in *"ps -q"*) echo fixture-infra;; '
                    'inspect*) test "$MODE" != infra-down || exit 1; echo "true healthy";; '
                    'image\ inspect*) test "$MODE" != digest-mismatch || exit 1; for arg in "$@"; do last="$arg"; done; echo "$last";; '
                    '*migrate*) test "$MODE" != migration-fail || exit 1;; '
                    '*"up -d"*) : > "$LAST_UP"; prev=""; for arg in "$@"; do if test "$prev" = --env-file; then cat "$arg" >> "$LAST_UP"; fi; prev="$arg"; done;; esac\n'
                ),
                "curl": '#!/bin/sh\nif test "$MODE" = ready-fail; then grep -q previous "$LAST_UP" || exit 1; fi\n',
                "sleep": "#!/bin/sh\nexit 0\n",
            }
            for name, source in scripts.items():
                path = tools / name
                path.write_text(source)
                path.chmod(0o755)
            env = dict(
                os.environ,
                PATH=str(tools) + ":" + os.environ["PATH"],
                DEPLOY_ROOT=tmp,
                PRODUCTION_ENV_FILE=str(config),
                INFRASTRUCTURE_ENV_FILE=str(infra),
                MIGRATIONS_BACKWARD_COMPATIBLE=compatible,
                MODE=mode,
                DEPLOY_ANALYTICS=analytics,
                TRACE=str(root / "trace"),
                LAST_UP=str(root / "last-up"),
            )
            lock = (root / ".operations.lock").open("w")
            if locked:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = subprocess.run(
                [
                    "bash",
                    str(ROOT / "scripts/deploy-release.sh"),
                    "v0.0.1",
                    "ghcr.io/fixture/vkposter",
                    str(images),
                ],
                env=env,
                capture_output=True,
                text=True,
            )
            lock.close()
            self.assertEqual(infra.read_text(), "MYSQL_IMAGE=owner-mysql\nREDIS_IMAGE=owner-redis\n")
            trace = (root / "trace").read_text() if (root / "trace").exists() else ""
            current = (root / ".release.env").read_text()
            return result.returncode, trace, current

    def test_backup_lock_blocks_release_before_any_change(self):
        code, trace, current = self.run_release("success", locked=True)
        self.assertEqual(code, 2)
        self.assertEqual(trace, "")
        self.assertEqual(current, "APP_IMAGE=previous\n")

    def test_analytics_release_updates_verify_only_renderer(self):
        code, trace, _ = self.run_release("success", analytics="true")
        self.assertEqual(code, 0)
        self.assertIn("--profile analytics", trace)
        self.assertIn("analytics-pdf", trace)
        self.assertNotIn("analytics-pdf-key", trace)

    def test_invalid_analytics_switch_changes_nothing(self):
        code, trace, _ = self.run_release("success", analytics="invalid")
        self.assertEqual(code, 2)
        self.assertEqual(trace, "")

    def test_success(self):
        code, trace, current = self.run_release("success")
        self.assertEqual(code, 0)
        self.assertIn("-app@sha256:" + "a" * 64, current)
        self.assertNotIn("MYSQL_IMAGE", current)
        self.assertNotIn("REDIS_IMAGE", current)
        self.assertIn("migrate", trace)
        self.assertNotIn("down", trace)

    def test_failed_readiness_rolls_back_images_only(self):
        code, trace, current = self.run_release("ready-fail")
        self.assertEqual(code, 1)
        self.assertEqual(current, "APP_IMAGE=previous\n")
        self.assertGreaterEqual(trace.count("up -d"), 2)
        self.assertNotIn("rollback", trace)
        self.assertNotIn("down -v", trace)
        for invocation in trace.splitlines():
            if "up -d" in invocation:
                self.assertIn("--no-deps", invocation)
                targets = invocation.split("--no-deps", 1)[1].split()
                self.assertTrue(set(targets).issubset({"app", "nginx", "worker", "scheduler", "analytics-worker", "analytics-pdf"}))

    def test_migration_failure_preserves_running_release(self):
        code, trace, current = self.run_release("migration-fail")
        self.assertEqual(code, 1)
        self.assertNotIn("up -d", trace)
        self.assertEqual(current, "APP_IMAGE=previous\n")

    def test_unknown_schema_compatibility_fails_before_changes(self):
        code, trace, _ = self.run_release("success", "")
        self.assertEqual(code, 2)
        self.assertEqual(trace, "")

    def test_forged_or_unavailable_artifact_fails_before_migration(self):
        for mode in ("forged-manifest", "digest-mismatch", "infra-down"):
            code, trace, current = self.run_release(mode)
            self.assertNotEqual(code, 0)
            self.assertNotIn("migrate", trace)
            self.assertNotIn("up -d", trace)
            self.assertEqual(current, "APP_IMAGE=previous\n")


if __name__ == "__main__":
    unittest.main()

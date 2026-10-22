"""Mock registry assertions: scan, publish and deploy must reference exactly the same manifest."""
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("release_artifacts", ROOT / "scripts/release-artifacts.py")
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)
DIGEST = "sha256:" + "a" * 64


class ReleaseArtifactsTest(unittest.TestCase):
    def fixtures(self, directory):
        for suffix in release.SUFFIXES:
            (directory / (suffix + ".json")).write_text(json.dumps(dict(suffix=suffix, repository="ghcr.io/fixture/vkposter-" + suffix, digest=DIGEST, scanned=DIGEST)))

    def test_failed_scan_never_produces_promotion_record(self):
        with tempfile.TemporaryDirectory() as tmp, patch.object(release, "digest", return_value=DIGEST), patch.object(release, "docker"), patch.object(release.subprocess, "run", side_effect=subprocess.CalledProcessError(1, "scan")):
            with self.assertRaises(subprocess.CalledProcessError):
                release.record("app", "ghcr.io/fixture/vkposter-app", DIGEST, Path(tmp))
            self.assertEqual(list(Path(tmp).glob("*.json")), [])

    def test_promote_never_rebuilds_and_deploys_only_scanned_digest(self):
        absent = subprocess.CompletedProcess([], 1, "", "manifest unknown")
        with tempfile.TemporaryDirectory() as tmp, patch.object(release, "digest", return_value=DIGEST), patch.object(release, "docker") as docker, patch.object(release.subprocess, "run", return_value=absent):
            directory = Path(tmp)
            self.fixtures(directory)
            output = directory / "release-images.env"
            release.promote(directory, "v0.0.1", "ghcr.io/fixture/vkposter", output)
            self.assertEqual(len(docker.call_args_list), 9)
            for call in docker.call_args_list:
                self.assertEqual(call.args[:3], ("buildx", "imagetools", "create"))
                self.assertTrue(call.args[-1].endswith("@" + DIGEST))
                self.assertIn("--prefer-index=false", call.args)
            lines = output.read_text().splitlines()
            self.assertEqual(len(lines), 4)
            self.assertTrue(all(line.endswith("@" + DIGEST) for line in lines))
            self.assertEqual(output.stat().st_mode & 0o777, 0o600)

    def test_scan_registry_or_publish_digest_mismatch_fails_closed(self):
        with tempfile.TemporaryDirectory() as tmp:
            directory = Path(tmp)
            self.fixtures(directory)
            path = directory / "app.json"
            row = json.loads(path.read_text())
            row["scanned"] = "sha256:" + "b" * 64
            path.write_text(json.dumps(row))
            with self.assertRaisesRegex(RuntimeError, "Scanned/published"):
                release.manifest(directory)
            self.fixtures(directory)
            with patch.object(release, "digest", return_value="sha256:" + "b" * 64):
                with self.assertRaisesRegex(RuntimeError, "Registry artifact"):
                    release.manifest(directory)
            absent = subprocess.CompletedProcess([], 1, "", "not found")
            with patch.object(release, "digest", side_effect=[DIGEST] * 9 + ["sha256:" + "b" * 64]), patch.object(release, "docker"), patch.object(release.subprocess, "run", return_value=absent):
                with self.assertRaisesRegex(RuntimeError, "Published/scanned"):
                    release.promote(directory, "v0.0.1", "ghcr.io/fixture/vkposter", directory / "release.env")
                self.assertFalse((directory / "release.env").exists())

    def test_existing_version_cannot_be_overwritten(self):
        different = subprocess.CompletedProcess([], 0, "sha256:" + "b" * 64, "")
        with tempfile.TemporaryDirectory() as tmp, patch.object(release, "digest", return_value=DIGEST), patch.object(release, "docker") as docker, patch.object(release.subprocess, "run", return_value=different):
            directory = Path(tmp)
            self.fixtures(directory)
            with self.assertRaisesRegex(RuntimeError, "Refusing to replace"):
                release.promote(directory, "v0.0.1", "ghcr.io/fixture/vkposter", directory / "release.env")
            docker.assert_not_called()


if __name__ == "__main__":
    unittest.main()

"""Reproduce transient PECL failures without network or a PHP runtime."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


class PeclRetryTest(unittest.TestCase):
    def run_fixture(self, mode):
        with tempfile.TemporaryDirectory(prefix="pecl-retry-") as directory:
            root = Path(directory)
            (root / "php-config").write_text('#!/bin/sh\nprintf "%s\\n" "$PECL_FIXTURE"\n')
            (root / "sleep").write_text('#!/bin/sh\nexit 0\n')
            (root / "pecl").write_text('''#!/bin/sh
module="${2%%-*}"
file="$PECL_FIXTURE/$module.attempts"
count=0
if [ -f "$file" ]; then count="$(cat "$file")"; fi
count=$((count + 1))
printf '%s' "$count" > "$file"
if [ "$PECL_MODE" = exhausted ]; then exit 1; fi
if [ "$PECL_MODE" = missing ]; then exit 0; fi
if [ "$count" -lt 3 ]; then exit 1; fi
printf fixture > "$PECL_FIXTURE/$module.so"
''')
            for name in ("php-config", "sleep", "pecl"):
                (root / name).chmod(0o700)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ["PATH"],
                       PECL_FIXTURE=str(root), PECL_MODE=mode)
            result = subprocess.run(["sh", str(ROOT / "docker/php/install-pecl.sh"),
                                     "redis-6.3.0", "imagick-3.8.1"],
                                    env=env, capture_output=True, text=True, timeout=10)
            attempts = {p.stem: int(p.read_text()) for p in root.glob("*.attempts")}
            return result, attempts

    def test_transient_errors_retry_each_extension_independently(self):
        result, attempts = self.run_fixture("recover")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(attempts, {"redis": 3, "imagick": 3})

    def test_exhaustion_fails_without_installing_next_extension(self):
        result, attempts = self.run_fixture("exhausted")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(attempts, {"redis": 3})

    def test_pecl_success_without_compiled_module_fails_closed(self):
        result, attempts = self.run_fixture("missing")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(attempts, {"redis": 3})

#!/usr/bin/env python3
"""Scan only git-visible files (not ignored local credentials) and prove exceptions stay narrow."""

import argparse
import json
import os
import pathlib
import shutil
import subprocess
import tempfile

ROOT = pathlib.Path(__file__).resolve().parents[2]
IMAGE = "zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f"


def scan(root, mode, expected=0):
    cmd = [
        "docker",
        "run",
        "--rm",
        "--network",
        "none",
        "-v",
        str(root) + ":/repo:ro",
        "-w",
        "/repo",
        IMAGE,
        mode,
        "/repo",
        "--redact=100",
        "--no-banner",
        "--log-level=warn",
    ]
    if mode == "git":
        cmd += ["--log-opts=--all"]
    with tempfile.TemporaryDirectory(prefix="gitleaks-report-") as tmp:
        cmd[2:2] = ["-v", tmp + ":/reports"]
        cmd += ["--report-format=json", "--report-path=/reports/result.json"]
        result = subprocess.run(cmd)
        if expected == 1 and result.returncode == 1:
            findings = json.loads((pathlib.Path(tmp) / "result.json").read_text())
            if not any(row["RuleID"] == "generic-api-key" for row in findings):
                raise SystemExit(
                    "Regression must exercise generic-api-key, not a different detector"
                )
        if result.returncode != expected:
            report = pathlib.Path(tmp) / "result.json"
            if report.exists():
                for row in json.loads(report.read_text()):
                    print({k: row.get(k) for k in ["File", "StartLine", "RuleID"]})
            raise SystemExit(
                f"Gitleaks gate failed: mode={mode}, expected={expected}, actual={result.returncode} (values redacted)"
            )


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--regression-only", action="store_true")
    args = parser.parse_args()
    if not args.regression_only:
        scan(ROOT, "git")
        with tempfile.TemporaryDirectory(prefix="vkposter-scan-") as tmp:
            target = pathlib.Path(tmp)
            files = subprocess.check_output(
                ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"],
                cwd=ROOT,
            ).split(b"\0")
            for name in files:
                if not name:
                    continue
                rel = pathlib.Path(os.fsdecode(name))
                source = ROOT / rel
                if not source.is_file() or source.is_symlink():
                    continue
                dest = target / rel
                dest.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, dest)
            scan(target, "dir")
    for relative in ["new/config.php", "tests/Support/TestEnv.php"]:
        with tempfile.TemporaryDirectory(prefix="vkposter-scan-regression-") as tmp:
            target = pathlib.Path(tmp)
            shutil.copyfile(ROOT / ".gitleaks.toml", target / ".gitleaks.toml")
            shutil.copyfile(ROOT / ".gitleaksignore", target / ".gitleaksignore")
            dest = target / relative
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text(
                "<?php $api_key = '"
                + "".join(str(i % 10) + chr(65 + (i * 7) % 26) for i in range(24))
                + "';\n"
            )
            scan(target, "dir", 1)
    print("PASS: history/worktree or regression checked; new keys remain detectable")


if __name__ == "__main__":
    main()

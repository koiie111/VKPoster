#!/usr/bin/env python3
"""Build once elsewhere; scan immutable candidates, verify runtime inputs, promote unchanged manifests."""
import argparse
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
SUFFIXES = {"app", "web", "ops", "pdf", "reader", "mysql", "redis", "caddy", "crypto"}
ALIASES = {"app": "ezposter-app:stage19", "web": "ezposter-web:stage19", "ops": "ezposter-ops:stage19",
           "pdf": "ezposter-pdf:stage19", "reader": "ezposter-reader:stage19", "mysql": "ezposter-mysql:stage19",
           "redis": "ezposter-redis:stage19", "caddy": "ezposter-caddy:stage19", "crypto": "ezposter-openbao:stage19"}


def docker(*args):
    return subprocess.check_output(["docker", *args], text=True).strip()


def digest(reference):
    value = docker("buildx", "imagetools", "inspect", reference, "--format", "{{.Manifest.Digest}}")
    if re.fullmatch(r"sha256:[a-f0-9]{64}", value) is None:
        raise RuntimeError("Registry did not return an immutable digest")
    return value


def validate(row):
    if not isinstance(row, dict) or row.get("suffix") not in SUFFIXES:
        raise RuntimeError("Invalid image manifest")
    if re.fullmatch(r"ghcr.io/[a-z0-9._/-]+-" + row["suffix"], row.get("repository", "")) is None:
        raise RuntimeError("Invalid repository")
    if re.fullmatch(r"sha256:[a-f0-9]{64}", row.get("digest", "")) is None or row.get("scanned") != row["digest"]:
        raise RuntimeError("Scanned/published digest mismatch")
    return row


def record(suffix, repository, expected, directory):
    row = validate(dict(suffix=suffix, repository=repository, digest=expected, scanned=expected))
    reference = repository + "@" + expected
    if digest(reference) != expected:
        raise RuntimeError("Candidate digest mismatch")
    docker("pull", reference)
    subprocess.run(["sh", str(ROOT / "scripts/security/scan.sh"), "trivy", reference], check=True)
    directory.mkdir(parents=True, exist_ok=True)
    # A record exists only after this exact immutable reference passes the mandatory scanner.
    (directory / (suffix + ".json")).write_text(json.dumps(row) + "\n")


def manifest(directory):
    rows = [validate(json.loads(p.read_text())) for p in sorted(directory.glob("*.json"))]
    if len(rows) != len(SUFFIXES) or {r["suffix"] for r in rows} != SUFFIXES:
        raise RuntimeError("Incomplete release image set")
    for row in rows:
        if digest(row["repository"] + "@" + row["digest"]) != row["digest"]:
            raise RuntimeError("Registry artifact mismatch")
    return rows


def runtime(directory):
    for row in manifest(directory):
        reference = row["repository"] + "@" + row["digest"]
        docker("pull", reference)
        docker("tag", reference, ALIASES[row["suffix"]])


def promote(directory, version, prefix, output):
    if re.fullmatch(r"v\d+\.\d+\.\d+(?:[.-][A-Za-z0-9.-]+)?", version) is None:
        raise RuntimeError("Invalid version")
    rows = manifest(directory)
    release = []
    for row in rows:
        if row["repository"] != prefix + "-" + row["suffix"]:
            raise RuntimeError("Release repository mismatch")
        reference = row["repository"] + "@" + row["digest"]
        tag = row["repository"] + ":" + version
        existing = subprocess.run(["docker", "buildx", "imagetools", "inspect", tag, "--format", "{{.Manifest.Digest}}"], capture_output=True, text=True)
        if existing.returncode == 0 and existing.stdout.strip() != row["digest"]:
            raise RuntimeError("Refusing to replace an existing version artifact")
        if existing.returncode != 0 and not any(marker in existing.stderr.lower() for marker in ("not found", "manifest unknown")):
            raise RuntimeError("Cannot safely establish release tag state")
        docker("buildx", "imagetools", "create", "--prefer-index=false", "--tag", tag, reference)
        if digest(tag) != row["digest"]:
            raise RuntimeError("Published/scanned digest mismatch")
        if row["suffix"] in {"app", "web", "ops", "pdf"}:
            release.append(row["suffix"].upper() + "_IMAGE=" + reference)
    output.write_text("\n".join(sorted(release)) + "\n")
    output.chmod(0o600)


def main():
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="operation", required=True)
    p = sub.add_parser("record")
    p.add_argument("suffix")
    p.add_argument("repository")
    p.add_argument("digest")
    p.add_argument("directory", type=Path)
    p = sub.add_parser("runtime")
    p.add_argument("directory", type=Path)
    p = sub.add_parser("promote")
    p.add_argument("directory", type=Path)
    p.add_argument("version")
    p.add_argument("prefix")
    p.add_argument("output", type=Path)
    args = parser.parse_args()
    if args.operation == "record":
        record(args.suffix, args.repository, args.digest, args.directory)
    elif args.operation == "runtime":
        runtime(args.directory)
    else:
        promote(args.directory, args.version, args.prefix, args.output)


if __name__ == "__main__":
    main()

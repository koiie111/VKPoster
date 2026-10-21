#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
case "${1:-}" in
 semgrep)
  shasum -a 256 -c tools/security/semgrep.sha256
  docker run --rm --network none -e SEMGREP_SEND_METRICS=off -e HOME=/tmp -v "$PWD:/src:ro" -w /src semgrep/semgrep:1.181.0 semgrep scan --config=tools/security/semgrep-php.yml --config=tools/security/semgrep-owasp.yml --metrics=off --error --strict src config public bin scripts
  ;;
 trivy)
  test -n "${2:-}" || { echo 'usage: scan.sh trivy IMAGE' >&2; exit 2; }
  docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy:0.76.0 image --scanners vuln --severity HIGH,CRITICAL --exit-code 1 --no-progress "$2"
  ;;
 *) echo 'usage: scan.sh semgrep | trivy IMAGE' >&2; exit 2 ;;
esac

#!/bin/sh
# Passive DAST may submit discovered forms. Only the dedicated synthetic Docker stand is permitted.
set -eu
: "${ZAP_NETWORK:?set the isolated Docker network}"
: "${ZAP_TARGET:?set https://stage19-security-web.test}"
test "$ZAP_TARGET" = https://stage19-security-web.test || {
    echo 'Refusing DAST outside the isolated stage19-security-web stand' >&2; exit 2;
}
base=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
reports=${ZAP_REPORT_DIRECTORY:-/tmp/vkposter-zap}
mkdir -p "$reports"
# -silent freezes the image's installed rules, without online addon updates; no warnings are ignored.
# Short output avoids printing OAuth/capability query values discovered during crawling.
docker run --rm --network "$ZAP_NETWORK" -v "$reports:/zap/wrk:rw" -v "$base/tools/security/zap-baseline.conf:/zap/baseline.conf:ro" \
    ghcr.io/zaproxy/zaproxy:2.17.0@sha256:5ccfff9a411743ab2615136a2edd7416bec9ce41572fa94e935df38c14adb2b6 \
    zap-baseline.py -t "$ZAP_TARGET" -z '-silent' -c /zap/baseline.conf -T 5 -s -J zap.json -r zap.html

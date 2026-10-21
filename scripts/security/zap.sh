#!/bin/sh
# Passive DAST may submit forms. Only the dedicated synthetic Docker stand is permitted.
set -eu
: "${ZAP_NETWORK:?set the isolated Docker network}"
: "${ZAP_TARGET:?set https://stage19-security-web.test}"
test "$ZAP_TARGET" = https://stage19-security-web.test || {
    echo 'Refusing DAST outside the isolated stage19-security-web stand' >&2; exit 2;
}
base=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
reports=${ZAP_REPORT_DIRECTORY:-/tmp/vkposter-zap}
mkdir -p "$reports"
run_reports=$(mktemp -d "$reports/run.XXXXXXXX")
volume=stage19-zap-$(python3 -c 'import secrets; print(secrets.token_hex(8))')
image=ghcr.io/zaproxy/zaproxy:2.17.0@sha256:5ccfff9a411743ab2615136a2edd7416bec9ce41572fa94e935df38c14adb2b6
if docker volume inspect "$volume" >/dev/null 2>&1; then
    echo 'Refusing existing scanner report volume' >&2; exit 2;
fi
docker volume create --label stage19.fixture=zap-reports "$volume" >/dev/null
cleanup() {
    if [ "$(docker volume inspect "$volume" --format '{{index .Labels "stage19.fixture"}}' 2>/dev/null || true)" = zap-reports ]; then
        docker volume rm "$volume" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP
# A real Linux volume avoids Desktop's bind-mount permission emulation. Only this
# empty test volume is initialized, never the caller's report directory or credentials.
docker run --rm --network none --user 0:0 -v "$volume:/zap/wrk" --entrypoint sh "$image" \
    -ec 'test "$(id -u zap)" = 1000; chown 1000:1000 /zap/wrk; chmod 0700 /zap/wrk'
# -silent freezes installed rules; no warnings are ignored. Logs exclude request URLs.
set +e
docker run --rm --network "$ZAP_NETWORK" -v "$volume:/zap/wrk:rw" -v "$base/tools/security/zap-baseline.conf:/zap/baseline.conf:ro" \
    "$image" zap-baseline.py -t "$ZAP_TARGET" -z '-silent' -c /zap/baseline.conf -T 5 -s -J zap.json -r zap.html
status=$?
set -e
# Export only the two scanner-generated report files into a new private host directory.
# The host caller owns the extracted files; scanner UID1000 needs no host write access.
if docker run --rm --network none --user 0:0 -v "$volume:/zap/wrk:ro" --entrypoint sh "$image" \
    -ec 'cd /zap/wrk; test -f zap.json; test -f zap.html; tar -cf - zap.json zap.html' > "$run_reports/report.tar"; then
    tar -xOf "$run_reports/report.tar" zap.json > "$run_reports/zap.json"
    tar -xOf "$run_reports/report.tar" zap.html > "$run_reports/zap.html"
    rm "$run_reports/report.tar"
    echo "ZAP reports: $run_reports"
else
    echo 'Scanner did not produce both reports' >&2
    if [ "$status" -eq 0 ]; then status=1; fi
fi
exit "$status"

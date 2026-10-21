#!/bin/bash
# Host-side release runner; invoked only after protected CI/tag/production-environment gates.
set -euo pipefail
# No set -x: env files, SSH and registry credentials must never reach logs.
root=${DEPLOY_ROOT:-/opt/ezposter}
config=${PRODUCTION_ENV_FILE:-/etc/ezposter/app.env}
release=${1:?usage: deploy-release.sh VERSION REGISTRY_PREFIX}
prefix=${2:?registry image prefix required}
[[ "$release" =~ ^v[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9.-]+)?$ ]] || exit 2
[[ "$prefix" =~ ^ghcr.io/[a-z0-9._/-]+$ ]] || exit 2
[[ -f "$config" && ! -L "$config" && $(stat -c %a "$config") == 600 ]] || { echo 'Private production env file required' >&2; exit 2; }
cd "$root"
exec 9> .operations.lock
flock -n 9 || { echo 'Another operational task owns the lock' >&2; exit 2; }
# This explicit release declaration is required before migrations; never automatically reverse DDL.
[[ ${MIGRATIONS_BACKWARD_COMPATIBLE:-} == true ]] || { echo 'Declare compatibility or use the reviewed manual migration/recovery procedure' >&2; exit 2; }
previous=$(mktemp)
next=$(mktemp)
trap 'rm -f "$previous" "$next"' EXIT
if [[ -f .release.env ]]; then cp .release.env "$previous"; fi
printf 'APP_ENV_FILE=%s\nAPP_IMAGE=%s-app:%s\nWEB_IMAGE=%s-web:%s\nPDF_IMAGE=%s-pdf:%s\nOPS_IMAGE=%s-ops:%s\nREDIS_IMAGE=%s-redis:%s\nMYSQL_IMAGE=%s-mysql:%s\n' "$config" "$prefix" "$release" "$prefix" "$release" "$prefix" "$release" "$prefix" "$release" "$prefix" "$release" "$prefix" "$release" > "$next"
services=(app nginx worker scheduler analytics-worker redis clamav)
profiles=()
case ${DEPLOY_ANALYTICS:-false} in
    true) profiles=(--profile analytics); services+=(analytics-pdf);;
    false) ;;
    *) echo 'DEPLOY_ANALYTICS must be true or false' >&2; exit 2;;
esac
compose() { docker compose -f compose.prod.yaml --env-file "$config" --env-file "$1" "${profiles[@]}" "${@:2}"; }
ready() {
    for attempt in $(seq 1 30); do
        if curl --fail --silent --max-time 5 http://127.0.0.1:8080/readyz >/dev/null; then return 0; fi
        sleep 2
    done
    return 1
}
# Images are immutable version references; retain the previous release references for image rollback.
compose "$next" pull "${services[@]}"
if ! compose "$next" run --rm --no-deps app php bin/console migrate; then
    echo 'Migration failed: stop rollout; preserve old services and use runbook. No automatic DB rollback.' >&2
    exit 1
fi
if compose "$next" up -d --no-build "${services[@]}" && ready; then
    cp "$next" .release.env
    echo 'Release ready'
else
    if [[ -s "$previous" ]]; then
        compose "$previous" up -d --no-build "${services[@]}"
        ready || { echo 'Image rollback readiness failed; manual recovery required' >&2; exit 1; }
        echo 'Previous images restored; database schema was not rolled back' >&2
    else
        echo 'First deploy failed; no prior release exists. Manual recovery required.' >&2
    fi
    exit 1
fi

#!/bin/sh
# Disposable HTTP PHP service + synthetic reader contract against app_test only.
set -eu
cd "$(dirname "$0")/../../.."
container="vkposter-source-contract-$$"
port="${TEST_HTTP_PORT:-18085}"
secret="source-reader-contract-secret-not-real-123456789"
fixture=$(mktemp -d)
trap 'docker rm -f "$container" >/dev/null 2>&1 || true; rm -rf "$fixture"' EXIT INT TERM
# Only Git-visible reader/test files enter the test runtime: never mount the owner's env/session/state.
python3 - "$fixture" <<'PYCODE'
import pathlib, subprocess, sys, shutil
root = pathlib.Path.cwd()
target = pathlib.Path(sys.argv[1])
for raw in subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z', 'tools/telegram-reader/reader', 'tools/telegram-reader/tests']).split(b'\0'):
    if not raw:
        continue
    source = root / raw.decode()
    if source.is_symlink() or not source.is_file():
        raise RuntimeError('Reader contract input must be a regular Git-visible file')
    if source.suffix not in ('.py', '.php', '.sh', '.json'):
        continue
    relative = source.relative_to(root / 'tools/telegram-reader')
    destination = target / relative
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copyfile(source, destination)
PYCODE
docker compose run -d --no-deps --name "$container" -p "127.0.0.1:$port:8085" \
    -e APP_ENV=testing -e APP_DEBUG=0 -e SOURCES_ENABLED=true -e DB_DATABASE=app_test -e REDIS_DB=15 -e MEDIA_LOCAL_ROOT=storage/testing/source-image-contract \
    -e "SOURCES_READER_SECRET=$secret" app php -S 0.0.0.0:8085 -t public >/dev/null
docker compose run --rm --no-deps app php tools/telegram-reader/tests/seed_contract.php
docker run --rm --read-only --tmpfs /tmp -v "$fixture:/work:ro" -w /work \
    -e "TEST_INTERNAL_URL=http://host.docker.internal:$port" -e "TEST_READER_SECRET=$secret" \
    --entrypoint python "${READER_TEST_IMAGE:-vkposter-telegram-reader-spike-reader}" \
    -m unittest tests.test_integration.PHPContractTests -v
docker compose run --rm --no-deps app php -r 'require "vendor/autoload.php"; $db=App\Tests\Support\TestEnv::connection(); foreach(["source_events", "source_items", "source_messages"] as $t) {echo $t, ": ", $db->table($t)->count(), PHP_EOL;} if($db->table("source_items")->count()!==2 || $db->table("source_messages")->count()!==4) {exit(1);}'

docker compose run --rm --no-deps app php tools/telegram-reader/tests/prepare_image_contract.php
docker run --rm --read-only --tmpfs /tmp -v "$fixture:/work:ro" -w /work \
    -e "TEST_INTERNAL_URL=http://host.docker.internal:$port" -e "TEST_READER_SECRET=$secret" \
    --entrypoint python "${READER_TEST_IMAGE:-vkposter-telegram-reader-spike-reader}" \
    -m unittest tests.test_image_delivery.PHPImageContractTests -v
docker compose run --rm --no-deps app php -r 'require "vendor/autoload.php"; $db=App\Tests\Support\TestEnv::connection(); $runs=$db->table("source_image_processings")->where("status","=","completed")->count(); $variants=$db->table("source_image_variants")->count(); echo "image_runs: $runs, variants: $variants", PHP_EOL; if($runs!==4 || $variants!==8) {exit(1);}'

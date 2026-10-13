#!/bin/sh
# CI only: disposable PHP HTTP server, service MySQL app_test, synthetic fixtures; never Telegram.
set -eu
container="vkposter-reader-ci-contract-$$"
secret="source-reader-contract-secret-not-real-123456789"
network="${CONTRACT_NETWORK:-host}"
url="http://127.0.0.1:18085"
reader_network="$network"
if [ "$network" != host ]; then reader_network="container:$container"; fi
trap 'docker rm -f "$container" >/dev/null 2>&1 || true' EXIT INT TERM
docker run -d --name "$container" --network "$network" \
    -e APP_ENV=testing -e APP_DEBUG=0 -e APP_KEY=AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8= -e DB_HOST -e DB_PORT -e DB_DATABASE=app_test \
    -e DB_USERNAME -e DB_PASSWORD -e REDIS_HOST -e REDIS_PORT \
    -e MEDIA_LOCAL_ROOT=storage/testing/source-image-contract -e "SOURCES_READER_SECRET=$secret" \
    -v "$PWD:/var/www/html" vkposter-app-dev php -S 0.0.0.0:18085 -t public >/dev/null
for fixture in seed_contract prepare_image_contract; do
    docker exec "$container" php "tools/telegram-reader/tests/$fixture.php"
    if [ "$fixture" = seed_contract ]; then testclass=tests.test_integration.PHPContractTests; else testclass=tests.test_image_delivery.PHPImageContractTests; fi
    docker run --rm --network "$reader_network" -e "TEST_INTERNAL_URL=$url" \
        -e "TEST_READER_SECRET=$secret" --entrypoint python vkposter-telegram-reader -m unittest "$testclass" -v
done

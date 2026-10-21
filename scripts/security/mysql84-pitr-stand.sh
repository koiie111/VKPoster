#!/bin/sh
# Fully isolated real production-tooling regression, including on a fresh CI runner.
set -eu
cd "$(dirname "$0")/../.."
prefix=stage19-ci-pitr-$(python3 -c 'import secrets; print(secrets.token_hex(6))')
mysql=$prefix-mysql
redis=$prefix-redis
network_created=false
data_created=false
socket_created=false
cleanup() {
    for name in "$mysql" "$redis"; do
        if [ "$(docker inspect "$name" --format '{{index .Config.Labels "stage19.fixture"}}' 2>/dev/null || true)" = mysql84-patched ]; then
            docker rm -f "$name" >/dev/null 2>&1 || true
        fi
    done
    if [ "$socket_created" = true ]; then docker volume rm "$prefix-socket" >/dev/null 2>&1 || true; fi
    if [ "$data_created" = true ]; then docker volume rm "$prefix-data" >/dev/null 2>&1 || true; fi
    if [ "$network_created" = true ]; then docker network rm "$prefix" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT HUP INT TERM
docker network create "$prefix" >/dev/null
network_created=true
for volume in "$prefix-data" "$prefix-socket"; do
    if docker volume inspect "$volume" >/dev/null 2>&1; then echo 'Fixture volume already exists; refusing reuse' >&2; exit 1; fi
done
docker volume create "$prefix-data" >/dev/null
data_created=true
docker volume create "$prefix-socket" >/dev/null
socket_created=true
docker run -d --name "$mysql" --label stage19.fixture=mysql84-patched --network "$prefix" --memory 1g \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=app_test -e MYSQL_USER=app -e MYSQL_PASSWORD=app \
    -v "$prefix-data:/var/lib/mysql" -v "$prefix-socket:/var/run/mysqld" \
    -v "$PWD/docker/mysql/my.cnf:/etc/mysql/conf.d/app.cnf:ro" \
    -v "$PWD/docker/mysql/production.cnf:/etc/mysql/conf.d/production.cnf:ro" ezposter-mysql:stage19 >/dev/null
# This intentionally synthetic password matches the production-config fixture, never a real Redis secret.
docker run -d --name "$redis" --label stage19.fixture=mysql84-patched --network "$prefix" --memory 128m --tmpfs /data \
    redis:7-alpine redis-server --requirepass "$(python3 -c 'print("r"*48)')" >/dev/null
for attempt in $(seq 1 120); do
    if docker exec "$mysql" mysql -uroot -proot -N -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$mysql" mysql -uroot -proot -N -e 'SELECT 1' >/dev/null 2>&1
PITR_MYSQL_FIXTURE=$mysql PITR_TEST_NETWORK=$prefix PITR_REDIS_HOST=$redis \
    python3 scripts/security/mysql84-pitr.py

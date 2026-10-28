#!/usr/bin/env bash
set -euo pipefail

PS_VERSION="${1:?PrestaShop version is required}"
BASE_061="${2:-1fb56ccecc87a1ad2f6f4d1af0fb553f85264e03}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NETWORK="pssfmc-${PS_VERSION//./-}-${GITHUB_RUN_ID:-local}-${RANDOM}"
DB_CONTAINER="${NETWORK}-db"
PS_CONTAINER="${NETWORK}-ps"
PORT="8080"

cleanup() {
  set +e
  if docker ps -a --format '{{.Names}}' | grep -qx "$PS_CONTAINER"; then
    echo '--- PrestaShop logs ---'
    docker logs "$PS_CONTAINER" 2>&1 | tail -n 200 || true
    docker rm -f "$PS_CONTAINER" >/dev/null 2>&1 || true
  fi
  if docker ps -a --format '{{.Names}}' | grep -qx "$DB_CONTAINER"; then
    echo '--- MySQL logs ---'
    docker logs "$DB_CONTAINER" 2>&1 | tail -n 100 || true
    docker rm -f "$DB_CONTAINER" >/dev/null 2>&1 || true
  fi
  docker network rm "$NETWORK" >/dev/null 2>&1 || true
}
trap cleanup EXIT

sql() {
  docker exec "$DB_CONTAINER" mysql -uroot -padmin --batch --skip-column-names prestashop -e "$1"
}

copy_module() {
  local source_dir="$1"
  docker exec "$PS_CONTAINER" rm -rf /var/www/html/modules/pssalesforcebridge
  docker cp "$source_dir" "$PS_CONTAINER:/var/www/html/modules/pssalesforcebridge"
  docker exec "$PS_CONTAINER" chown -R www-data:www-data /var/www/html/modules/pssalesforcebridge
}

console() {
  docker exec -w /var/www/html "$PS_CONTAINER" php bin/console "$@"
}

wait_for_shop() {
  local ready=0
  for _ in $(seq 1 120); do
    if curl -fsS --max-time 5 "http://127.0.0.1:${PORT}/" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 5
  done
  if [ "$ready" -ne 1 ]; then
    echo "PrestaShop ${PS_VERSION} did not become reachable" >&2
    exit 1
  fi
}

echo "==> Preparing exact module fixtures"
rm -rf /tmp/pssfmc-runtime-061 /tmp/pssfmc-runtime-070 "$ROOT/build/runtime"
mkdir -p /tmp/pssfmc-runtime-061 /tmp/pssfmc-runtime-070 "$ROOT/build/runtime"

git -C "$ROOT" archive "$BASE_061" pssalesforcebridge | tar -x -C /tmp/pssfmc-runtime-061
(
  cd "$ROOT"
  zip -qr build/runtime/pssalesforcebridge-0.7.0.zip pssalesforcebridge -x '*/vendor/*' '*.DS_Store'
)
unzip -q "$ROOT/build/runtime/pssalesforcebridge-0.7.0.zip" -d /tmp/pssfmc-runtime-070

test -f /tmp/pssfmc-runtime-061/pssalesforcebridge/pssalesforcebridge.php
test -f /tmp/pssfmc-runtime-070/pssalesforcebridge/pssalesforcebridge.php

grep -Fq "\$this->version = '0.6.1';" /tmp/pssfmc-runtime-061/pssalesforcebridge/pssalesforcebridge.php
grep -Fq "\$this->version = '0.7.0';" /tmp/pssfmc-runtime-070/pssalesforcebridge/pssalesforcebridge.php

echo "==> Starting MySQL"
docker network create "$NETWORK" >/dev/null
docker run -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MYSQL_ROOT_PASSWORD=admin \
  -e MYSQL_DATABASE=prestashop \
  mysql:5.7 --default-authentication-plugin=mysql_native_password >/dev/null

# The official MySQL 5.7 image starts a temporary server while initializing
# the data directory, then shuts it down and launches the final server. A raw
# mysqladmin ping can therefore succeed too early. Wait for the entrypoint's
# completion marker first, then require the final server to answer a real query.
db_init_done=0
for _ in $(seq 1 90); do
  if docker logs "$DB_CONTAINER" 2>&1 | grep -q 'MySQL init process done. Ready for start up.'; then
    db_init_done=1
    break
  fi
  sleep 1
done
if [ "$db_init_done" -ne 1 ]; then
  echo "MySQL initialization did not complete" >&2
  exit 1
fi

db_ready=0
for _ in $(seq 1 60); do
  if docker exec "$DB_CONTAINER" mysql -uroot -padmin --batch --skip-column-names -e 'SELECT 1' 2>/dev/null | grep -qx '1'; then
    db_ready=1
    break
  fi
  sleep 1
done
if [ "$db_ready" -ne 1 ]; then
  echo "MySQL final server did not become ready" >&2
  exit 1
fi

echo "==> Starting PrestaShop ${PS_VERSION}"
docker run -d --name "$PS_CONTAINER" --network "$NETWORK" -p "${PORT}:80" \
  -e DB_SERVER="$DB_CONTAINER" \
  -e DB_NAME=prestashop \
  -e DB_USER=root \
  -e DB_PASSWD=admin \
  -e DB_PREFIX=ps_ \
  -e PS_INSTALL_AUTO=1 \
  -e PS_DOMAIN="localhost:${PORT}" \
  -e PS_HANDLE_DYNAMIC_DOMAIN=1 \
  -e PS_LANGUAGE=en \
  -e PS_COUNTRY=fr \
  -e PS_FOLDER_ADMIN=admin-dev \
  -e PS_FOLDER_INSTALL=install-dev \
  -e PS_DEV_MODE=1 \
  -e ADMIN_MAIL=runtime@example.com \
  -e ADMIN_PASSWD='Runtime123!' \
  "prestashop/prestashop:${PS_VERSION}" >/dev/null

wait_for_shop
ACTUAL_VERSION="$(sql "SELECT value FROM ps_configuration WHERE name='PS_VERSION_DB' ORDER BY id_configuration DESC LIMIT 1")"
if [ "$ACTUAL_VERSION" != "$PS_VERSION" ]; then
  echo "Expected PrestaShop ${PS_VERSION}, got ${ACTUAL_VERSION}" >&2
  exit 1
fi

echo "==> Phase A: real 0.6.1 -> 0.7.0 upgrade"
copy_module /tmp/pssfmc-runtime-061/pssalesforcebridge
console prestashop:module install pssalesforcebridge --no-interaction
INSTALLED_VERSION="$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")"
test "$INSTALLED_VERSION" = '0.6.1'

sql "UPDATE ps_configuration SET value='365' WHERE name='PS_SFMC_RETENTION_QUEUE_DAYS'"
sql "DELETE FROM ps_configuration WHERE name='PS_SFMC_RETENTION_LOG_DAYS'"

copy_module /tmp/pssfmc-runtime-070/pssalesforcebridge
console prestashop:module upgrade pssalesforcebridge --no-interaction
UPGRADED_VERSION="$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")"
test "$UPGRADED_VERSION" = '0.7.0'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_RETENTION_QUEUE_DAYS' LIMIT 1")" = '365'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_RETENTION_LOG_DAYS' LIMIT 1")" = '30'

echo "==> Phase B: clean 0.7.0 installation from exact ZIP"
console prestashop:module uninstall pssalesforcebridge --no-interaction
copy_module /tmp/pssfmc-runtime-070/pssalesforcebridge
console prestashop:module install pssalesforcebridge --no-interaction

docker cp "$ROOT/tests/runtime/prestashop-smoke.php" "$PS_CONTAINER:/tmp/prestashop-smoke.php"
docker exec -e EXPECTED_PS_VERSION="$PS_VERSION" -e PS_ROOT=/var/www/html -w /var/www/html "$PS_CONTAINER" php /tmp/prestashop-smoke.php

echo "==> Verifying protected health and cron endpoints"
TOKEN="$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_CRON_TOKEN' ORDER BY id_configuration DESC LIMIT 1")"
test -n "$TOKEN"

curl -fsS --max-time 10 "http://127.0.0.1:${PORT}/module/pssalesforcebridge/health?token=${TOKEN}" > /tmp/pssfmc-health.json
python3 - <<'PY'
import json
with open('/tmp/pssfmc-health.json', 'r', encoding='utf-8') as fh:
    report = json.load(fh)
assert report['mode'] == 'test', report
assert report['module_version'] == '0.7.0', report
assert report['ok'] is True, report
PY

curl -fsS --max-time 20 "http://127.0.0.1:${PORT}/module/pssalesforcebridge/cron?token=${TOKEN}" > /tmp/pssfmc-cron.json
python3 - <<'PY'
import json
with open('/tmp/pssfmc-cron.json', 'r', encoding='utf-8') as fh:
    report = json.load(fh)
assert report.get('ok') is True, report
payload = report.get('result', report)
serialized = json.dumps(payload)
assert 'test_mode' in serialized, report
assert 'access_token' not in serialized.lower(), report
assert 'client_secret' not in serialized.lower(), report
PY

curl -fsS --max-time 10 "http://127.0.0.1:${PORT}/" >/dev/null

echo "runtime certification: PASS for PrestaShop ${PS_VERSION}"

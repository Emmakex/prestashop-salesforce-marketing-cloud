#!/usr/bin/env bash
set -euo pipefail

PS_VERSION="${1:?PrestaShop version is required}"
BASE_071="${2:-41cfd6bd7517c29056048ee9bb2ceedd7247b119}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NETWORK="pssfmc-${PS_VERSION//./-}-${GITHUB_RUN_ID:-local}-${RANDOM}"
DB_CONTAINER="${NETWORK}-db"
PS_CONTAINER="${NETWORK}-ps"
PORT="8080"
MYSQL_IMAGE="mysql:5.7"
PRESTASHOP_IMAGE="prestashop/prestashop:${PS_VERSION}"

cleanup() {
  set +e
  if docker ps -a --format '{{.Names}}' | grep -qx "$PS_CONTAINER"; then
    echo '--- PrestaShop logs ---'
    # Local CI cron bearer appears in Apache access URLs on failures.
    # Never expose even synthetic bearer values in exported job logs.
    docker logs "$PS_CONTAINER" 2>&1 \
      | sed -E 's/([?&]token=)[^ &"]+/\1[REDACTED]/g' \
      | tail -n 200 || true
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

pull_image() {
  local image="$1"
  local attempt

  for attempt in 1 2 3 4 5; do
    if docker pull "$image"; then
      return 0
    fi

    echo "Docker pull failed for ${image} (attempt ${attempt}/5)" >&2
    sleep $((attempt * 5))
  done

  echo "Unable to pull ${image} after 5 attempts" >&2
  return 1
}

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
  docker exec -u www-data -w /var/www/html "$PS_CONTAINER" php bin/console "$@"
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

fetch_endpoint() {
  local name="$1"
  local url="$2"
  local output="$3"
  local timeout="$4"
  local status

  status="$(curl -sS --max-time "$timeout" -o "$output" -w '%{http_code}' "$url")"
  if [ "$status" != '200' ]; then
    echo "${name} endpoint returned HTTP ${status}" >&2
    echo "--- ${name} response body ---" >&2
    cat "$output" >&2 || true
    echo >&2
    exit 1
  fi
}

echo "==> Preparing historical 0.7.1 fixture and Growth-only 0.9.2"
rm -rf /tmp/pssfmc-runtime-old /tmp/pssfmc-runtime-growth "$ROOT/build/runtime"
mkdir -p /tmp/pssfmc-runtime-old /tmp/pssfmc-runtime-growth "$ROOT/build/runtime"
git -C "$ROOT" archive "$BASE_071" pssalesforcebridge | tar -x -C /tmp/pssfmc-runtime-old
(
  cd "$ROOT"
  zip -qr build/runtime/pssalesforcebridge-0.9.2.zip pssalesforcebridge -x '*/vendor/*' '*.DS_Store'
)
unzip -q "$ROOT/build/runtime/pssalesforcebridge-0.9.2.zip" -d /tmp/pssfmc-runtime-growth
test -f /tmp/pssfmc-runtime-old/pssalesforcebridge/pssalesforcebridge.php
test -f /tmp/pssfmc-runtime-growth/pssalesforcebridge/pssalesforcebridge.php
grep -Fq "\$this->version = '0.7.1';" /tmp/pssfmc-runtime-old/pssalesforcebridge/pssalesforcebridge.php
grep -Fq "\$this->version = '0.9.2';" /tmp/pssfmc-runtime-growth/pssalesforcebridge/pssalesforcebridge.php
test ! -e /tmp/pssfmc-runtime-growth/pssalesforcebridge/src/Salesforce/AuthClient.php
test ! -e /tmp/pssfmc-runtime-growth/pssalesforcebridge/src/Salesforce/RetiredFlowClient.php
test ! -e /tmp/pssfmc-runtime-growth/pssalesforcebridge/controllers/front/recover.php

echo "==> Pulling pinned runtime images"
pull_image "$MYSQL_IMAGE"
pull_image "$PRESTASHOP_IMAGE"

echo "==> Starting MySQL"
docker network create "$NETWORK" >/dev/null
docker run --pull=never -d --name "$DB_CONTAINER" --network "$NETWORK" \
  -e MYSQL_ROOT_PASSWORD=admin \
  -e MYSQL_DATABASE=prestashop \
  "$MYSQL_IMAGE" --default-authentication-plugin=mysql_native_password >/dev/null

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
docker run --pull=never -d --name "$PS_CONTAINER" --network "$NETWORK" -p "${PORT}:80" \
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
  "$PRESTASHOP_IMAGE" >/dev/null

wait_for_shop
ACTUAL_VERSION="$(sql "SELECT value FROM ps_configuration WHERE name='PS_VERSION_DB' ORDER BY id_configuration DESC LIMIT 1")"
if [ "$ACTUAL_VERSION" != "$PS_VERSION" ]; then
  echo "Expected PrestaShop ${PS_VERSION}, got ${ACTUAL_VERSION}" >&2
  exit 1
fi



echo "==> Phase 0: first-time 0.9.2 install on a shop with no legacy module tables"
copy_module /tmp/pssfmc-runtime-growth/pssalesforcebridge
console prestashop:module install pssalesforcebridge --no-interaction
test "$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")" = '0.9.2'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_MODE' ORDER BY id_configuration DESC LIMIT 1")" = 'test'
test "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ps_sfmc_log'")" = '0'
test "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ps_sfmc_queue'")" = '0'
console prestashop:module uninstall pssalesforcebridge --no-interaction
test "$(sql "SELECT COUNT(*) FROM ps_module WHERE name='pssalesforcebridge'")" = '0'

echo "==> Phase A: install 0.7.1 fixture and historical CI marker"
copy_module /tmp/pssfmc-runtime-old/pssalesforcebridge
console prestashop:module install pssalesforcebridge --no-interaction
test "$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")" = '0.7.1'
sql "INSERT INTO ps_sfmc_log (level, event, context, created_at) VALUES ('info', 'CI_PRESERVE_MARKER', '{}', UTC_TIMESTAMP())"
test "$(sql "SELECT COUNT(*) FROM ps_sfmc_log WHERE event='CI_PRESERVE_MARKER'")" = '1'
# Snapshot a safely blocked 0.7.1 shop before simulating an unsafe old mode.
# The rollback restores BOTH DB state and the exact historical code package.
ROLLBACK_SQL="/tmp/pssfmc-rollback-${PS_VERSION//./-}.sql"
docker exec "$DB_CONTAINER" mysqldump -uroot -padmin --single-transaction --quick --skip-lock-tables prestashop > "$ROLLBACK_SQL"
test -s "$ROLLBACK_SQL"
sql "UPDATE ps_configuration SET value='production' WHERE name='PS_SFMC_MODE'"

echo "==> Phase B: upgrade to 0.9.2 with no historical data loss"
copy_module /tmp/pssfmc-runtime-growth/pssalesforcebridge
console prestashop:module upgrade pssalesforcebridge --no-interaction
test "$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")" = '0.9.2'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_MODE' ORDER BY id_configuration DESC LIMIT 1")" = 'test'
test "$(sql "SELECT COUNT(*) FROM ps_sfmc_log WHERE event='CI_PRESERVE_MARKER'")" = '1'
docker cp "$ROOT/tests/runtime/prestashop-smoke.php" "$PS_CONTAINER:/tmp/prestashop-smoke.php"
docker exec -u www-data -e EXPECTED_PS_VERSION="$PS_VERSION" -e PS_ROOT=/var/www/html -w /var/www/html "$PS_CONTAINER" php /tmp/prestashop-smoke.php

echo "==> Phase C: clean Growth-only reinstall, preserve historical DB"
console prestashop:module uninstall pssalesforcebridge --no-interaction
test "$(sql "SELECT COUNT(*) FROM ps_sfmc_log WHERE event='CI_PRESERVE_MARKER'")" = '1'
copy_module /tmp/pssfmc-runtime-growth/pssalesforcebridge
console prestashop:module install pssalesforcebridge --no-interaction
test "$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")" = '0.9.2'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_MODE' ORDER BY id_configuration DESC LIMIT 1")" = 'test'
test "$(sql "SELECT COUNT(*) FROM ps_sfmc_log WHERE event='CI_PRESERVE_MARKER'")" = '1'
docker exec -u www-data -e EXPECTED_PS_VERSION="$PS_VERSION" -e PS_ROOT=/var/www/html -w /var/www/html "$PS_CONTAINER" php /tmp/prestashop-smoke.php

echo "==> Encrypted per-shop OAuth credentials on real disposable PrestaShop Configuration (no network)"
docker cp "$ROOT/tests/runtime/prestashop-shop-credential-smoke.php" "$PS_CONTAINER:/tmp/prestashop-shop-credential-smoke.php"
docker exec -u www-data -e GITHUB_ACTIONS=true -e GROWTH_CRED_RUNTIME_DISPOSABLE=yes -e EXPECTED_PS_VERSION="$PS_VERSION" -e PS_ROOT=/var/www/html -w /var/www/html "$PS_CONTAINER" php /tmp/prestashop-shop-credential-smoke.php

echo "==> Real PrestaShop Db Growth SQL CHECK preflight (MySQL5.7, no new tables)"
docker cp "$ROOT/tests/runtime/prestashop-growth-db-api-contract.php" "$PS_CONTAINER:/tmp/prestashop-growth-db-api-contract.php"
docker exec -u www-data -e GITHUB_ACTIONS=true -e G3_RUNTIME_CI_ONLY=yes_disposable_prestashop -e EXPECTED_PS_VERSION="$PS_VERSION" -e PS_ROOT=/var/www/html -w /var/www/html "$PS_CONTAINER" php /tmp/prestashop-growth-db-api-contract.php

echo "==> Protected Growth-only health/cron endpoints"
TOKEN="$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_CRON_TOKEN' ORDER BY id_configuration DESC LIMIT 1")"
test -n "$TOKEN"
fetch_endpoint 'health' "http://127.0.0.1:${PORT}/module/pssalesforcebridge/health?token=$TOKEN" /tmp/pssfmc-growth-health.json 10
python3 - <<'PY'
import json
with open('/tmp/pssfmc-growth-health.json', encoding='utf-8') as h: d=json.load(h)
assert d['ok'] is True and d['status']=='blocked_test' and d['mode']=='test',d
assert d['module_version']=='0.9.2' and d['provider']=='marketing_cloud_growth',d
assert d['record_ingestion_allowed'] is False and d['marketing_sends_allowed'] is False,d
PY
fetch_endpoint 'cron' "http://127.0.0.1:${PORT}/module/pssalesforcebridge/cron?token=$TOKEN" /tmp/pssfmc-growth-cron.json 10
python3 - <<'PY'
import json
with open('/tmp/pssfmc-growth-cron.json', encoding='utf-8') as h: d=json.load(h)
assert d['ok'] is True and d['status']=='blocked_test',d
assert d['provider']=='marketing_cloud_growth',d
assert d['marketing_sends']['skipped']=='approval_required',d
assert d['data_cloud_ingestion']['skipped']=='schema_required',d
assert 'customer_delivery' not in d and 'retired_flow_delivery' not in d,d
PY
curl -fsS --max-time 10 "http://127.0.0.1:${PORT}/" >/dev/null

echo "==> Phase D: restore 0.7.1 from pre-upgrade DB backup and exact historical files"
copy_module /tmp/pssfmc-runtime-old/pssalesforcebridge
docker exec -i "$DB_CONTAINER" mysql -uroot -padmin prestashop < "$ROLLBACK_SQL"
test "$(sql "SELECT version FROM ps_module WHERE name='pssalesforcebridge' LIMIT 1")" = '0.7.1'
test "$(sql "SELECT value FROM ps_configuration WHERE name='PS_SFMC_MODE' ORDER BY id_configuration DESC LIMIT 1")" = 'test'
test "$(sql "SELECT COUNT(*) FROM ps_sfmc_log WHERE event='CI_PRESERVE_MARKER'")" = '1'
docker exec -u www-data -w /var/www/html "$PS_CONTAINER" php -r '
require "config/config.inc.php";
$m = Module::getInstanceByName("pssalesforcebridge");
if (!is_object($m) || $m->version !== "0.7.1"
    || Configuration::get("PS_SFMC_MODE") !== "test") {
    fwrite(STDERR, "Rollback did not restore 0.7.1 in Test\\n");
    exit(1);
}
'
echo "Rollback of historical 0.7.1 from snapshot PASS: Test gate and records preserved."
echo "Growth-only 0.9.2 upgrade, clean install and rollback PASS on PrestaShop $PS_VERSION"

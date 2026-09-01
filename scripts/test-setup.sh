#!/usr/bin/env bash
#
# Bring the Pest test database up.
#
# The suite reads phpunit.xml, not .env. The database named here must match
# the `DB_DATABASE` value in that file (`appoint_manager_test`). It is never
# the development database (`appoint_manager`) and never the e2e database
# (`appoint_manager_e2e`).
#
# Parallel Pest workers then create `appoint_manager_test_test_1`, `_2`, …
# themselves via Laravel's ParallelTesting — this script only needs the base
# schema to exist so a serial `./vendor/bin/pest` has somewhere to migrate.
#
set -euo pipefail

DB="${TEST_DB_DATABASE:-appoint_manager_test}"
USER="${TEST_DB_USERNAME:-root}"
PASS="${TEST_DB_PASSWORD:-}"
HOST="${TEST_DB_HOST:-127.0.0.1}"
PORT="${TEST_DB_PORT:-3306}"

mysql_args=(-h "$HOST" -P "$PORT" -u "$USER")
[ -n "$PASS" ] && mysql_args+=("-p$PASS")

if ! mysqladmin "${mysql_args[@]}" ping >/dev/null 2>&1; then
    echo "test-setup: MySQL is not reachable at $HOST:$PORT."
    echo "test-setup: start it with \`docker compose up -d\`, or run \`./scripts/test-mysql84.sh\` for 8.4 on 33084."
    echo "test-setup: do not point the suite at SQLite — lockForUpdate() is a no-op there."
    exit 1
fi

echo "test-setup: ensuring $DB exists"
mysql "${mysql_args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# A fresh clone has no schema. migrate:fresh against an empty database is what
# RefreshDatabase will do on the first test anyway; running it here means a
# failed first-test migrate is a setup problem, not a red herring mid-suite.
export DB_CONNECTION=mysql
export DB_HOST="$HOST"
export DB_PORT="$PORT"
export DB_DATABASE="$DB"
export DB_USERNAME="$USER"
export DB_PASSWORD="$PASS"
export APP_ENV=testing

echo "test-setup: migrating $DB"
php artisan migrate --force --no-interaction

echo "test-setup: $DB is ready"

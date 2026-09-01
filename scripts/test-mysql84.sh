#!/usr/bin/env bash
#
# Run the Pest suite against MySQL 8.4, serial then parallel.
#
# Production is 8. A laptop whose brew install is 9.x is a local deviation.
# This is the one command that makes the 8.4 claim verifiable on demand:
#
#   ./scripts/test-mysql84.sh
#
# It listens on 33084 so a mysqld already bound to 3306 is left alone.
# phpunit.xml defaults to 3306 and does not force the port, so DB_PORT here
# is what the suite actually opens.
#
# Prefers Docker (`docker-compose.mysql84.yml`, image mysql:8.4). If docker
# is not on PATH, falls back to a keg-only `mysql@8.4` with its own datadir
# — never `brew services start mysql@8.4`, which would point 8.4 at the 9.x
# datadir. Charset and collation match docker-compose.yml
# (utf8mb4 / utf8mb4_unicode_ci).
#
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${TEST_DB_PORT:-33084}"
export TEST_DB_PORT="$PORT"
export DB_PORT="$PORT"
export DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
export DB_USERNAME="${TEST_DB_USERNAME:-root}"
export DB_PASSWORD="${TEST_DB_PASSWORD:-}"
export DB_CONNECTION=mysql
export DB_DATABASE=appoint_manager_test

mysql_ping() {
    mysqladmin -h "$DB_HOST" -P "$PORT" -u "$DB_USERNAME" ${DB_PASSWORD:+-p$DB_PASSWORD} ping >/dev/null 2>&1
}

mysql_client() {
    mysql -h "$DB_HOST" -P "$PORT" -u "$DB_USERNAME" ${DB_PASSWORD:+-p$DB_PASSWORD} "$@"
}

start_docker_mysql84() {
    if ! command -v docker >/dev/null 2>&1; then
        return 1
    fi

    echo "test-mysql84: bringing up mysql:8.4 via docker on ${DB_HOST}:${PORT}"
    if ! docker compose -p appoint-manager-mysql84 -f docker-compose.mysql84.yml up -d --wait; then
        echo "test-mysql84: --wait not supported; starting and polling"
        docker compose -p appoint-manager-mysql84 -f docker-compose.mysql84.yml up -d
        for _ in $(seq 1 40); do
            mysql_ping && return 0
            sleep 2
        done
        return 1
    fi
    mysql_ping
}

start_brew_mysql84() {
    local prefix datadir socket pidfile
    if ! command -v brew >/dev/null 2>&1; then
        return 1
    fi
    prefix="$(brew --prefix mysql@8.4 2>/dev/null || true)"
    if [ -z "$prefix" ] || [ ! -x "$prefix/bin/mysqld" ]; then
        return 1
    fi

    datadir="${MYSQL84_DATADIR:-$(brew --prefix)/var/appoint-manager-mysql84}"
    socket="${MYSQL84_SOCKET:-$datadir/mysql.sock}"
    pidfile="${MYSQL84_PIDFILE:-$datadir/mysqld.pid}"

    echo "test-mysql84: docker not on PATH; using keg mysql@8.4 at ${prefix}"
    echo "test-mysql84: isolated datadir ${datadir} (9.x on 3306 is not touched)"

    if [ ! -d "$datadir/mysql" ]; then
        mkdir -p "$datadir"
        "$prefix/bin/mysqld" --initialize-insecure --datadir="$datadir"
    fi

    if mysql_ping; then
        return 0
    fi

    "$prefix/bin/mysqld" \
        --datadir="$datadir" \
        --port="$PORT" \
        --socket="$socket" \
        --pid-file="$pidfile" \
        --character-set-server=utf8mb4 \
        --collation-server=utf8mb4_unicode_ci \
        --bind-address=127.0.0.1 \
        --mysqlx=0 \
        --daemonize

    for _ in $(seq 1 40); do
        mysql_ping && return 0
        sleep 1
    done
    return 1
}

echo "test-mysql84: bringing up MySQL 8.4 on ${DB_HOST}:${PORT}"
if mysql_ping; then
    echo "test-mysql84: already listening on ${PORT}"
elif start_docker_mysql84; then
    echo "test-mysql84: docker 8.4 is up"
elif start_brew_mysql84; then
    echo "test-mysql84: brew 8.4 is up"
else
    echo "test-mysql84: no 8.4 server on ${PORT}." >&2
    echo "test-mysql84: install Docker and rerun, or \`brew install mysql@8.4\` (keg-only; this script will not run brew services)." >&2
    exit 1
fi

VERSION="$(mysql_client -N -e 'SELECT VERSION();')"
echo "test-mysql84: server reports ${VERSION}"

case "$VERSION" in
    8.4.*) ;;
    *)
        echo "test-mysql84: expected 8.4.x, got ${VERSION}." >&2
        echo "test-mysql84: the suite on this port is not the production engine." >&2
        exit 1
        ;;
esac

./scripts/test-setup.sh

echo "test-mysql84: Pest serial"
./vendor/bin/pest

echo "test-mysql84: Pest parallel"
./vendor/bin/pest --parallel

echo "test-mysql84: ${VERSION} serial and parallel both finished"

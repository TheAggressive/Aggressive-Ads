#!/usr/bin/env bash
#
# A disposable MySQL for the WordPress test suites, without Docker and without
# sudo.
#
# This never touches the system database. It initializes its own datadir under
# .cache/ci/mysql and listens on a non-default port, so a masked or running
# mysql.service is neither required nor disturbed — the same disposability the
# Compose stack provides, from a server that is already installed.
#
# The socket lives in /tmp rather than beside the datadir because a Unix socket
# path is capped near 107 bytes and a checkout nested a few directories deep
# blows through that. The failure is a connection error that names a truncated
# path, which reads like anything but a length limit.
#
# Usage: mysql.sh start|stop|status|destroy

set -euo pipefail

cd "$(dirname "$0")/../.."

DATA_ROOT="${AGGR_TESTS_MYSQL_DIR:-.cache/ci/mysql}"
DATA_DIR="${DATA_ROOT}/data"
PID_FILE="${DATA_ROOT}/mysqld.pid"
LOG_FILE="${DATA_ROOT}/error.log"
PORT="${AGGR_TESTS_DB_PORT:-13306}"
SOCKET="/tmp/aggr-mysqld-$(id -u)-${PORT}.sock"

DB_NAME="${AGGR_TESTS_DB_NAME:-wordpress_test}"
DB_USER="${AGGR_TESTS_DB_USER:-wordpress}"
DB_PASSWORD="${AGGR_TESTS_DB_PASSWORD:-wordpress}"

find_mysqld() {
	local candidate
	for candidate in /usr/sbin/mysqld /usr/sbin/mariadbd /usr/local/mysql/bin/mysqld; do
		[ -x "${candidate}" ] && { printf '%s\n' "${candidate}"; return 0; }
	done
	command -v mysqld 2>/dev/null && return 0
	command -v mariadbd 2>/dev/null && return 0
	return 1
}

# Reads SQL on stdin. Deliberately takes no arguments: the only caller feeds it
# a heredoc, and a passthrough nobody uses is a passthrough nobody checks.
client() {
	mysql --protocol=TCP --host=127.0.0.1 --port="${PORT}" --user=root
}

is_up() {
	mysqladmin --protocol=TCP --host=127.0.0.1 --port="${PORT}" \
		--user=root ping >/dev/null 2>&1
}

start() {
	if is_up; then
		echo "local-mysql: already listening on 127.0.0.1:${PORT}"
		return 0
	fi

	local mysqld
	if ! mysqld="$(find_mysqld)"; then
		echo "local-mysql: no mysqld found." >&2
		echo "Install one (sudo apt-get install mysql-server) and re-run." >&2
		echo "The service itself may stay masked — this never uses it." >&2
		exit 1
	fi

	mkdir -p "${DATA_ROOT}"

	# An empty datadir is the only state --initialize-insecure accepts, so the
	# marker is the mysql schema directory rather than the datadir itself.
	if [ ! -d "${DATA_DIR}/mysql" ]; then
		echo "local-mysql: initializing ${DATA_DIR}"
		rm -rf "${DATA_DIR}"
		mkdir -p "${DATA_DIR}"
		"${mysqld}" --initialize-insecure \
			--datadir="$(pwd -P)/${DATA_DIR}" \
			--basedir=/usr \
			--log-error="$(pwd -P)/${LOG_FILE}"
	fi

	echo "local-mysql: starting on 127.0.0.1:${PORT}"
	"${mysqld}" \
		--datadir="$(pwd -P)/${DATA_DIR}" \
		--basedir=/usr \
		--socket="${SOCKET}" \
		--port="${PORT}" \
		--bind-address=127.0.0.1 \
		--pid-file="$(pwd -P)/${PID_FILE}" \
		--log-error="$(pwd -P)/${LOG_FILE}" \
		--mysqlx=0 \
		>/dev/null 2>&1 &

	local waited=0
	while [ "${waited}" -lt 60 ]; do
		is_up && break
		sleep 1
		waited=$(( waited + 1 ))
	done

	if ! is_up; then
		echo "local-mysql: did not become ready within ${waited}s." >&2
		tail -n 15 "${LOG_FILE}" >&2 2>/dev/null || true
		exit 1
	fi

	# Idempotent: the suites drop and recreate every table they use, so the
	# schema only has to exist and be writable by the test user.
	client <<-SQL
		CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\`;
		CREATE USER IF NOT EXISTS '${DB_USER}'@'%' IDENTIFIED BY '${DB_PASSWORD}';
		GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
		FLUSH PRIVILEGES;
	SQL

	echo "local-mysql: ready ($("${mysqld}" --version | awk '{print $3}'))"
}

stop() {
	if ! is_up; then
		echo "local-mysql: not running"
		return 0
	fi

	mysqladmin --protocol=TCP --host=127.0.0.1 --port="${PORT}" \
		--user=root shutdown >/dev/null 2>&1 || true

	local waited=0
	while [ "${waited}" -lt 30 ] && is_up; do
		sleep 1
		waited=$(( waited + 1 ))
	done

	rm -f "${SOCKET}"
	echo "local-mysql: stopped"
}

case "${1:-start}" in
	start) start ;;
	stop) stop ;;
	status)
		if is_up; then echo "local-mysql: up on 127.0.0.1:${PORT}"; else echo "local-mysql: down"; exit 1; fi
		;;
	destroy)
		stop
		rm -rf "${DATA_ROOT}"
		echo "local-mysql: removed ${DATA_ROOT}"
		;;
	*)
		echo "usage: mysql.sh start|stop|status|destroy" >&2
		exit 2
		;;
esac

#!/usr/bin/env bash
# Manage the one disposable WordPress environment used locally and in CI.

set -euo pipefail

cd "$(dirname "$0")/../.."

action="${1:?usage: environment.sh <start|stop|logs|exec|wp> [arguments...]}"
shift

project="${AGGR_COMPOSE_PROJECT:-aggressive-ads}"
port="${AGGR_WP_PORT:-9960}"
wp_user="${AGGR_WP_USER:-www-data}"
compose=( docker compose --project-name "${project}" --file compose.yml )
wp_options=()

if [ "${wp_user}" = "root" ]; then
	wp_options=( --allow-root )
fi

mkdir -p .cache/ci/artifacts-empty

case "${action}" in
	start)
		"${compose[@]}" up --detach --build --wait

		if ! "${compose[@]}" exec -T --user "${wp_user}" -e "HTTP_HOST=localhost:${port}" wordpress wp core is-installed "${wp_options[@]}"; then
			"${compose[@]}" exec -T --user "${wp_user}" -e "HTTP_HOST=localhost:${port}" wordpress wp core install \
				--url="http://localhost:${port}" \
				--title='Aggressive Ads Tests' \
				--admin_user=admin \
				--admin_password=password \
				--admin_email=admin@example.test \
				--skip-email \
				"${wp_options[@]}"
		fi

		if [ "${AGGR_SKIP_PLUGIN_ACTIVATION:-0}" != "1" ]; then
			"${compose[@]}" exec -T --user "${wp_user}" -e "HTTP_HOST=localhost:${port}" wordpress wp plugin activate aggressive-ads "${wp_options[@]}"
		fi
		;;
	stop)
		"${compose[@]}" down --volumes --remove-orphans
		;;
	logs)
		"${compose[@]}" logs "$@"
		;;
	exec)
		"${compose[@]}" exec -T wordpress "$@"
		;;
	wp)
		if [ "${1:-}" = "--" ]; then
			shift
		fi
		"${compose[@]}" exec -T --user "${wp_user}" -e "HTTP_HOST=localhost:${port}" wordpress wp "${wp_options[@]}" "$@"
		;;
	*)
		echo "Unknown environment action: ${action}" >&2
		exit 2
		;;
esac

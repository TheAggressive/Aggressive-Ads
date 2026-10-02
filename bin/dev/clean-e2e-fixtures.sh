#!/usr/bin/env bash
#
# Remove what the browser suite and the dev seed left on a Studio site.
#
#   pnpm e2e:clean <site path>          report only
#   pnpm e2e:clean <site path> --yes    back up, then delete
#
# For a site the suite ran against before it had a disposable one of its own
# (`pnpm e2e:site`). What counts as a fixture, and why each rule is safe, is in
# clean-e2e-fixtures.php; this script's job is the backup. With --yes it copies
# the database into <site>/.aggr-backups/clean-<time>/ before anything is
# deleted, and the PHP copies each file there just before removing it. The PHP
# refuses to delete without that directory, so the backup cannot be skipped by
# calling it directly.
#
# The site path is required, never discovered. Discovery picks "the site that
# serves this checkout", which is exactly how the suite ended up seeding a
# working site in the first place.

set -euo pipefail

cd "$(dirname "$0")/../.."

site=""
confirm=0

for arg in "$@"; do
	case "${arg}" in
		--yes) confirm=1 ;;
		-*)
			echo "clean-e2e-fixtures: unknown option ${arg}" >&2
			exit 2
			;;
		*) site="${arg}" ;;
	esac
done

if [[ -z "${site}" ]]; then
	echo "usage: pnpm e2e:clean <studio site path> [--yes]" >&2
	exit 2
fi

site="$(realpath "${site}")"

if ! studio status --path "${site}" >/dev/null 2>&1; then
	echo "clean-e2e-fixtures: ${site} is not a Studio site." >&2
	exit 1
fi

# The served copy of the plugin, so the cleanup speaks the same constants as
# the code that wrote the rows.
script='require WP_PLUGIN_DIR . "/aggressive-ads/bin/dev/clean-e2e-fixtures.php";'

if [[ "${confirm}" -eq 0 ]]; then
	studio wp --path "${site}" eval "${script}"
	echo
	echo "clean-e2e-fixtures: nothing was deleted. Re-run with --yes to back up and delete."
	exit 0
fi

backup="${site}/.aggr-backups/clean-$(date +%Y%m%d-%H%M%S)"
mkdir -p "${backup}"

database="${site}/wp-content/database/.ht.sqlite"

if [[ -f "${database}" ]]; then
	# sqlite3's online backup when it is installed, so a write landing during
	# the copy cannot tear it; a plain copy otherwise.
	if command -v sqlite3 >/dev/null 2>&1; then
		sqlite3 "${database}" ".backup '${backup}/ht.sqlite'"
	else
		cp "${database}" "${backup}/ht.sqlite"
	fi
else
	studio wp --path "${site}" db export "${backup}/database.sql" >/dev/null
fi

echo "clean-e2e-fixtures: database backed up to ${backup}"

AGGR_CLEAN_CONFIRM=1 AGGR_CLEAN_BACKUP="${backup}" studio wp --path "${site}" eval "${script}"

echo "clean-e2e-fixtures: to undo, stop the site, copy ${backup}/ht.sqlite back to"
echo "  ${database}, and copy ${backup}/uploads and ${backup}/ads-uploads back"
echo "  under wp-content/uploads."

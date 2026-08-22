#!/usr/bin/env bash
#
# Run the browser suite against the Studio site that serves this checkout.
#
# This is not a disposable container. The suite mutates the site it runs
# against, and two of those mutations are not reversible by anything here:
#
#   * tests/e2e/seed-users.php resets the `admin` and `advertiser` passwords to
#     match the fixtures, so whatever those accounts used before is gone — and
#     Studio's own stored admin password no longer opens wp-admin;
#   * bin/dev/seed.php and tests/e2e/seed-mappings.php write fixture campaigns,
#     an organization and a placement.
#
# Everything else — theme, home, siteurl, permalink structure, the mail-capture
# mu-plugin — is captured up front and restored on the way out, on success and
# on failure alike.
#
# Because discovery aims all of that at whichever site happens to serve this
# checkout, the site has to say yes first: touch .aggr-e2e-site in its root, or
# export AGGR_STUDIO_E2E_ALLOW=1 for one run.

set -euo pipefail

cd "$(dirname "$0")/../.."

repo_root="$(pwd -P)"
requested_path="${AGGR_STUDIO_PATH:-}"

if ! command -v studio >/dev/null 2>&1; then
	echo "studio-e2e: Studio CLI is not installed or not on PATH." >&2
	echo "Enable Studio CLI in Studio Settings, then re-run." >&2
	exit 1
fi

# No machine-specific site path or port belongs in the repository. Select the
# site whose plugin directory resolves to this checkout, unless its path was
# supplied explicitly, and read the localhost URL assigned by Studio.
#
# The listing goes to node on stdin rather than through the environment: it
# carries every site's stored admin password, and a child process environment is
# readable from /proc and echoed by any `set -x`.
sites="$(studio site list --format=json)"

mapfile -t discovery < <(
	printf '%s' "${sites}" \
		| AGGR_PLUGIN_ROOT="${repo_root}" \
			AGGR_STUDIO_REQUESTED="${requested_path}" \
			node -e '
				const fs = require("node:fs");
				const path = require("node:path");
				const parsed = JSON.parse(fs.readFileSync(0, "utf8"));
				const sites = Array.isArray(parsed) ? parsed : parsed.sites ?? [];
				const root = fs.realpathSync(process.env.AGGR_PLUGIN_ROOT);
				const requested = process.env.AGGR_STUDIO_REQUESTED;

				const real = (target) => {
					try {
						return fs.realpathSync(target);
					} catch {
						return null;
					}
				};

				const pathOf = (site) =>
					site.path ?? site.sitePath ?? site.localPath ?? null;

				const wanted = requested ? real(requested) : null;

				if (requested && null === wanted) {
					process.stdout.write("missing\n" + requested + "\n");
					process.exit(0);
				}

				const matches = sites.filter((site) => {
					const sitePath = pathOf(site);

					if (!sitePath) {
						return false;
					}

					if (wanted) {
						return real(sitePath) === wanted;
					}

					return (
						real(path.join(sitePath, "wp-content/plugins/aggressive-ads")) ===
						root
					);
				});

				if (1 === matches.length) {
					const match = matches[0];

					/*
					 * The url Studio reports, never one assembled here.
					 *
					 * This used to fall back to "http://localhost:" + port,
					 * which quietly produces the wrong scheme for a site with
					 * enableHttps set — and a base URL that is wrong in the
					 * scheme fails every spec for a reason none of them name.
					 * If Studio does not say, this script does not guess.
					 */
					process.stdout.write(
						(match.url ? "ok" : "nourl") +
							"\n" +
							pathOf(match) +
							"\n" +
							(match.url ?? "") +
							"\n"
					);
					process.exit(0);
				}

				if (matches.length > 1) {
					process.stdout.write(
						"ambiguous\n" + matches.map(pathOf).join("\n") + "\n"
					);
					process.exit(0);
				}

				process.stdout.write("none\n");
			'
)

case "${discovery[0]:-}" in
	ok)
		site_path="${discovery[1]}"
		base_url="${AGGR_STUDIO_URL:-${discovery[2]}}"
		;;
	nourl)
		site_path="${discovery[1]}"
		base_url="${AGGR_STUDIO_URL:-}"

		if [[ -z "${base_url}" ]]; then
			echo "studio-e2e: Studio reported no URL for ${discovery[1]}." >&2
			echo "Start the site in Studio so it is assigned one, or set" >&2
			echo "AGGR_STUDIO_URL to the address it serves." >&2
			exit 1
		fi
		;;
	ambiguous)
		echo "studio-e2e: ${#discovery[@]} Studio sites serve this checkout:" >&2
		printf '  %s\n' "${discovery[@]:1}" >&2
		echo "Set AGGR_STUDIO_PATH to the one you mean." >&2
		exit 1
		;;
	missing)
		echo "studio-e2e: AGGR_STUDIO_PATH does not exist: ${discovery[1]}" >&2
		exit 1
		;;
	none)
		echo "studio-e2e: no Studio site serves this checkout." >&2
		echo "Symlink this directory as that site's wp-content/plugins/aggressive-ads," >&2
		echo "or set AGGR_STUDIO_PATH to the Studio site root." >&2
		exit 1
		;;
	*)
		echo "studio-e2e: could not read the Studio site list." >&2
		exit 1
		;;
esac

if [[ ! "${base_url}" =~ ^https?:// ]]; then
	echo "studio-e2e: Studio returned an invalid home URL: ${base_url}" >&2
	exit 1
fi

# The site consents, or nothing runs. See the header for what is irreversible.
if [[ "${AGGR_STUDIO_E2E_ALLOW:-}" != "1" && ! -e "${site_path}/.aggr-e2e-site" ]]; then
	cat >&2 <<-CONSENT
		studio-e2e: ${site_path} has not opted in to browser testing.

		The suite will reset the admin and advertiser passwords on that site and
		seed fixture campaigns. Neither is undone afterwards.

		If it is a site you are willing to lose:

		  touch "${site_path}/.aggr-e2e-site"

		or export AGGR_STUDIO_E2E_ALLOW=1 for a single run.
	CONSENT
	exit 1
fi

# stdout is dropped, stderr is not. `studio site start` prints the site's admin
# username and password on success, and this script's output ends up in qa:local
# logs that get pasted into issues.
studio site start --path "${site_path}" >/dev/null

served_plugin="$(
	studio wp --path "${site_path}" eval \
		'echo realpath( WP_PLUGIN_DIR . "/aggressive-ads" );' | tr -d '\r\n'
)"

if [[ "${served_plugin}" != "${repo_root}" ]]; then
	echo "studio-e2e: ${site_path} is not serving this checkout." >&2
	echo "Expected: ${repo_root}" >&2
	echo "Actual:   ${served_plugin:-missing plugin}" >&2
	exit 1
fi

original_theme="$(studio wp --path "${site_path}" option get stylesheet | tr -d '\r\n')"

# global-setup.ts rewrites this with `--hard`, so it is as much this script's to
# put back as the theme and the URLs are.
original_permalinks="$(studio wp --path "${site_path}" option get permalink_structure | tr -d '\r\n')"

mail_fixture="${repo_root}/tests/fixtures/mu-plugins/dev-mail-sender.php"
mail_link="${site_path}/wp-content/mu-plugins/aggr-e2e-mail-capture.php"
remove_mail_link=0

cleanup() {
	status=$?
	cleanup_failed=0
	trap - EXIT
	set +e

	current_theme="$(studio wp --path "${site_path}" option get stylesheet 2>/dev/null | tr -d '\r\n')"

	if [[ "${current_theme}" != "${original_theme}" ]]; then
		studio wp --path "${site_path}" theme activate "${original_theme}" >/dev/null || cleanup_failed=1
	fi

	studio wp --path "${site_path}" option update permalink_structure "${original_permalinks}" >/dev/null || cleanup_failed=1
	studio wp --path "${site_path}" rewrite flush --hard >/dev/null || cleanup_failed=1

	if [[ "${remove_mail_link}" -eq 1 ]]; then
		rm -f "${mail_link}" || cleanup_failed=1
	fi

	if [[ "${status}" -eq 0 && "${cleanup_failed}" -ne 0 ]]; then
		echo "studio-e2e: the suite passed but the site was not fully restored." >&2
		status=1
	fi

	exit "${status}"
}
trap cleanup EXIT

if [[ -e "${mail_link}" || -L "${mail_link}" ]]; then
	if [[ "$(realpath "${mail_link}" 2>/dev/null)" != "${mail_fixture}" ]]; then
		echo "studio-e2e: refusing to replace ${mail_link}." >&2
		echo "It is not a link to ${mail_fixture}." >&2
		exit 1
	fi
else
	mkdir -p "$(dirname "${mail_link}")"
	ln -s "${mail_fixture}" "${mail_link}"
	remove_mail_link=1
fi

# home and siteurl are set from Studio and left that way, unlike the theme and
# the permalink structure, which are put back.
#
# They are not this script's to restore, because the value it would restore is
# not knowably right: Studio assigns the port and can reassign it, so a stored
# URL captured before a run can be stale by the next one. This site stored
# https://laartsonline.local, which resolved to 127.0.0.1 with nothing listening
# on 443 — reachable only for the length of a test run, and "restored" to
# unreachable afterwards.
#
# Following Studio is safe rather than merely convenient: a custom hostname for
# a Studio site is configured in Studio, so `studio site list` reports it and
# this picks it up on the next run. There is no arrangement where the right
# address is one Studio does not know about, which is why nothing here is a
# literal. AGGR_STUDIO_URL stays as the narrow fallback for a site Studio
# reports no URL for at all.
studio wp --path "${site_path}" option update home "${base_url}" >/dev/null
studio wp --path "${site_path}" option update siteurl "${base_url}" >/dev/null

echo "studio-e2e: ${base_url} (${site_path})"
echo "studio-e2e: home and siteurl now follow Studio; theme and permalinks are restored."

AGGR_E2E_BASE_URL="${base_url}" \
	AGGR_E2E_WP_PATH="${site_path}" \
	pnpm test:e2e "$@"

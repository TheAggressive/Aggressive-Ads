#!/usr/bin/env bash
#
# Create the Studio site the browser suite runs against, and nothing else does.
#
#   pnpm e2e:site                  ~/Studio/aggr-e2e
#   AGGR_E2E_SITE_PATH=… pnpm e2e:site
#
# The suite resets the admin and advertiser passwords and seeds campaigns,
# organizations and placements, and none of that is undone (see
# studio-e2e.sh). It used to run against whichever Studio site served this
# checkout, which on the machine that found this was the working site: about
# two hundred fixture campaigns, forty of them written into a real
# organization, and a reset admin password.
#
# So the suite gets a site of its own. This creates one, points its plugin
# directory at this checkout, and marks it disposable *in its database*
# (`aggr_e2e_disposable`). studio-e2e.sh refuses any site without that option,
# so the old opt-ins — a `.aggr-e2e-site` file or AGGR_STUDIO_E2E_ALLOW=1 — are
# no longer enough to aim the suite at a site somebody works in.
#
# `.aggr-e2e-disposable` records that this script made the site; it is what
# lets a second run repair it, and what studio-e2e.sh prefers when several
# sites serve the checkout. The database option is what consents.
#
# Idempotent: run it again to repair the link or the marks.

set -euo pipefail

cd "$(dirname "$0")/../.."

repo_root="$(pwd -P)"
site_path="${AGGR_E2E_SITE_PATH:-${HOME}/Studio/aggr-e2e}"

if ! command -v studio >/dev/null 2>&1; then
	echo "e2e-site: Studio CLI is not installed or not on PATH." >&2
	exit 1
fi

if [[ -e "${site_path}" && ! -e "${site_path}/.aggr-e2e-disposable" ]]; then
	cat >&2 <<-REFUSE
		e2e-site: ${site_path} already exists and was not made by this script.

		This only creates new sites; it never marks an existing one disposable,
		because the mark is what lets the suite overwrite it. (The older
		.aggr-e2e-site opt-in file does not count.) Choose another path with
		AGGR_E2E_SITE_PATH.
	REFUSE
	exit 1
fi

if [[ ! -e "${site_path}" ]]; then
	# stdout dropped: Studio prints the generated admin password on success,
	# and this output lands in logs.
	studio create \
		--name "Aggressive Ads E2E" \
		--path "${site_path}" \
		--skip-browser \
		--skip-log-details >/dev/null
fi

# The mark goes down first, so a run that dies below is still recognisably
# this script's site the next time and is repaired rather than refused.
touch "${site_path}/.aggr-e2e-disposable"

studio start --path "${site_path}" --skip-browser --skip-log-details >/dev/null

plugin_link="${site_path}/wp-content/plugins/aggressive-ads"

relinked=0

if [[ -L "${plugin_link}" ]]; then
	if [[ "$(realpath "${plugin_link}" 2>/dev/null)" != "${repo_root}" ]]; then
		ln -sfn "${repo_root}" "${plugin_link}"
		relinked=1
	fi
elif [[ -e "${plugin_link}" ]]; then
	echo "e2e-site: ${plugin_link} exists and is not a link to this checkout." >&2
	exit 1
else
	ln -s "${repo_root}" "${plugin_link}"
	relinked=1
fi

# Studio confines PHP to the site directory (open_basedir) plus the targets of
# the links it finds *when the server starts*. A link made or repointed while
# the site runs is outside that list until a restart, and every request then
# opens with "open_basedir restriction in effect" for aggressive-ads.php —
# CLI calls, which this script checks with, are not confined and still pass.
if [[ "${relinked}" -eq 1 ]]; then
	studio stop --path "${site_path}" >/dev/null
	studio start --path "${site_path}" --skip-browser --skip-log-details >/dev/null
fi

served="$(
	studio wp --path "${site_path}" eval \
		'echo realpath( WP_PLUGIN_DIR . "/aggressive-ads" );' | tr -d '\r\n'
)"

if [[ "${served}" != "${repo_root}" ]]; then
	echo "e2e-site: the site does not see this checkout through the link." >&2
	echo "Expected: ${repo_root}" >&2
	echo "Actual:   ${served:-nothing}" >&2
	exit 1
fi

studio wp --path "${site_path}" plugin activate aggressive-ads >/dev/null
studio wp --path "${site_path}" option update aggr_e2e_disposable 1 >/dev/null

# Checked over HTTP, because that is where the confinement applies: the CLI
# check above passes on a site whose web server cannot load the plugin.
site_url="$(studio wp --path "${site_path}" option get siteurl | tr -d '\r\n')"

login_page="$(curl -sS "${site_url}/wp-login.php" 2>&1)" || {
	echo "e2e-site: ${site_url} did not answer: ${login_page}" >&2
	exit 1
}

if grep -q "open_basedir" <<< "${login_page}"; then
	echo "e2e-site: the web server cannot read this checkout (open_basedir)." >&2
	echo "Restart the site in Studio and run this again." >&2
	exit 1
fi

# The runner starts it for a run and stops it after; it has no reason to be up
# in between.
studio site stop --path "${site_path}" >/dev/null

echo "e2e-site: ${site_path} serves this checkout and is marked disposable."
echo "e2e-site: run the suite with: pnpm test:e2e:studio"

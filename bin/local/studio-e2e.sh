#!/usr/bin/env bash
#
# Run the browser suite against the Studio site that serves this checkout.
#
# Whatever the suite does to the site is undone afterwards, as a whole: the
# database is snapshotted before Playwright starts and copied back when it
# ends, and every upload the run added is deleted (bin/local/e2e-snapshot.mjs).
# The specs and seeds no longer have to clean up after themselves for the site
# to come back clean, which they never reliably did — see that file.
#
# The run itself is still destructive while it lasts: the seeds reset the
# `admin` and `advertiser` passwords, switch the theme and rewrite permalinks.
# A site it runs on is unusable for anything else until the restore.
#
# One run holds a site at a time (bin/local/e2e-lock.mjs); a second is refused
# rather than allowed to restore the site under the first. The site is stopped
# for every restore, and left running afterwards only if it was running before,
# so the suite's site is up only while the suite is.
#
# So it runs only against a site made for it. `pnpm e2e:site` creates one and
# marks it disposable in its own database (the `aggr_e2e_disposable` option);
# this refuses any site without that mark. A file in the site root or an
# environment variable used to be enough, and that is how the suite came to
# seed about two hundred campaigns into a working site — forty of them into a
# real organization — and reset its admin password. A mark that only the
# creating script sets cannot be given to a working site by accident.
#
# When several Studio sites serve this checkout (the working site and the
# disposable one, typically), the one carrying .aggr-e2e-disposable — written
# only by `pnpm e2e:site` — is chosen. The old opt-in file, .aggr-e2e-site,
# means nothing now; a working site may still carry one.

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

				const candidates = sites.filter((site) => {
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

				/*
				 * The working site serves this checkout too. Of several, the
				 * one made for the suite wins; whether it really is disposable
				 * is checked against its database below, not taken from this.
				 */
				const marked = candidates.filter((site) =>
					fs.existsSync(path.join(pathOf(site), ".aggr-e2e-disposable"))
				);
				const matches =
					candidates.length > 1 && 1 === marked.length ? marked : candidates;

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
							"\n" +
							(match.running ? "running" : "stopped") +
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
		site_state="${discovery[3]:-stopped}"
		;;
	nourl)
		site_path="${discovery[1]}"
		base_url="${AGGR_STUDIO_URL:-}"
		site_state="${discovery[3]:-stopped}"

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
		echo "Set AGGR_STUDIO_PATH to the one you mean, or create the suite's" >&2
		echo "own site with: pnpm e2e:site" >&2
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

# The site is disposable, by its own database's say-so, or nothing runs. Set
# only by `pnpm e2e:site`, which creates the site it marks; see the header for
# why a file or an environment variable no longer counts.
disposable="$(
	studio wp --path "${site_path}" option get aggr_e2e_disposable 2>/dev/null | tr -d '\r\n' || true
)"

if [[ "${disposable}" != "1" ]]; then
	cat >&2 <<-REFUSE
		studio-e2e: ${site_path} is not a disposable test site.

		While it runs, the suite resets the admin and advertiser passwords,
		switches the theme and seeds campaigns, organizations and placements.
		It runs only on a site made for it:

		  pnpm e2e:site

		creates one at ~/Studio/aggr-e2e serving this checkout.
	REFUSE
	exit 1
fi

# One run per site. A second run would read the first one's snapshot as a
# crash and restore the site under it, mid-test (bin/local/e2e-lock.mjs). Taken
# after the checks above, so a site that is not the suite's never gets a lock
# file written into it.
node bin/local/e2e-lock.mjs lock "${site_path}" "$$"

snapshot_taken=0
remove_mail_link=0

cleanup() {
	status=$?
	cleanup_failed=0
	trap - EXIT
	set +e

	if [[ "${snapshot_taken}" -eq 1 ]]; then
		# Stopped before the restore, so nothing the run set in motion — a
		# cron spawn, a request still in flight — can write to the database
		# after it has been put back.
		studio site stop --path "${site_path}" >/dev/null || cleanup_failed=1

		# The snapshot is removed only by a restore that worked, so a failed
		# one leaves it for the next run to restore from instead of re-taking.
		node bin/local/e2e-snapshot.mjs restore "${site_path}" "${database_file}" || cleanup_failed=1

		# The database is back, but global-setup's `--hard` flush also wrote the
		# site's .htaccess, which is a file. Rewrite it from the restored rules.
		studio wp --path "${site_path}" rewrite flush --hard >/dev/null || cleanup_failed=1
	fi

	if [[ "${remove_mail_link}" -eq 1 ]]; then
		rm -f "${mail_link}" || cleanup_failed=1
	fi

	# Running afterwards only if it was running before: the suite's site is
	# up only while the suite is, and a site somebody had open stays open.
	if [[ "${site_state}" == "running" ]]; then
		studio site start --path "${site_path}" >/dev/null || cleanup_failed=1
	else
		studio site stop --path "${site_path}" >/dev/null || cleanup_failed=1
	fi

	node bin/local/e2e-lock.mjs unlock "${site_path}" "$$" || cleanup_failed=1

	if [[ "${cleanup_failed}" -ne 0 ]]; then
		echo "studio-e2e: the site was not fully restored; the next run will retry." >&2

		if [[ "${status}" -eq 0 ]]; then
			echo "studio-e2e: the suite passed, but this run is failed for it." >&2
			status=1
		fi
	fi

	exit "${status}"
}
trap cleanup EXIT

database_file="$(
	studio wp --path "${site_path}" eval \
		'echo defined( "FQDB" ) ? FQDB : "";' 2>/dev/null | tr -d '\r\n'
)"

if [[ -z "${database_file}" || ! -f "${database_file}" ]]; then
	echo "studio-e2e: ${site_path} has no SQLite database to snapshot." >&2
	echo "The suite runs only where it can put the site back afterwards." >&2
	exit 1
fi

# A run before snapshots kept its theme and permalinks here instead, and one
# that died left them changed. Put them back before the snapshot, or the
# snapshot would keep the damage as the site's baseline.
legacy_restore="${site_path}/.aggr-e2e-restore"

if [[ -f "${legacy_restore}" ]]; then
	echo "studio-e2e: recovering theme and permalinks from an older run." >&2
	studio wp --path "${site_path}" theme activate "$(sed -n '1p' "${legacy_restore}" | tr -d '\r\n')" >/dev/null
	studio wp --path "${site_path}" option update permalink_structure "$(sed -n '2p' "${legacy_restore}" | tr -d '\r\n')" >/dev/null
	rm -f "${legacy_restore}"
fi

# A snapshot already here means a run died before restoring. The snapshot, not
# the site as it is now, is the baseline, so restore from it first.
# The lock above says no other run is alive, so this is a dead run's; stopped
# for the restore, as at the end of a run.
if [[ -d "${site_path}/.aggr-e2e-snapshot" ]]; then
	echo "studio-e2e: a previous run did not restore the site; restoring it now." >&2

	# The dead run started the site, so "running" now says nothing about
	# whether it was running before; that run recorded the answer.
	recorded_state="$(cat "${site_path}/.aggr-e2e-snapshot/site-state" 2>/dev/null || true)"

	if [[ "${recorded_state}" == "running" || "${recorded_state}" == "stopped" ]]; then
		site_state="${recorded_state}"
	fi

	studio site stop --path "${site_path}" >/dev/null
	node bin/local/e2e-snapshot.mjs restore "${site_path}" "${database_file}"
	studio wp --path "${site_path}" rewrite flush --hard >/dev/null
fi

# stdout is dropped, stderr is not. `studio site start` prints the site's admin
# username and password on success, and this script's output ends up in qa:local
# logs that get pasted into issues.
studio site start --path "${site_path}" >/dev/null

mail_fixture="${repo_root}/tests/fixtures/mu-plugins/dev-mail-sender.php"
mail_link="${site_path}/wp-content/mu-plugins/aggr-e2e-mail-capture.php"

# A link this script left behind is this script's to replace, from any
# checkout and even when it dangles. A run that dies before its trap leaves
# the link, and the next run used to adopt it as somebody else's and keep it;
# once the checkout it pointed into was gone, every page on the site opened
# with an include_once warning. Anything that is not such a link — a real
# file, a link elsewhere — is still refused.
mail_target=""

if [[ -L "${mail_link}" ]]; then
	mail_target="$(readlink "${mail_link}")"
fi

if [[ -L "${mail_link}" && "${mail_target}" == */tests/fixtures/mu-plugins/dev-mail-sender.php ]]; then
	ln -sfn "${mail_fixture}" "${mail_link}"
	remove_mail_link=1
elif [[ -e "${mail_link}" || -L "${mail_link}" ]]; then
	echo "studio-e2e: refusing to replace ${mail_link}." >&2
	echo "It is not a link to this plugin's mail fixture." >&2
	exit 1
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

# After home and siteurl, so the snapshot carries the address Studio serves now
# and a restore does not put back a stale one.
node bin/local/e2e-snapshot.mjs take "${site_path}" "${database_file}"
snapshot_taken=1

# For a run that recovers this one, should it die: see the restore above.
printf '%s\n' "${site_state}" > "${site_path}/.aggr-e2e-snapshot/site-state"

echo "studio-e2e: ${base_url} (${site_path})"
echo "studio-e2e: the database and uploads are restored when the run ends."

AGGR_E2E_BASE_URL="${base_url}" \
	AGGR_E2E_WP_PATH="${site_path}" \
	pnpm test:e2e "$@"

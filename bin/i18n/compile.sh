#!/usr/bin/env bash
#
# Compile every locale .po into the binary catalog WordPress loads, plus Jed
# JSON for any classic (non-module) script.
#
# .mo files are build output: gitignored, produced here, and shipped by
# bin/release/package.sh. Skipping this step is invisible at runtime — the site
# renders English with no error — which is why bin/release/verify-package.sh
# refuses to publish an archive containing a .po without its .mo.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

aggr_i18n_ensure_languages_dir

po_files="$(aggr_i18n_list_po_files)"
if [[ -z "${po_files}" ]]; then
	aggr_i18n_info "No locale .po files to compile."
	exit 0
fi

while IFS= read -r po; do
	[[ -n "${po}" ]] || continue
	locale="$(aggr_i18n_locale_from_po "${po}")"
	aggr_i18n_info "Compiling ${locale}"

	# make-mo names its output after the .po, which for a plugin is already
	# the correct name. THERE IS NO RENAME STEP HERE, and the theme scripts
	# this was adapted from have one — see aggr_i18n_mo_path() in lib.sh for
	# why porting it silently disables translation.
	aggr_i18n_wp i18n make-mo "languages/$(basename "${po}")" languages

	expected="$(aggr_i18n_mo_path "${locale}")"
	[[ -f "${expected}" ]] || aggr_i18n_die "make-mo did not write $(basename "${expected}"). WordPress opens that exact filename and no other."

	# JSON catalogs always keep the domain prefix — _load_script_textdomain()
	# builds "{$domain}-{$locale}-{$md5}.json" with no path branch of its own.
	aggr_i18n_wp i18n make-json "languages/$(basename "${po}")" languages --no-purge
done <<< "${po_files}"

aggr_i18n_info "Done."

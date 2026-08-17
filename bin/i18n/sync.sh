#!/usr/bin/env bash
#
# Merge the current POT into every locale catalog.
#
# Existing translations are kept, new strings arrive untranslated, and strings
# that no longer exist in the source are commented out rather than deleted —
# so restoring a reverted string does not lose its translation.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

aggr_i18n_ensure_languages_dir
[[ -f "${AGGR_POT_FILE}" ]] || aggr_i18n_die "No POT at languages/${AGGR_TEXT_DOMAIN}.pot. Run: pnpm i18n:pot"

command -v msgmerge > /dev/null 2>&1 || aggr_i18n_die "msgmerge is required. Install gettext (apt-get install gettext / brew install gettext)."

po_files="$(aggr_i18n_list_po_files)"
if [[ -z "${po_files}" ]]; then
	aggr_i18n_info "No locale catalogs yet. Create one with: pnpm i18n:locale <code>"
	exit 0
fi

while IFS= read -r po; do
	[[ -n "${po}" ]] || continue
	aggr_i18n_info "Merging $(aggr_i18n_locale_from_po "${po}")"
	msgmerge --update --backup=none --quiet "${po}" "${AGGR_POT_FILE}"
done <<< "${po_files}"

aggr_i18n_info "Done. Review the fuzzy entries msgmerge introduced before committing."

#!/usr/bin/env bash
#
# Per-locale translation coverage.
#
# Informational only — it never fails. Deciding a locale is "ready enough" to
# ship is a judgement call, and a gate that makes it for you either blocks a
# release over one untranslated error string or gets a threshold nobody
# believes in.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

command -v msgfmt > /dev/null 2>&1 || aggr_i18n_die "msgfmt is required. Install gettext (apt-get install gettext / brew install gettext)."

if [[ -f "${AGGR_POT_FILE}" ]]; then
	# Subtract the header entry, which is a msgid "" every catalog carries.
	total=$(( $(grep -c '^msgid ' "${AGGR_POT_FILE}") - 1 ))
	aggr_i18n_info "Source strings: ${total}"
else
	aggr_i18n_info "No POT yet. Run: pnpm i18n:pot"
fi

po_files="$(aggr_i18n_list_po_files)"
if [[ -z "${po_files}" ]]; then
	aggr_i18n_info "No locale catalogs. Create one with: pnpm i18n:locale <code>"
	exit 0
fi

while IFS= read -r po; do
	[[ -n "${po}" ]] || continue
	printf '  %-10s %s\n' \
		"$(aggr_i18n_locale_from_po "${po}")" \
		"$(msgfmt --statistics -o /dev/null "${po}" 2>&1)"
done <<< "${po_files}"

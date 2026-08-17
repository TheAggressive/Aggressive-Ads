#!/usr/bin/env bash
#
# Strict catalog validation, on the host.
#
# Two things make this file worth its own script.
#
# It runs on the HOST, not in the wp-env cli container: that image is Alpine
# based and ships no gettext, so running validation inside it can only ever
# report success.
#
# And it uses msgfmt -c rather than `wp i18n make-mo`. make-mo is lenient — it
# reports success on a msgid/msgstr placeholder mismatch, which is precisely
# the defect that reaches a translated production site as a fatal sprintf or a
# mangled sentence. msgfmt -c checks formats, headers and domain, and exits
# non-zero.
#
# A MISSING msgfmt IS A HARD FAILURE. Skipping the strict check when the tool
# is absent means the check only runs where it was never going to fail, which
# is how the sibling theme shipped four broken catalogs for months.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

if ! command -v msgfmt > /dev/null 2>&1; then
	aggr_i18n_die "msgfmt not found. Install gettext (apt-get install gettext / brew install gettext). This check never skips."
fi

po_files="$(aggr_i18n_list_po_files)"
if [[ -z "${po_files}" ]]; then
	aggr_i18n_info "No locale catalogs to validate."
	exit 0
fi

failed=0
while IFS= read -r po; do
	[[ -n "${po}" ]] || continue
	aggr_i18n_info "Validating $(basename "${po}")"
	if ! msgfmt --check --output-file=/dev/null "${po}"; then
		failed=1
	fi
done <<< "${po_files}"

if [[ "${failed}" -ne 0 ]]; then
	aggr_i18n_die "One or more catalogs are invalid."
fi

aggr_i18n_info "All catalogs valid."

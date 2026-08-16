#!/usr/bin/env bash
#
# Regenerate languages/aggressive-ads.pot from the source tree.
#
# Run this whenever a translatable string is added, changed or removed. The
# drift gate in check.sh fails CI with a diff when you forget.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

aggr_i18n_ensure_languages_dir

aggr_i18n_info "Extracting strings into languages/${AGGR_TEXT_DOMAIN}.pot…"

aggr_i18n_wp i18n make-pot \
	. \
	"languages/${AGGR_TEXT_DOMAIN}.pot" \
	--domain="${AGGR_TEXT_DOMAIN}" \
	--package-name="Aggressive Ads" \
	--exclude="${AGGR_I18N_EXCLUDE}"

aggr_i18n_info "Wrote languages/${AGGR_TEXT_DOMAIN}.pot ($(grep -c '^msgid ' "${AGGR_POT_FILE}") entries)."

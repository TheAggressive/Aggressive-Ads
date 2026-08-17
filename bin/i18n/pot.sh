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

# Report-Msgid-Bugs-To is pinned rather than left to wp-cli, which derives it
# from the *directory name* when it is not given. A clone into `aggressive-ads`
# and GitHub's checkout into `Aggressive-Ads` therefore produced two different
# POT headers from identical source, and the drift check failed on CI for every
# change while passing on every laptop. The support URL is a property of the
# plugin slug, not of wherever somebody happened to put the working copy.
aggr_i18n_wp i18n make-pot \
	. \
	"languages/${AGGR_TEXT_DOMAIN}.pot" \
	--domain="${AGGR_TEXT_DOMAIN}" \
	--package-name="Aggressive Ads" \
	--headers="{\"Report-Msgid-Bugs-To\":\"https://wordpress.org/support/plugin/${AGGR_TEXT_DOMAIN}\"}" \
	--exclude="${AGGR_I18N_EXCLUDE}"

aggr_i18n_info "Wrote languages/${AGGR_TEXT_DOMAIN}.pot ($(grep -c '^msgid ' "${AGGR_POT_FILE}") entries)."

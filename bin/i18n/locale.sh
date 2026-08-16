#!/usr/bin/env bash
#
# Scaffold a new locale catalog: bin/i18n/locale.sh de_DE
#
# The argument is a WordPress locale code (de_DE, fr_FR, pt_BR, es_ES), not a
# bare language code — that is what WP_LANG_DIR and the .mo filename use.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

locale="${1:-}"
[[ -n "${locale}" ]] || aggr_i18n_die "Usage: pnpm i18n:locale <locale>   e.g. pnpm i18n:locale de_DE"

if [[ ! "${locale}" =~ ^[a-z]{2,3}(_[A-Z]{2})?$ ]]; then
	aggr_i18n_die "'${locale}' is not a WordPress locale code (expected e.g. de_DE, pt_BR, fr_FR)."
fi

command -v msginit > /dev/null 2>&1 || aggr_i18n_die "msginit is required. Install gettext (apt-get install gettext / brew install gettext)."

aggr_i18n_ensure_languages_dir
[[ -f "${AGGR_POT_FILE}" ]] || aggr_i18n_die "No POT at languages/${AGGR_TEXT_DOMAIN}.pot. Run: pnpm i18n:pot"

target="${AGGR_LANGUAGES_DIR}/${AGGR_TEXT_DOMAIN}-${locale}.po"
[[ ! -f "${target}" ]] || aggr_i18n_die "${AGGR_TEXT_DOMAIN}-${locale}.po already exists. Run: pnpm i18n:sync"

# --no-translator keeps the header free of whatever name and address happen to
# be configured on the machine that ran this, which would otherwise land in a
# committed file.
msginit \
	--input="${AGGR_POT_FILE}" \
	--output-file="${target}" \
	--locale="${locale}" \
	--no-translator

aggr_i18n_info "Created languages/${AGGR_TEXT_DOMAIN}-${locale}.po"
aggr_i18n_info "Translate it, then: pnpm i18n:compile"

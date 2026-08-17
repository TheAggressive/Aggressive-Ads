#!/usr/bin/env bash
#
# The i18n CI gate: POT drift, then catalog validity.
#
# There is deliberately NO translator-comment lint here.
# WordPress.WP.I18n.MissingTranslatorsComment is part of the WordPress standard
# `pnpm lint:php` already runs, and it fails the build on a placeholder string
# without a comment — verified by deleting one and watching PHPCS go red.
# Writing a second implementation would mean a second set of bugs and a second
# thing to drift. See docs/i18n.md.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

aggr_i18n_ensure_languages_dir
[[ -f "${AGGR_POT_FILE}" ]] || aggr_i18n_die "Committed POT missing at languages/${AGGR_TEXT_DOMAIN}.pot. Run: pnpm i18n:pot"

# Generated inside the repository rather than under /tmp on purpose: when the
# extraction runs in the wp-env container, a host mktemp path does not exist on
# the other side of the bind mount.
drift_pot="${AGGR_LANGUAGES_DIR}/.drift.pot"
work_dir="$(mktemp -d)"
trap 'rm -f "${drift_pot}"; rm -rf "${work_dir}"' EXIT

aggr_i18n_info "Regenerating POT for the drift check…"
aggr_i18n_make_pot "languages/.drift.pot"

[[ -f "${drift_pot}" ]] || aggr_i18n_die "make-pot produced no output; the drift check cannot pass vacuously."

aggr_i18n_normalize_pot "${AGGR_POT_FILE}" "${work_dir}/committed.pot"
aggr_i18n_normalize_pot "${drift_pot}" "${work_dir}/generated.pot"

if ! diff -u "${work_dir}/committed.pot" "${work_dir}/generated.pot" > "${work_dir}/pot.diff"; then
	aggr_i18n_info "POT drift detected. First 80 lines:"
	head -n 80 "${work_dir}/pot.diff" || true
	aggr_i18n_die "languages/${AGGR_TEXT_DOMAIN}.pot is out of date. Run: pnpm i18n:pot && commit the result."
fi

aggr_i18n_info "POT is up to date."

# Catalog validation needs msgfmt, which the cli container does not have. When
# this script is the container half of bin/ci/i18n.sh, the host half runs
# validate-po.sh afterwards — so skipping is explicit and loud, and is never
# something a missing tool chooses on your behalf.
case "${AGGR_I18N_PO_VALIDATOR:-auto}" in
	auto)
		bash "${AGGR_I18N_DIR}/validate-po.sh"
		;;
	skip)
		aggr_i18n_info "PO validation SKIPPED (AGGR_I18N_PO_VALIDATOR=skip) — catalogs are NOT checked here."
		;;
	*)
		aggr_i18n_die "AGGR_I18N_PO_VALIDATOR must be 'auto' or 'skip' (got '${AGGR_I18N_PO_VALIDATOR}')."
		;;
esac

aggr_i18n_info "i18n check passed."

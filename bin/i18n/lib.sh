#!/usr/bin/env bash
#
# Shared helpers for the plugin's i18n tooling.
#
# See docs/i18n.md. The one rule that is easy to get wrong and impossible to
# notice is the compiled catalog's filename — read aggr_i18n_mo_path() before
# touching compile.sh.
#
# shellcheck shell=bash

set -euo pipefail

AGGR_I18N_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AGGR_PLUGIN_ROOT="$(cd "${AGGR_I18N_DIR}/../.." && pwd)"
AGGR_TEXT_DOMAIN="${AGGR_TEXT_DOMAIN:-aggressive-ads}"
AGGR_LANGUAGES_DIR="${AGGR_PLUGIN_ROOT}/languages"
# Read by the scripts that source this file; ShellCheck only sees one file.
# shellcheck disable=SC2034
AGGR_POT_FILE="${AGGR_LANGUAGES_DIR}/${AGGR_TEXT_DOMAIN}.pot"

# What make-pot must not walk.
#
# dist/ is excluded because it is build output whose strings all come from src/,
# and scanning both would double every entry.
#
# src/ is NOT excluded, and that is a deliberate reversal. The original rule
# said it held no translatable literal, because Interactivity stores are script
# modules and script modules have no translation mechanism — their strings are
# hydrated from PHP already translated. That is still true of
# src/interactivity/. It is not true of src/admin/, which is a *classic* script
# where wp_set_script_translations() works normally and __() calls are real
# catalog entries. Excluding src/ made those strings invisible to translators
# while the code looked correct. See docs/i18n.md.
AGGR_I18N_EXCLUDE="${AGGR_I18N_EXCLUDE:-node_modules,vendor,dist,types,tests,bin,docs,release,test-results,playwright-report,.git,.github,.husky,.phpunit.cache}"

# The pinned extractor, fetched by bin/ci/install-wp-cli.sh.
AGGR_PINNED_WP_CLI="${AGGR_PLUGIN_ROOT}/.cache/ci/wp"

aggr_i18n_info() {
	printf 'i18n: %s\n' "$*"
}

aggr_i18n_die() {
	printf 'i18n: ERROR: %s\n' "$*" >&2
	exit 1
}

aggr_i18n_ensure_languages_dir() {
	mkdir -p "${AGGR_LANGUAGES_DIR}"
}

# Run `wp i18n …` from the plugin root, with paths relative to it.
#
# The pinned phar wins over whatever `wp` is on PATH, and that order is the
# reason install-wp-cli.sh exists. `wp i18n make-pot` is a pure source-tree
# extractor — it needs no database and no WordPress install — so the only thing
# that decides what lands in the POT is the extractor's own version. Letting
# PATH decide means the drift gate reports "drift" for a colleague's correct
# commit, which is how a gate stops being believed.
#
# A host `wp` is still allowed for the convenience commands, with a warning,
# because being unable to look at coverage without a download is worse than an
# occasional header difference. The gate itself runs through bin/ci/i18n.sh,
# which installs the pinned binary first.
aggr_i18n_wp() {
	local runner="${AGGR_WP_CLI:-}"

	if [[ -z "${runner}" && -x "${AGGR_PINNED_WP_CLI}" ]]; then
		runner="${AGGR_PINNED_WP_CLI}"
	fi

	if [[ -n "${runner}" ]]; then
		# WP-CLI 2.12 is not PHP 8.4 clean, and its own deprecation notices
		# print five times per invocation. None of them come from this
		# plugin — make-pot parses our PHP, it never executes it — so
		# silencing them hides nothing of ours and keeps a real failure
		# visible instead of buried in known noise.
		(
			cd "${AGGR_PLUGIN_ROOT}" &&
				php -d error_reporting='E_ALL & ~E_DEPRECATED' \
					-d display_errors=0 \
					"${runner}" --allow-root "$@"
		)
		return
	fi

	if command -v wp > /dev/null 2>&1; then
		aggr_i18n_info "Using the WP-CLI on PATH. The gate uses the pinned one: bash bin/ci/install-wp-cli.sh"
		( cd "${AGGR_PLUGIN_ROOT}" && wp --allow-root "$@" )
		return
	fi

	aggr_i18n_die "No WP-CLI available. Run: bash bin/ci/install-wp-cli.sh"
}

# One make-pot invocation, called by both the generator and the drift check.
#
# It was two, with the flags copied between them, and the copies disagreed the
# moment one gained an argument: the drift check regenerated the POT without the
# pinned Report-Msgid-Bugs-To header and then reported the difference from the
# committed file as drift. A check that regenerates an artefact differently from
# the thing that generates it is checking its own copy of the command.
#
# $1 Output path, relative to the plugin root.
aggr_i18n_make_pot() {
	local output="$1"

	# Report-Msgid-Bugs-To is pinned rather than left to wp-cli, which derives
	# it from the *directory name* when it is not given. A clone into
	# `aggressive-ads` and GitHub's checkout into `Aggressive-Ads` therefore
	# produced two different headers from identical source. The support URL is
	# a property of the plugin slug, not of wherever the working copy sits.
	aggr_i18n_wp i18n make-pot \
		. \
		"${output}" \
		--domain="${AGGR_TEXT_DOMAIN}" \
		--package-name="Aggressive Ads" \
		--headers="{\"Report-Msgid-Bugs-To\":\"https://wordpress.org/support/plugin/${AGGR_TEXT_DOMAIN}\"}" \
		--exclude="${AGGR_I18N_EXCLUDE}"
}

# Strip headers that change on every run, so the drift gate reports real
# changes rather than a timestamp. Without this the diff is never empty, the
# gate is pure noise, and somebody disables it inside a week.
#
# Project-Id-Version carries the plugin version, which release packaging stamps
# without regenerating the committed POT — a version stamp, not translatable
# content, so it normalizes away with the rest.
aggr_i18n_normalize_pot() {
	local src="$1"
	local dest="$2"

	sed -E \
		-e 's/^"Project-Id-Version: .+\\n"$/"Project-Id-Version: Aggressive Ads\\n"/' \
		-e 's/^"POT-Creation-Date: .+\\n"$/"POT-Creation-Date: YEAR-MO-DA HO:MI+ZONE\\n"/' \
		-e 's/^"PO-Revision-Date: .+\\n"$/"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"/' \
		-e 's/^"X-Generator: .+\\n"$/"X-Generator: WP-CLI\\n"/' \
		"${src}" > "${dest}"
}

aggr_i18n_list_po_files() {
	find "${AGGR_LANGUAGES_DIR}" -maxdepth 1 -type f \
		-name "${AGGR_TEXT_DOMAIN}-*.po" 2> /dev/null | sort
}

aggr_i18n_locale_from_po() {
	local base
	base="$(basename "$1" .po)"
	printf '%s\n' "${base#"${AGGR_TEXT_DOMAIN}-"}"
}

# The compiled catalog's path — the single most dangerous line in this
# directory, which is why it is a function rather than an inlined string.
#
# A PLUGIN'S .mo KEEPS THE DOMAIN PREFIX: aggressive-ads-de_DE.mo.
#
# The LAAO and Aggressive Apparel themes rename theirs to de_DE.mo, and that is
# correct *for a theme*, because _load_textdomain_just_in_time() special-cases
# paths under the template or stylesheet directory:
#
#   if ( str_starts_with( $path, $template_directory ) || … ) {
#       $mofile = "{$path}{$locale}.mo";            // de_DE.mo
#   } else {
#       $mofile = "{$path}{$domain}-{$locale}.mo";  // aggressive-ads-de_DE.mo
#   }
#
# A plugin's Domain Path is never under either directory, so only the second
# branch is ever taken. Porting the theme's rename step produces a file
# WordPress will never open, and there is no error, no warning and nothing in
# the log — the site just renders English. Do not port it. See docs/i18n.md.
aggr_i18n_mo_path() {
	printf '%s/%s-%s.mo\n' "${AGGR_LANGUAGES_DIR}" "${AGGR_TEXT_DOMAIN}" "$1"
}

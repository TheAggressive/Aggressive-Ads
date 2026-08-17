#!/usr/bin/env bash
#
# The i18n lane: POT drift, then catalog validity.
#
# Both halves run on the host, and each needs a tool that must not be optional.
#
# Extraction needs the PINNED WP-CLI, not whatever is on PATH, or the drift
# diff depends on which release the machine happens to have installed.
#
# Validation needs msgfmt, because `wp i18n make-mo` reports success on a
# msgid/msgstr placeholder mismatch — the exact defect that reaches a
# translated production site as a mangled sentence or a fatal sprintf.
#
# Neither is fetched lazily at the point of use: a check that installs its own
# prerequisite mid-run is a check that silently degrades when the install
# fails.

set -euo pipefail

cd "$(dirname "$0")/../.."

bash bin/ci/install-wp-cli.sh

# Guarded three ways so it stays a no-op for developers: only when msgfmt is
# genuinely absent, only under apt-get, and only with non-interactive sudo.
# Never fatal on its own — under `set -e` a flaky apt mirror would kill the
# lane with a raw apt error, burying validate-po.sh's actionable message.
if ! command -v msgfmt > /dev/null 2>&1; then
	if command -v apt-get > /dev/null 2>&1 && sudo -n true > /dev/null 2>&1; then
		echo "ci:i18n: msgfmt not found — installing gettext for catalog validation."
		if ! { sudo -n apt-get update -qq && sudo -n apt-get install -y -qq gettext; }; then
			echo "ci:i18n: warning: installing gettext failed." >&2
		fi
	else
		echo "ci:i18n: warning: msgfmt is missing and cannot be installed automatically." >&2
	fi
fi

bash bin/i18n/check.sh

echo "ci:i18n: ok"

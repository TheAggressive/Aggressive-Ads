#!/usr/bin/env bash
#
# The contract for declaring a change finished.
#
# Green locally means green in CI. Every lane here maps 1:1 onto a GitHub
# Actions job, and adding a lane means adding it to BOTH — adding it to only
# one is how the two drift.
#
# See docs/build-and-release.md.

set -euo pipefail

cd "$(dirname "$0")/../.."

run() {
	echo
	echo "──────────────────────────────────────────────────────────────"
	echo "  $1"
	echo "──────────────────────────────────────────────────────────────"
	shift
	"$@"
}

run "doctor"      pnpm ci:doctor
run "structure"   pnpm lint:files
run "php"         pnpm ci:php

# Lanes landing with the phases that need them:
#   frontend  — lint:js, lint:css, format:check, typecheck, test:js
#   php:wp    — the integration, security, rest and upgrade suites
#   i18n      — POT drift and .mo compilation
#   build     — webpack bundles
#   e2e       — Playwright, including the Twenty Twenty-Five smoke test
#   package   — release packaging and independent ZIP verification

echo
echo "ci:verify: all lanes passed"

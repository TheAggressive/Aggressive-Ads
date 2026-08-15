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
run "composer"    pnpm composer:verify
run "security"    pnpm ci:security
run "structure"   pnpm lint:files
run "build"       pnpm ci:build
run "frontend"    pnpm ci:frontend
run "php"         pnpm ci:php
run "coverage"    pnpm ci:coverage

# The integration, security, rest and upgrade suites need real WordPress and
# real MySQL, which means wp-env must be up. Failing with a usable instruction
# beats a wall of connection errors — but it still fails, because a suite that
# quietly skips itself is a suite that stops covering anything.
if ! docker info >/dev/null 2>&1; then
	echo
	echo "ci:verify: Docker is not running, so the WordPress suites cannot run." >&2
	echo "           Start Docker, then: pnpm env:start" >&2
	exit 1
fi

run "php:wp"      pnpm ci:php:wp

# The one deliberate difference from CI, and the only one.
#
# CI runs `test:e2e:install`, whose --with-deps apt-gets the browsers' system
# libraries onto a bare runner. Locally that flag shells out to sudo on every
# single run — it never checks whether the libraries are already present — so
# the full rehearsal stopped to ask for a root password each time.
#
# The libraries are one-time machine setup, not part of the change under test,
# and a host that really is missing one fails at browse time with Playwright's
# own instruction to run `playwright install-deps`. What CI and this script
# still share byte for byte is the lane that decides anything: ci:e2e.
run "browsers"    pnpm test:e2e:browsers
run "e2e"         pnpm ci:e2e
run "package"     pnpm ci:package

# Every lane above maps 1:1 onto a job in .github/workflows/ci.yml.
# Adding one here without adding it there is how the two drift.
#
# Lanes landing with the phases that need them:
#   i18n      — POT drift and .mo compilation

echo
echo "ci:verify: all lanes passed"

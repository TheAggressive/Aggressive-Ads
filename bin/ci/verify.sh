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
run "browsers"    pnpm test:e2e:install
run "e2e"         pnpm ci:e2e
run "package"     pnpm ci:package

# Every lane above maps 1:1 onto a job in .github/workflows/ci.yml.
# Adding one here without adding it there is how the two drift.
#
# Lanes landing with the phases that need them:
#   i18n      — POT drift and .mo compilation

echo
echo "ci:verify: all lanes passed"

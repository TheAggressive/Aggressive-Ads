#!/usr/bin/env bash
#
# Fast pre-push gate. It covers deterministic checks that do not need Docker;
# the full WordPress, browser, package and network audit lanes still run in CI.

set -euo pipefail

cd "$(dirname "$0")/../.."

pnpm ci:doctor
pnpm install --frozen-lockfile
pnpm composer:verify
pnpm lint:files
pnpm ci:frontend
pnpm ci:build
pnpm ci:php
pnpm ci:coverage

echo
echo "Fast pre-push checks passed."
echo "Before a release, run the full rehearsal: pnpm qa"

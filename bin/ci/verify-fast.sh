#!/usr/bin/env bash
#
# Fast pre-push gate. It omits browsers, packaging, multisite and network
# audits; combined coverage still needs Docker-backed wp-env.

set -euo pipefail

cd "$(dirname "$0")/../.."

pnpm ci:doctor
pnpm install --frozen-lockfile
pnpm composer:verify
pnpm lint:files
pnpm ci:frontend
pnpm ci:build
pnpm ci:php
pnpm env:start:coverage
pnpm ci:coverage

echo
echo "Fast pre-push checks passed."
echo "Before a release, run the full rehearsal: pnpm qa"

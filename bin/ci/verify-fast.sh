#!/usr/bin/env bash
#
# Fast Docker-free pre-push gate. WordPress integration, coverage, browsers,
# packaging and network audits remain in CI; qa:local adds the WordPress suites
# on a local MySQL and the browser workflows via Studio.
#
# `ci:frontend` is called as a lane, never unrolled into its parts here. It once
# was, and the copy silently lost `lint:shell` — in the same change that added
# two new shell scripts. A second copy of a lane's contents is a second thing to
# keep in step, which is the drift bin/ci/check-ci-parity.sh exists to refuse
# everywhere else. `lint:shell` reaches for Docker only when Docker is there and
# falls back to an installed ShellCheck, so it costs this gate nothing.

set -euo pipefail

cd "$(dirname "$0")/../.."

pnpm ci:doctor
pnpm install --frozen-lockfile
pnpm composer:verify
pnpm lint:files
pnpm ci:frontend
pnpm ci:build
pnpm ci:php

# One second, and it catches the drift that is otherwise only visible in CI.
#
# The POT records a source line per string, so inserting a method above one is
# enough to make it stale — a change with no new strings in it at all. That is
# exactly the failure worth catching before a push rather than after, and it
# needs no Docker: the extractor is the checksum-pinned WP-CLI in .cache/ci.
pnpm ci:i18n

echo
echo "Fast pre-push checks passed."
echo "Run browser workflows locally with: pnpm qa:local"
echo "The exact containerized CI rehearsal remains: pnpm qa"

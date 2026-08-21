#!/usr/bin/env bash
# Docker-free local gate. MySQL-specific suites remain authoritative in CI.

set -euo pipefail

cd "$(dirname "$0")/../.."

pnpm qa:fast
pnpm test:e2e:browsers
pnpm test:e2e:studio

echo
echo "Local Studio QA passed. GitHub CI will run MySQL and release lanes."

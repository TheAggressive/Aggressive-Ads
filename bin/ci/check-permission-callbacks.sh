#!/usr/bin/env bash
#
# REST permission gate.
#
# The rules and their reasoning live in bin/ci/check-permission-callbacks.php,
# which uses PHP's tokenizer rather than grep. This wrapper exists so the lane
# keeps the name package.json and the CI workflow already know.
#
# The first version of this check was pure grep and matched exactly one
# spelling. Testing it found four ways past — a wrapped line, an arrow
# function, a closure, and a route with no permission_callback at all — plus a
# missing scan directory that made the whole gate pass over nothing.

set -euo pipefail

cd "$(dirname "$0")/../.."

exec php bin/ci/check-permission-callbacks.php

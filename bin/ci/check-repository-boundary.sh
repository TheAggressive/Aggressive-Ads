#!/usr/bin/env bash
#
# Architecture boundary gate.
#
# The rules and their reasoning live in bin/ci/check-boundaries.php, which uses
# PHP's tokenizer rather than grep. This wrapper exists so the lane keeps the
# name package.json and the CI workflow already know.
#
# The first version of this check was pure grep, and it reported every docblock
# that *named* a forbidden function — including the comment explaining why the
# domain layer deliberately does not call wp_parse_url(). Reading code as text
# cannot tell a call from prose about a call.

set -euo pipefail

cd "$(dirname "$0")/../.."

exec php bin/ci/check-boundaries.php

#!/usr/bin/env bash
# Convenience: pot → sync → compile → status.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

bash "${DIR}/pot.sh"
bash "${DIR}/sync.sh"
bash "${DIR}/compile.sh"
bash "${DIR}/status.sh"

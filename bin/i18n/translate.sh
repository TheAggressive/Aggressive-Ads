#!/usr/bin/env bash
# Sync POT → PO, then machine-translate empty/fuzzy entries (no API key required).
set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

# pnpm forwards a literal "--" before script args; skip it.
while [[ "${1:-}" == "--" ]]; do
	shift
done

# Local DeepL / MyMemory secrets from gitignored `.env.local` (see `.env.example`).
aggr_i18n_load_dotenv

aggr_i18n_info "Syncing locales from pot…"
bash "${AGGR_I18N_DIR}/sync.sh"

aggr_i18n_info "Machine-translating empty/fuzzy strings…"
node "${AGGR_I18N_DIR}/translate.mjs" "$@"

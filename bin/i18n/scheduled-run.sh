#!/usr/bin/env bash
#
# The unattended translation run.
#
# Nobody should have to remember to translate. This is what the timer calls:
# it brings a scratch checkout up to date with the default branch, merges the
# POT into every catalog, translates what is new, changed, empty or fuzzy, and
# commits the result on a branch.
#
# Three things it deliberately does not do:
#
#   * It never touches your working tree. It works in a git worktree under
#     .cache/, so a run that fires while you are mid-edit cannot move your
#     files or leave you on another branch.
#   * It never pushes or opens a pull request unless I18N_AUTO_PR=1 is set in
#     .env.local. A machine opening pull requests on your behalf should be a
#     decision you made once, in writing, not a side effect of installing a
#     timer.
#   * It never regenerates the POT. CI fails when the POT drifts from the
#     source, so the committed POT is current by construction; regenerating
#     here would need WP-CLI and could produce a diff nobody asked for.
#
# When the model is not running, translate.mjs prints in red and exits 1
# without touching a catalog. That is the whole failure path: this script adds
# no second opinion about whether the model is up.

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

aggr_i18n_load_dotenv

readonly WORKTREE="${AGGR_PLUGIN_ROOT}/.cache/i18n-auto"
readonly BRANCH="${I18N_AUTO_BRANCH:-automation/i18n-draft}"
readonly BASE="${I18N_AUTO_BASE:-origin/master}"

command -v git > /dev/null 2>&1 || aggr_i18n_die "git is required."

aggr_i18n_info "Fetching ${BASE%%/*}…"
git -C "${AGGR_PLUGIN_ROOT}" fetch --quiet "${BASE%%/*}"

# A worktree that already exists is reused; recreating it every night would
# throw away nothing useful but would churn the disk for no reason.
if [[ ! -d "${WORKTREE}/.git" ]]; then
	aggr_i18n_info "Creating the scratch worktree at .cache/i18n-auto…"
	git -C "${AGGR_PLUGIN_ROOT}" worktree add --quiet --force -B "${BRANCH}" "${WORKTREE}" "${BASE}"
else
	git -C "${WORKTREE}" fetch --quiet "${BASE%%/*}"
	git -C "${WORKTREE}" checkout --quiet -B "${BRANCH}" "${BASE}"
fi

aggr_i18n_info "Translating on ${BRANCH} from ${BASE}…"

# The run's own output is the record of what happened; it goes to the journal
# when systemd is the caller, and to the terminal when a person is.
if ! ( cd "${WORKTREE}" && bash bin/i18n/translate.sh ); then
	aggr_i18n_die "The translation run did not finish. Nothing was committed."
fi

if [[ -z "$(git -C "${WORKTREE}" status --porcelain -- languages)" ]]; then
	aggr_i18n_info "No catalog changed. Nothing to commit."
	exit 0
fi

changed="$(git -C "${WORKTREE}" diff --numstat -- languages | wc -l)"

git -C "${WORKTREE}" add languages
git -C "${WORKTREE}" commit --quiet --message "chore(i18n): scheduled translation draft

Drafted by the local model on $(date -u '+%Y-%m-%d'), by the timer rather than
by hand. ${changed} catalog(s) changed. Machine-drafted entries carry the
\`aggr-mt\` flag and the engine in a translator comment; fuzzy entries are the
ones the run did not trust.

Review before release."

aggr_i18n_info "Committed on ${BRANCH} (${changed} catalog(s) changed)."

if [[ "${I18N_AUTO_PR:-0}" != "1" ]]; then
	aggr_i18n_info "I18N_AUTO_PR is not 1, so nothing was pushed."
	aggr_i18n_info "To review it: git -C .cache/i18n-auto log -p -1"
	exit 0
fi

command -v gh > /dev/null 2>&1 || aggr_i18n_die "I18N_AUTO_PR=1 but the gh CLI is not installed."

aggr_i18n_info "Pushing ${BRANCH}…"
git -C "${WORKTREE}" push --quiet --force-with-lease --set-upstream origin "${BRANCH}"

if gh pr view "${BRANCH}" --repo "$(gh repo view --json nameWithOwner -q .nameWithOwner)" > /dev/null 2>&1; then
	aggr_i18n_info "A pull request for ${BRANCH} is already open; the push updated it."
	exit 0
fi

( cd "${WORKTREE}" && gh pr create \
	--base "${BASE#*/}" \
	--head "${BRANCH}" \
	--title "chore(i18n): scheduled translation draft" \
	--body "Drafted by the local model on a timer. Machine-drafted entries carry the \`aggr-mt\` flag and name the engine; fuzzy entries are the ones the run did not trust. Needs a reader of each language before release." )

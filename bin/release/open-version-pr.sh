#!/usr/bin/env bash
# Open a version-only PR, explicitly dispatch its checks, and register auto-merge.
# GITHUB_TOKEN-created branches do not emit ordinary push/pull_request workflow
# events, so required workflows are dispatched against the exact PR head.

set -euo pipefail

cd "$(dirname "$0")/../.."

VERSION="${AGGR_RELEASE_VERSION:?AGGR_RELEASE_VERSION is required}"
REPOSITORY="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
EVENT_SHA="${GITHUB_SHA:?GITHUB_SHA is required}"
RUN_ID="${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: ${VERSION}" >&2
	exit 2
fi

if [[ ! "${REPOSITORY}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
	echo "Invalid GitHub repository: ${REPOSITORY}" >&2
	exit 2
fi

if [[ ! "${EVENT_SHA}" =~ ^[a-f0-9]{40}$ || ! "${RUN_ID}" =~ ^[0-9]+$ ]]; then
	echo "Invalid trusted GitHub run identity." >&2
	exit 2
fi

git fetch --no-tags origin master
REMOTE_SHA="$(git rev-parse origin/master)"
if [[ "${REMOTE_SHA}" != "${EVENT_SHA}" ]]; then
	echo "master advanced from ${EVENT_SHA} to ${REMOTE_SHA}; the newer run owns release planning."
	exit 0
fi

BRANCH="automation/release-v${VERSION}-${RUN_ID}"
TITLE="chore(release): synchronize version ${VERSION}"
PR="$(gh pr list --repo "${REPOSITORY}" --head "${BRANCH}" --state open \
	--json number --jq '.[0].number // empty')"

if [[ -z "${PR}" ]]; then
	if ! git ls-remote --exit-code --heads origin "refs/heads/${BRANCH}" >/dev/null; then
		git switch --create "${BRANCH}" "${EVENT_SHA}"
		node bin/release/sync-version.mjs "${VERSION}"
		node bin/ci/check-version-contract.mjs
		git diff --check

		git config user.name "github-actions[bot]"
		git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
		git add -- \
			package.json \
			aggressive-ads.php \
			src/blocks/placement/block.json \
			README.md \
			tests/php/bootstrap-unit.php \
			tests/php/phpstan-bootstrap.php
		HUSKY=0 git commit -m "${TITLE}"
		git push --set-upstream origin "${BRANCH}"
	fi

	gh pr create --repo "${REPOSITORY}" --base master --head "${BRANCH}" \
		--title "${TITLE}" \
		--body "Automated protected release preparation for v${VERSION}. This PR changes only synchronized version declarations; publishing starts after this PR passes every required check and merges." \
		>/dev/null
	PR="$(gh pr list --repo "${REPOSITORY}" --head "${BRANCH}" --state open \
		--json number --jq '.[0].number // empty')"
fi

if [[ -z "${PR}" ]]; then
	echo "Could not resolve the protected version PR." >&2
	exit 1
fi

PR_NUMBER="$(gh pr view "${PR}" --repo "${REPOSITORY}" --json number --jq '.number')"
HEAD_SHA="$(gh pr view "${PR_NUMBER}" --repo "${REPOSITORY}" --json headRefOid --jq '.headRefOid')"

for workflow in ci.yml codeql.yml workflow-security.yml; do
	gh workflow run "${workflow}" --repo "${REPOSITORY}" --ref "${BRANCH}"
done

# Auto-merge merges on behalf of whoever registered it, and GitHub suppresses
# workflow events for everything GITHUB_TOKEN does — including the merge commit
# this eventually pushes to master. Registered with GITHUB_TOKEN, the merge
# lands silently and the master run that would publish the synchronized commit
# never starts. A separate credential is what makes that push observable.
if [[ -n "${AGGR_RELEASE_TOKEN:-}" ]]; then
	GH_TOKEN="${AGGR_RELEASE_TOKEN}" gh pr merge "${PR_NUMBER}" --repo "${REPOSITORY}" \
		--auto --squash --delete-branch --match-head-commit "${HEAD_SHA}"
	echo "Version PR #${PR_NUMBER} is awaiting required checks for ${HEAD_SHA}."
	exit 0
fi

gh pr merge "${PR_NUMBER}" --repo "${REPOSITORY}" --auto --squash \
	--delete-branch --match-head-commit "${HEAD_SHA}"

echo "Version PR #${PR_NUMBER} is awaiting required checks for ${HEAD_SHA}."
echo
echo "warning: AGGR_RELEASE_TOKEN is not configured, so this merge will not" >&2
echo "warning: emit a workflow event and v${VERSION} will not publish by itself." >&2
echo "warning: Once #${PR_NUMBER} merges, start the release run by hand:" >&2
echo "warning:   gh workflow run ci.yml --repo ${REPOSITORY} --ref master" >&2
echo "warning: See docs/build-and-release.md." >&2

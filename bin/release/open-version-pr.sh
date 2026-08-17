#!/usr/bin/env bash
# Open a version-only PR, explicitly dispatch its checks, and register auto-merge.
# GITHUB_TOKEN-created branches do not emit ordinary push/pull_request workflow
# events, so required workflows are dispatched against the exact PR head.
#
# The version commit is created through the GraphQL API rather than `git commit`
# because master's ruleset requires signed commits and GITHUB_TOKEN has no
# signing key. GitHub signs commits it creates itself; a pushed one arrives
# unsigned, and the PR then passes every required check and is refused at the
# merge with "the base branch policy prohibits the merge" — a failure that only
# appears at the end, on a PR that looks entirely green. v1.1.0 stalled there.

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

# Everything sync-version.mjs rewrites. Listed once, because the commit is
# built from exactly this set and check-version-contract.mjs verifies it.
VERSION_FILES=(
	package.json
	aggressive-ads.php
	src/blocks/placement/block.json
	README.md
	tests/php/bootstrap-unit.php
	tests/php/phpstan-bootstrap.php
)

PR="$(gh pr list --repo "${REPOSITORY}" --head "${BRANCH}" --state open \
	--json number --jq '.[0].number // empty')"

if [[ -z "${PR}" ]]; then
	if ! git ls-remote --exit-code --heads origin "refs/heads/${BRANCH}" >/dev/null; then
		node bin/release/sync-version.mjs "${VERSION}"
		node bin/ci/check-version-contract.mjs
		git diff --check

		# The branch starts at a commit that already exists on master, so
		# creating the ref pushes no new object and needs no signature.
		gh api "repos/${REPOSITORY}/git/refs" \
			-f ref="refs/heads/${BRANCH}" -f sha="${EVENT_SHA}" >/dev/null

		# Every version file, base64-encoded, as createCommitOnBranch wants it.
		ADDITIONS="$(for file in "${VERSION_FILES[@]}"; do
			jq -n --arg path "${file}" \
				--arg contents "$(base64 -w0 -- "${file}")" \
				'{ path: $path, contents: $contents }'
		done | jq -sc '.')"

		# expectedHeadOid refuses the write if anything moved the branch since
		# the ref was created, so a racing run cannot commit onto it blindly.
		if ! COMMIT_SHA="$(jq -n \
			--arg repo "${REPOSITORY}" \
			--arg branch "${BRANCH}" \
			--arg oid "${EVENT_SHA}" \
			--arg title "${TITLE}" \
			--argjson additions "${ADDITIONS}" \
			'{
				query: "mutation($input: CreateCommitOnBranchInput!) { createCommitOnBranch(input: $input) { commit { oid } } }",
				variables: { input: {
					branch: { repositoryNameWithOwner: $repo, branchName: $branch },
					message: { headline: $title },
					expectedHeadOid: $oid,
					fileChanges: { additions: $additions }
				} }
			}' | gh api graphql --input - \
			--jq '.data.createCommitOnBranch.commit.oid')" || [[ -z "${COMMIT_SHA}" ]]; then
			# Leaving the ref behind would make the next run's ls-remote treat
			# this branch as already prepared and open an empty PR.
			gh api --method DELETE "repos/${REPOSITORY}/git/refs/heads/${BRANCH}" >/dev/null || true
			echo "Could not create the signed version commit." >&2
			exit 1
		fi

		# The whole reason the commit is made through the API rather than
		# `git commit`. master requires signed commits, GITHUB_TOKEN has no
		# signing key, and an unsigned version commit produces a PR that passes
		# every check and can never merge — which is how v1.1.0 stalled. Assert
		# it here so a regression fails the release rather than the merge.
		VERIFIED="$(gh api "repos/${REPOSITORY}/commits/${COMMIT_SHA}" \
			--jq '.commit.verification.verified')"
		if [[ "${VERIFIED}" != "true" ]]; then
			gh api --method DELETE "repos/${REPOSITORY}/git/refs/heads/${BRANCH}" >/dev/null || true
			echo "Version commit ${COMMIT_SHA} is unsigned; master would reject the merge." >&2
			exit 1
		fi
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

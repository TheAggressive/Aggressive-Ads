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

# One credential decides whether this release finishes by itself, so it is
# chosen once here and every gh call below inherits it.
#
# GITHUB_TOKEN cannot open a pull request that starts its own checks. GitHub
# holds workflow runs on bot-authored PRs at `action_required` until a human
# approves them, and suppresses the push event when their merge lands. A
# release made with it therefore stalls twice — once waiting for approval on
# three runs, once waiting for someone to dispatch the publish — and both
# stalls look exactly like a PR that is simply still running.
#
# A release credential is not a bot, so its PR triggers ci.yml, codeql.yml and
# workflow-security.yml through their ordinary `pull_request: [master]`
# triggers, and its merge emits the push that publishes. That is the whole
# difference between a release that completes unattended and one that does not.
if [[ -n "${AGGR_RELEASE_TOKEN:-}" ]]; then
	export GH_TOKEN="${AGGR_RELEASE_TOKEN}"
	UNATTENDED=1
else
	UNATTENDED=0
fi

# Manual release steps are announced as workflow errors, not stdout. A warning
# in a log nobody opens is why v1.1.0 sat blocked while looking green.
announce_manual_step() {
	echo "::error title=Release needs a human::$1"

	if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
		{
			echo "### ⚠️ This release will not finish by itself"
			echo
			echo "$1"
			echo
			echo "Configure an \`AGGR_RELEASE_TOKEN\` secret to remove this step."
			echo "See \`docs/build-and-release.md\`."
		} >>"${GITHUB_STEP_SUMMARY}"
	fi
}

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

# A release credential's PR already started these through their own
# `pull_request: [master]` triggers, and dispatching again would run every lane
# a second time against the same commit and report each required check twice.
#
# But "already started" is an assumption about GitHub's behaviour, and if it
# ever failed to hold, auto-merge would wait on checks that never arrive. That
# trades a loud stall for a silent one, which is the failure this whole change
# exists to remove. So the assumption is checked rather than trusted: give the
# PR's own runs a minute to register, and dispatch anyway if none did.
DISPATCH=1

if (( UNATTENDED )); then
	DISPATCH=0

	for _ in $(seq 1 6); do
		STARTED="$(gh api "repos/${REPOSITORY}/commits/${HEAD_SHA}/check-runs" \
			--jq '.total_count' 2>/dev/null || echo 0)"

		if [[ "${STARTED}" -gt 0 ]]; then
			break
		fi

		sleep 10
	done

	if [[ "${STARTED:-0}" -eq 0 ]]; then
		echo "::warning title=Dispatching version PR checks as a fallback::The version PR started no checks of its own within 60s, so they are being dispatched explicitly."
		DISPATCH=1
	fi

	# Existing is not the same as running. GitHub holds runs at
	# `action_required` until a human approves them, and a held run still
	# counts above — so the poll alone would report checks that never execute
	# and auto-merge would wait on them forever. Approval cannot be granted
	# from inside the run that needs it, so this names it instead of hanging.
	HELD="$(gh api "repos/${REPOSITORY}/actions/runs?branch=${BRANCH}&status=action_required" \
		--jq '.total_count' 2>/dev/null || echo 0)"

	if [[ "${HELD:-0}" -gt 0 ]]; then
		announce_manual_step "${HELD} workflow run(s) for version PR
#${PR_NUMBER} are held at \`action_required\` and will not start until they are
approved, even though a release credential opened the PR. Approve them at
https://github.com/${REPOSITORY}/pull/${PR_NUMBER}/checks — auto-merge is
already registered and will proceed once they pass."
	fi
fi

if (( DISPATCH )); then
	for workflow in ci.yml codeql.yml workflow-security.yml; do
		gh workflow run "${workflow}" --repo "${REPOSITORY}" --ref "${BRANCH}"
	done
fi

gh pr merge "${PR_NUMBER}" --repo "${REPOSITORY}" --auto --squash \
	--delete-branch --match-head-commit "${HEAD_SHA}"

echo "Version PR #${PR_NUMBER} is awaiting required checks for ${HEAD_SHA}."

if (( UNATTENDED )); then
	exit 0
fi

# Both stalls a GITHUB_TOKEN release hits, named at the point they are created
# rather than discovered later on a PR that looks entirely green.
announce_manual_step "Version PR #${PR_NUMBER} was opened by a bot, so its
checks are held at \`action_required\` and its merge will emit no push event.
Approve the held runs at
https://github.com/${REPOSITORY}/pull/${PR_NUMBER}/checks, then publish
v${VERSION} once it merges with:

    gh workflow run ci.yml --repo ${REPOSITORY} --ref master"

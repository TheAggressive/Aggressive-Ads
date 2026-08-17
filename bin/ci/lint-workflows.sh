#!/usr/bin/env bash
# Run the workflow gates locally, at the versions CI pins.
#
# `lanes.mjs` derives the local rehearsal from `ci.yml`, so nothing it produces
# covers `workflow-security.yml`. That left a hole big enough to walk through:
# an edit to a workflow passed `pnpm qa` and could only fail on GitHub, which is
# exactly the local/CI divergence the derivation exists to prevent. Two real
# defects reached CI that way — `secrets` used in a step `if:`, where it is not
# an available context, and an App token inheriting blanket permissions.
#
# Versions and checksums are read out of the workflow rather than repeated here.
# A second copy of a pinned version is how a local check silently stops standing
# in for the gate it mirrors.

set -euo pipefail

cd "$(dirname "$0")/../.."

WORKFLOW=".github/workflows/workflow-security.yml"
CACHE="build/tools"

# Reads `KEY: value` from the workflow's env blocks.
pin() {
	local key="$1" value

	value="$(sed -n "s/^[[:space:]]*${key}:[[:space:]]*\([^[:space:]#]*\).*/\1/p" \
		"${WORKFLOW}" | head -n1)"

	if [[ -z "${value}" ]]; then
		echo "lint-workflows: ${key} is not pinned in ${WORKFLOW}." >&2
		exit 1
	fi

	printf '%s' "${value}"
}

# Downloads a pinned release archive, verifies it, and extracts one binary.
ensure() {
	local name="$1" version="$2" sha="$3" url="$4" strip="$5"
	local binary="${CACHE}/${name}-${version}"

	if [[ -x "${binary}" ]]; then
		printf '%s' "${binary}"
		return
	fi

	mkdir -p "${CACHE}"

	local archive="${CACHE}/${name}-${version}.tar.gz"

	# A failed download must not leave a truncated file that the next run
	# treats as a cache hit.
	if ! curl --fail --silent --show-error --location "${url}" --output "${archive}"; then
		rm -f "${archive}"
		echo "lint-workflows: could not download ${name} ${version}." >&2
		exit 1
	fi

	if ! printf '%s  %s\n' "${sha}" "${archive}" | sha256sum --check --status; then
		rm -f "${archive}"
		echo "lint-workflows: ${name} ${version} failed checksum verification." >&2
		echo "lint-workflows: expected ${sha}" >&2
		exit 1
	fi

	tar -xzf "${archive}" -C "${CACHE}" --strip-components="${strip}" "${name}"
	mv "${CACHE}/${name}" "${binary}"
	chmod +x "${binary}"
	rm -f "${archive}"

	printf '%s' "${binary}"
}

ACTIONLINT_VERSION="$(pin ACTIONLINT_VERSION)"
ACTIONLINT_SHA256="$(pin ACTIONLINT_SHA256)"
ZIZMOR_VERSION="$(pin ZIZMOR_VERSION)"
ZIZMOR_SHA256="$(pin ZIZMOR_SHA256)"

actionlint="$(ensure actionlint "${ACTIONLINT_VERSION}" "${ACTIONLINT_SHA256}" \
	"https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/actionlint_${ACTIONLINT_VERSION}_linux_amd64.tar.gz" \
	0)"

zizmor="$(ensure zizmor "${ZIZMOR_VERSION}" "${ZIZMOR_SHA256}" \
	"https://github.com/zizmorcore/zizmor/releases/download/v${ZIZMOR_VERSION}/zizmor-x86_64-unknown-linux-gnu.tar.gz" \
	0)"

echo "lint-workflows: actionlint ${ACTIONLINT_VERSION}"
"${actionlint}"

# Matches the job's inputs. Its online audits need a credential; without one
# zizmor still runs its offline audits, so this reports the difference rather
# than presenting a narrower run as the same check.
echo "lint-workflows: zizmor ${ZIZMOR_VERSION}"

if [[ -z "${GH_TOKEN:-}" ]] && command -v gh >/dev/null 2>&1; then
	GH_TOKEN="$(gh auth token 2>/dev/null || true)"
	export GH_TOKEN
fi

if [[ -z "${GH_TOKEN:-}" ]]; then
	echo "lint-workflows: no GitHub credential, so zizmor's online audits are skipped here and run in CI only." >&2
fi

"${zizmor}" --min-severity low --persona regular .

echo "lint-workflows: ok"

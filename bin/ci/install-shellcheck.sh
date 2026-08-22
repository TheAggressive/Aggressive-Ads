#!/usr/bin/env bash
#
# Fetch the pinned ShellCheck binary into .cache/ci/.
#
# bin/check-shell.sh used to reach for Docker first and an ambient `shellcheck`
# second. That made the shell lane the only thing in the pre-push gate that
# needed a container, on a machine where the whole point of qa:fast is that it
# does not — and the fallback fails closed, so turning Docker off turned the
# gate red rather than turning it off. Neither is acceptable: the answer is a
# ShellCheck this repository controls.
#
# Same shape and same reasoning as install-wp-cli.sh: a pinned version, a
# checksum per platform, and a download that only becomes runnable after the
# checksum passes. The version tracks the digest-pinned image in check-shell.sh
# so a run here and a run in a container analyse with identical rules.
#
# Exit 3 means "no pinned build for this platform" rather than a failure, so
# check-shell.sh can fall back to the image instead of dying.

set -euo pipefail

cd "$(dirname "$0")/../.."

SHELLCHECK_VERSION="${SHELLCHECK_VERSION:-0.11.0}"

# sha256 of the release tarball, per platform. Recorded by downloading each and
# hashing it, not copied from a page that could change under us.
#
# Assigned to platform_sha256 rather than straight to SHELLCHECK_SHA256 so the
# override below stays reachable. Written the other way round first, the case
# block clobbered the caller's value and the mismatch branch could not be
# reached at all — a verification step nothing could prove was running.
case "$(uname -s).$(uname -m)" in
	Linux.x86_64)
		SHELLCHECK_PLATFORM='linux.x86_64'
		platform_sha256='8c3be12b05d5c177a04c29e3c78ce89ac86f1595681cab149b65b97c4e227198'
		;;
	Linux.aarch64 | Linux.arm64)
		SHELLCHECK_PLATFORM='linux.aarch64'
		platform_sha256='12b331c1d2db6b9eb13cfca64306b1b157a86eb69db83023e261eaa7e7c14588'
		;;
	Darwin.x86_64)
		SHELLCHECK_PLATFORM='darwin.x86_64'
		platform_sha256='3c89db4edcab7cf1c27bff178882e0f6f27f7afdf54e859fa041fca10febe4c6'
		;;
	Darwin.arm64 | Darwin.aarch64)
		SHELLCHECK_PLATFORM='darwin.aarch64'
		platform_sha256='56affdd8de5527894dca6dc3d7e0a99a873b0f004d7aabc30ae407d3f48b0a79'
		;;
	*)
		echo "install-shellcheck: no pinned build for $(uname -s).$(uname -m)" >&2
		exit 3
		;;
esac

# Overridable for the same reason install-wp-cli.sh allows it: a pin nobody can
# feed a wrong value to is a pin nobody can demonstrate works.
SHELLCHECK_SHA256="${SHELLCHECK_SHA256:-${platform_sha256}}"

SHELLCHECK_URL="https://github.com/koalaman/shellcheck/releases/download/v${SHELLCHECK_VERSION}/shellcheck-v${SHELLCHECK_VERSION}.${SHELLCHECK_PLATFORM}.tar.xz"

TOOL_DIR="${SHELLCHECK_INSTALL_DIR:-.cache/ci}"
TARGET="${TOOL_DIR}/shellcheck"

# The binary is what gets run, so the binary is what gets checked on the fast
# path. The tarball checksum above governs what may ever become that binary; a
# version match afterwards catches a stale cache from an earlier pin.
if [ -x "${TARGET}" ] && "${TARGET}" --version 2>/dev/null | grep -qx "version: ${SHELLCHECK_VERSION}"; then
	[ -n "${SHELLCHECK_SKIP_INFO:-}" ] || echo "install-shellcheck: ${TARGET} already at ${SHELLCHECK_VERSION}"
	exit 0
fi

if ! command -v tar >/dev/null 2>&1; then
	echo "install-shellcheck: tar is required to unpack the release." >&2
	exit 3
fi

mkdir -p "${TOOL_DIR}"

staging="$(mktemp -d)"
trap 'rm -rf "${staging}"' EXIT

echo "install-shellcheck: fetching ShellCheck ${SHELLCHECK_VERSION} (${SHELLCHECK_PLATFORM})"
curl --fail --silent --show-error --location --retry 3 \
	--output "${staging}/shellcheck.tar.xz" "${SHELLCHECK_URL}"

if ! echo "${SHELLCHECK_SHA256}  ${staging}/shellcheck.tar.xz" | sha256sum --check --status; then
	echo "install-shellcheck: checksum mismatch for ${SHELLCHECK_URL}" >&2
	echo "  expected ${SHELLCHECK_SHA256}" >&2
	echo "  actual   $(sha256sum "${staging}/shellcheck.tar.xz" | awk '{print $1}')" >&2
	exit 1
fi

tar -xJf "${staging}/shellcheck.tar.xz" -C "${staging}"

unpacked="${staging}/shellcheck-v${SHELLCHECK_VERSION}/shellcheck"

if [ ! -f "${unpacked}" ]; then
	echo "install-shellcheck: ${SHELLCHECK_URL} did not contain the expected binary." >&2
	exit 1
fi

chmod 0755 "${unpacked}"
mv "${unpacked}" "${TARGET}"

echo "install-shellcheck: ${TARGET} (${SHELLCHECK_VERSION})"

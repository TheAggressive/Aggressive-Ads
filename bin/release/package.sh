#!/usr/bin/env bash
#
# Builds the distributable ZIP.
#
# The output is the product. What is in this repository is not what a site
# owner installs, and the difference between the two is where mistakes hide:
# a stray src/ or node_modules/ is historically how a 4 MB plugin becomes a
# 400 MB one, and a committed .env is how a secret ships.
#
# So the excludes are a denylist *and* the result is checked against a
# hard-fail list afterwards. A denylist alone fails silently when somebody adds
# a directory nobody thought to exclude.
#
# Usage: bin/release/package.sh [version]

set -euo pipefail

cd "$(dirname "$0")/../.."

SLUG=laao-advertiser-portal
PLUGIN_FILE="${SLUG}.php"
BUILD_DIR=release
STAGING="${BUILD_DIR}/${SLUG}"

# Paths that must never reach the package. Checked after staging, not merely
# excluded before it.
PACKAGE_FORBIDDEN=(
	node_modules
	vendor
	tests
	bin
	src
	.git
	.github
	docs
	CLAUDE.md
)

# Files without which the plugin does not work. inc/class-autoloader.php is
# here because it is the production autoloader — the plugin ships without
# vendor/, so dropping it produces a fatal naming a class rather than the
# missing loader. See docs/adr/0012-own-autoloader-in-production.md.
PACKAGE_REQUIRED=(
	"${PLUGIN_FILE}"
	uninstall.php
	inc/class-autoloader.php
	inc/class-plugin.php
)

header_version() {
	grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${PLUGIN_FILE}" | awk '{print $NF}'
}

VERSION="${1:-$(header_version)}"

if [ -z "${VERSION}" ]; then
	echo "Could not determine a version from ${PLUGIN_FILE}." >&2
	exit 1
fi

ZIP="${BUILD_DIR}/${SLUG}-${VERSION}.zip"

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING}"

# A single top-level directory named for the slug, because that is what
# WordPress unpacks and what determines the installed folder name.
rsync -a \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.wp-env*' \
	--exclude='.editorconfig' \
	--exclude='.gitignore' \
	--exclude='.phpunit.cache/' \
	--exclude='node_modules/' \
	--exclude='vendor/' \
	--exclude='tests/' \
	--exclude='bin/' \
	--exclude='src/' \
	--exclude='docs/' \
	--exclude='CLAUDE.md' \
	--exclude='release/' \
	--exclude='composer.*' \
	--exclude='package.json' \
	--exclude='pnpm-*' \
	--exclude='phpcs.xml*' \
	--exclude='phpstan.neon*' \
	--exclude='phpunit*.xml*' \
	--exclude='*.zip' \
	--exclude='*.sha256' \
	--exclude='.env*' \
	--exclude='*.log' \
	./ "${STAGING}/"

failed=0

for path in "${PACKAGE_FORBIDDEN[@]}"; do
	if [ -e "${STAGING}/${path}" ]; then
		echo "FORBIDDEN path reached the package: ${path}" >&2
		failed=1
	fi
done

for path in "${PACKAGE_REQUIRED[@]}"; do
	if [ ! -f "${STAGING}/${path}" ]; then
		echo "REQUIRED file missing from the package: ${path}" >&2
		failed=1
	fi
done

# Anything that looks like a credential, wherever it came from.
secrets=$(
	find "${STAGING}" -type f \( -name '.env*' -o -name '*.pem' -o -name '*.key' -o -name 'id_rsa*' \) 2>/dev/null || true
)

if [ -n "${secrets}" ]; then
	echo "Possible secrets in the package:" >&2
	echo "${secrets}" >&2
	failed=1
fi

if [ "${failed}" -ne 0 ]; then
	echo >&2
	echo "Packaging aborted. See docs/build-and-release.md." >&2
	exit 1
fi

( cd "${BUILD_DIR}" && zip -rq "$(basename "${ZIP}")" "${SLUG}" -x '*.DS_Store' )

# A checksum beside the archive, so the verifier can prove it is checking the
# file that was actually built.
( cd "${BUILD_DIR}" && sha256sum "$(basename "${ZIP}")" > "$(basename "${ZIP}").sha256" )

echo "package: ${ZIP}"
echo "version: ${VERSION}"
echo "size:    $(du -h "${ZIP}" | cut -f1)"

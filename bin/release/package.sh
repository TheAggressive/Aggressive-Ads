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

SLUG=aggressive-ads
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
	types
	.cache
	.git
	.github
	.husky
	.prettierignore
	.prettierrc.cjs
	.pnpm-store
	commitlint.config.mjs
	docs
	CLAUDE.md
	test-results
	playwright-report
)

# Files without which the plugin does not work. inc/class-autoloader.php is
# here because it is the production autoloader — the plugin ships without
# vendor/, so dropping it produces a fatal naming a class rather than the
# missing loader. See docs/build-and-release.md.
PACKAGE_REQUIRED=(
	"${PLUGIN_FILE}"
	uninstall.php
	inc/class-autoloader.php
	inc/class-plugin.php
	inc/class-service-registrar.php
	dist/interactivity/dialog.js
	dist/interactivity/logic.js
	dist/interactivity/wizard.js
	dist/interactivity/autosave.js
	dist/interactivity/upload.js
	dist/blocks/placement/block.json
	dist/blocks/placement/index.js
	dist/blocks/placement/index.asset.php
	dist/blocks/placement/index.css
	dist/blocks/placement/style-index.css
	dist/blocks/placement/view.js
	dist/blocks/placement/view.asset.php
	dist/styles/portal.css
)

# The version this build is of, in order of authority.
#
# The tag is the version. The plugin header is a copy of it, updated by hand and
# therefore usually behind — it was two releases stale within a day of the
# release process being changed. Falling back to that copy meant a hand-built
# archive could be stamped 1.1.1 while containing 1.2.1 code, and be named
# accordingly, with nothing anywhere saying otherwise.
#
# `git describe --tags --abbrev=0` reads the same tag the release was cut from,
# so a build of a given commit produces the right version whatever the checkout
# happens to declare. The header is only consulted when there is no repository
# to ask, which is the case for a build from an extracted source archive.
tag_version() {
	git describe --tags --abbrev=0 2>/dev/null | sed 's/^v//'
}

header_version() {
	grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${PLUGIN_FILE}" | awk '{print $NF}'
}

VERSION="${1:-${AGGR_RELEASE_VERSION:-$(tag_version)}}"
VERSION="${VERSION:-$(header_version)}"

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: ${VERSION}" >&2
	exit 2
fi

ZIP="${BUILD_DIR}/${SLUG}-${VERSION}.zip"

# Compiled catalogs are build output — gitignored, like dist/ — so they have to
# be produced here or the archive ships .po files WordPress cannot read and the
# site silently renders English. verify-package.sh refuses such an archive, but
# failing at package time is cheaper than failing at verification time.
#
# A no-op until a locale .po is committed, so this costs nothing today and
# starts working by itself on the day one is.
bash bin/i18n/compile.sh

rm -rf "${BUILD_DIR}"
mkdir -p "${STAGING}"

# A single top-level directory named for the slug, because that is what
# WordPress unpacks and what determines the installed folder name.
rsync -a \
	--exclude='.cache/' \
	--exclude='.git/' \
	--exclude='.github/' \
	--exclude='.husky/' \
	--exclude='.prettierignore' \
	--exclude='.prettierrc*' \
	--exclude='.editorconfig' \
	--exclude='.gitignore' \
	--exclude='.phpunit.cache/' \
	--exclude='node_modules/' \
	--exclude='.pnpm-store/' \
	--exclude='vendor/' \
	--exclude='tests/' \
	--exclude='test-results/' \
	--exclude='playwright-report/' \
	--exclude='bin/' \
	--exclude='src/' \
	--exclude='types/' \
	--exclude='docs/' \
	--exclude='CLAUDE.md' \
	--exclude='release/' \
	--exclude='composer.*' \
	--exclude='commitlint.config.*' \
	--exclude='package.json' \
	--exclude='pnpm-*' \
	--exclude='.npmrc' \
	--exclude='phpcs.xml*' \
	--exclude='phpstan.neon*' \
	--exclude='phpunit*.xml*' \
	--exclude='playwright.config.ts' \
	--exclude='tsconfig.json' \
	--exclude='jest.config.js' \
	--exclude='eslint.config.js' \
	--exclude='.stylelintrc.json' \
	--exclude='webpack*.mjs' \
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

# Stamped only after the required-file gate has run: sed on a missing
# dist/blocks/placement/block.json dies with "can't read", burying the
# diagnostic that names the unbuilt file.
#
# semantic-release owns the published version. Stamp only the staged tree so
# the checkout is never rewritten by the release bot and the bytes verified by
# CI are the bytes WordPress ultimately installs.
sed -i -E \
	"s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*).*/\1${VERSION}/" \
	"${STAGING}/${PLUGIN_FILE}"
sed -i -E \
	"s/define\( 'AGGR_VERSION', '[^']+' \);/define( 'AGGR_VERSION', '${VERSION}' );/" \
	"${STAGING}/${PLUGIN_FILE}"
sed -i -E \
	"0,/\"version\": \"[^\"]+\"/s//\"version\": \"${VERSION}\"/" \
	"${STAGING}/dist/blocks/placement/block.json"
# README ships, so it would otherwise reach users still reading
# 0.0.0-development — the one stamped file that is documentation rather than
# code, and the easiest to forget precisely because nothing executes it.
sed -i -E \
	"s/^\| Plugin \| Aggressive Ads \`[^\`]+\` \|$/| Plugin | Aggressive Ads \`${VERSION}\` |/" \
	"${STAGING}/README.md"

staged_header_version=$(
	grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${STAGING}/${PLUGIN_FILE}" | awk '{print $NF}'
)
staged_constant_version=$(
	grep -m1 -oE "define\( 'AGGR_VERSION', '[^']+'" "${STAGING}/${PLUGIN_FILE}" | awk -F"'" '{print $4}'
)
staged_block_version=$(
	grep -m1 -oE '"version": "[^"]+"' "${STAGING}/dist/blocks/placement/block.json" | cut -d'"' -f4
)
staged_readme_version=$(
	# A literal grep pattern, not a template: the backticks are Markdown and
	# there is nothing here for the shell to expand.
	# shellcheck disable=SC2016
	grep -m1 -oE '^\| Plugin \| Aggressive Ads `[^`]+`' "${STAGING}/README.md" | cut -d'`' -f2
)
if [[ "${staged_header_version}" != "${VERSION}" || "${staged_constant_version}" != "${VERSION}" || "${staged_block_version}" != "${VERSION}" || "${staged_readme_version}" != "${VERSION}" ]]; then
	echo "Version stamp did not apply to the staged plugin." >&2
	exit 1
fi

# Normalize modes, timestamps, and archive order. `zip -X` alone does not make
# archives reproducible because filesystem metadata and traversal order remain.
SOURCE_EPOCH="${SOURCE_DATE_EPOCH:-$(git log -1 --format=%ct)}"
if [[ ! "${SOURCE_EPOCH}" =~ ^[0-9]+$ || "${SOURCE_EPOCH}" -lt 315532800 ]]; then
	echo "SOURCE_DATE_EPOCH must be a ZIP-compatible Unix timestamp." >&2
	exit 2
fi

find "${STAGING}" -type d -exec chmod 0755 {} +
find "${STAGING}" -type f -exec chmod 0644 {} +
find "${STAGING}" -exec touch -h -d "@${SOURCE_EPOCH}" {} +

(
	cd "${BUILD_DIR}"
	TZ=UTC find "${SLUG}" -print |
		LC_ALL=C sort |
		TZ=UTC zip -qX "$(basename "${ZIP}")" -@
)

# A checksum beside the archive, so the verifier can prove it is checking the
# file that was actually built.
( cd "${BUILD_DIR}" && sha256sum "$(basename "${ZIP}")" > "$(basename "${ZIP}").sha256" )

echo "package: ${ZIP}"
echo "version: ${VERSION}"
echo "size:    $(du -h "${ZIP}" | cut -f1)"

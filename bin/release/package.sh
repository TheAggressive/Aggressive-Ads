#!/usr/bin/env bash
#
# Builds the distributable ZIP.
#
# The output is the product. What is in this repository is not what a site
# owner installs, and the difference between the two is where mistakes hide:
# a stray src/ or node_modules/ is historically how a 4 MB plugin becomes a
# 400 MB one, and a committed .env is how a secret ships.
#
# So the archive is built from an *allowlist*: the paths below are copied, and
# nothing else can reach it. The result is still checked against a hard-fail
# list afterwards, as a second opinion rather than as the only one.
#
# It was a denylist until v1.4.0, and this file already warned why that was
# wrong — "a denylist alone fails silently when somebody adds a directory
# nobody thought to exclude". It then did exactly that, five times over: the
# 1.4.0 archive shipped `.playwright-results-artifact/` (browser traces from
# the artifact-acceptance run), `junit.xml`, `compose.yml`, `.releaserc.json`
# and `patches/`. None was excluded because none existed when the list was
# written, and the hard-fail list could not catch them either, being a list of
# names somebody had already thought of.
#
# An allowlist inverts the failure. Forgetting to add a new plugin directory
# means it is missing from the archive, which PACKAGE_REQUIRED catches loudly;
# forgetting to exclude a new dev file means nothing at all.
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
#
# languages/ is the one directory that ships selectively: the compiled .mo and
# .json go, the .po sources and the directory's own README stay behind.
# WordPress reads a .po at no point, and four locales of source catalogue is
# half a megabyte in every install, growing with each language added.
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
	# A dev artifact rather than a directory, and the reason it is listed:
	# package.sh rsyncs the working tree, not `git ls-files`, so a gitignored
	# file still reaches the archive. This one shipped — 109 KB of PHPUnit's
	# result cache — and made the "reproducible archive" claim false, because
	# its contents depend on which tests that machine last ran.
	.phpunit.result.cache
	.phpunit.cache
)

# Everything the archive is built from. Nothing outside this list can ship.
#
# `languages/` is deliberately absent: it is the one directory that ships
# selectively, and it is copied by the explicit rule further down. WordPress
# reads a .po at no point, and four locales of source catalogue is half a
# megabyte in every install, growing with each language added.
PACKAGE_CONTENTS=(
	"${PLUGIN_FILE}"
	uninstall.php
	README.md
	SECURITY.md
	inc
	templates
	dist
	assets
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
# Copy the allowlist, and nothing else. A path that is not named here cannot
# reach the archive by being forgotten.
for entry in "${PACKAGE_CONTENTS[@]}"; do
	if [ ! -e "${entry}" ]; then
		echo "Missing packaged path: ${entry}" >&2
		exit 2
	fi

	rsync -a --relative "./${entry}" "${STAGING}/"
done

# languages/ ships selectively: the compiled catalogs WordPress reads, and the
# POT translators start from. The .po sources stay behind — WordPress reads one
# at no point, and they are half a megabyte per four locales.
#
# `find -exec cp` rather than a glob, so a locale that has not been compiled
# yet leaves the loop empty instead of copying a literal `*.mo`.
mkdir -p "${STAGING}/languages"
find languages -maxdepth 1 -type f \
	\( -name '*.mo' -o -name '*.json' -o -name '*.pot' \) \
	-exec cp {} "${STAGING}/languages/" \;

failed=0

# Nothing outside the allowlist, asserted rather than assumed.
#
# The copy above cannot bring an unlisted path in, so this is guarding against
# a different mistake: something *else* writing into the staging directory —
# a build step, a stray redirect — between the copy and the zip. It costs one
# `ls` and it is the check that would have caught the 1.4.0 leaks whatever
# their cause.
allowed=$(printf '%s\n' "${PACKAGE_CONTENTS[@]}" languages | sort -u)
staged=$(find "${STAGING}" -mindepth 1 -maxdepth 1 -exec basename {} \; | sort -u)

while IFS= read -r entry; do
	if ! grep -qxF "${entry}" <<< "${allowed}"; then
		echo "UNEXPECTED path reached the package: ${entry}" >&2
		failed=1
	fi
done <<< "${staged}"

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

#!/usr/bin/env bash
#
# Verifies the built ZIP.
#
# **Everything here is asserted against the archive, not against the staging
# directory.** The staging directory is what the script that just ran believes
# it produced; the ZIP is what a site owner installs. Checking the former and
# shipping the latter is how a packaging bug survives its own test.
#
# Usage: bin/release/verify-package.sh [version]

set -euo pipefail

cd "$(dirname "$0")/../.."

SLUG=aggressive-ads
PLUGIN_FILE="${SLUG}.php"
BUILD_DIR=release

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
	composer.json
	package.json
	phpcs.xml.dist
	phpstan.neon
	test-results
	playwright-report
	playwright.config.ts
	# Dev artifacts rather than directories, and the reason they are listed:
	# package.sh rsyncs the working tree, not `git ls-files`, so a gitignored
	# file still reaches the archive. .phpunit.result.cache did — 109 KB of
	# PHPUnit's result cache — and made the "reproducible archive" claim false,
	# because its contents depend on which tests that machine last ran.
	.phpunit.result.cache
	.phpunit.cache
)

# Files with no import graph pointing at them, so nothing else notices when one
# stops shipping. The autoloader is ours rather than Composer's; the templates
# and the stylesheet are located by path at render time, and a portal missing
# base.php is a fatal on the advertiser's first page load, not a build error.
PACKAGE_REQUIRED=(
	"${PLUGIN_FILE}"
	uninstall.php
	inc/class-autoloader.php
	inc/class-plugin.php
	inc/class-service-registrar.php
	inc/Update/class-update-http-client.php
	inc/Update/class-release-repository.php
	inc/Update/class-package-verifier.php
	inc/Update/class-plugin-updates.php
	inc/Admin/class-review-data.php
	inc/Admin/class-review-screen.php
	inc/Admin/class-menu.php
	inc/Admin/class-settings-screen.php
	inc/Assets/class-brand-styles.php
	inc/Core/class-settings.php
	inc/Domain/class-contrast.php
	inc/Domain/class-settings-schema.php
	inc/Notification/class-notification-service.php
	inc/Notification/class-notification-delivery.php
	inc/Notification/class-password-notification.php
	inc/Notification/class-organization-notification.php
	inc/Portal/class-organization-actions.php
	inc/Portal/class-password-actions.php
	inc/Repository/class-org-access-repository.php
	inc/Repository/class-campaign-lifecycle-repository.php
	inc/Workflow/class-organization-membership.php
	inc/Workflow/class-password-reset.php
	templates/portal/forgot-password.php
	templates/portal/set-password.php
	templates/portal/screens/forgot-password.php
	templates/portal/screens/set-password.php
	inc/Portal/class-campaign-actions.php
	inc/Portal/class-creative-actions.php
	inc/Portal/class-signup-actions.php
	inc/REST/class-packages-controller.php
	inc/REST/class-organizations-controller.php
	inc/Repository/class-package-repository.php
	inc/Admin/class-package-data.php
	inc/Admin/class-package-screen.php
	inc/Workflow/class-package-manager.php
	inc/Repository/class-user-repository.php
	inc/Workflow/class-campaign-editor.php
	inc/Workflow/class-campaign-copier.php
	inc/Workflow/class-creative-manager.php
	inc/Workflow/class-review-actions.php
	inc/Workflow/class-review-readiness.php
	inc/Workflow/class-advertiser-registration.php
	inc/Workflow/class-fill-service.php
	inc/Workflow/class-fill-token.php
	inc/Workflow/class-fill-cache.php
	inc/Workflow/class-delivery-request.php
	inc/Workflow/class-click-hop.php
	inc/Workflow/class-placement-slot.php
	inc/Workflow/placement-slot-function.php
	inc/Workflow/class-event-retention.php
	inc/REST/class-fill-controller.php
	inc/REST/class-beacon-controller.php
	inc/Repository/class-event-repository.php
	inc/Repository/class-rollup-repository.php
	assets/icon.svg
	assets/svg/aggressive-ads-icon.svg
	assets/fonts/OFL.txt
	dist/styles/portal.css
	dist/styles/admin.css
	dist/interactivity/scroll-lock.js
	dist/interactivity/helpers.js
	dist/interactivity/logic.js
	dist/interactivity/dialog.js
	dist/interactivity/dialog.asset.php
	dist/interactivity/wizard.js
	dist/interactivity/autosave.js
	dist/interactivity/upload.js
	dist/blocks-interactivity/ad-slot/block.json
	dist/blocks-interactivity/ad-slot/index.js
	dist/blocks-interactivity/ad-slot/index.asset.php
	dist/blocks-interactivity/ad-slot/view.js
	dist/blocks-interactivity/ad-slot/view.asset.php
	dist/admin/settings.js
	dist/admin/settings.asset.php
	dist/admin/packages.js
	dist/admin/packages.asset.php
	dist/admin/organizations.js
	dist/admin/organizations.asset.php
	dist/admin/inventory.js
	dist/admin/inventory.asset.php
	dist/admin/review.js
	dist/admin/review.asset.php
	templates/portal/base.php
	templates/portal/base-bare.php
	templates/portal/login.php
	templates/portal/screens/login.php
	templates/portal/partials/campaign-ad-updates.php
	templates/portal/partials/campaign-overlays.php
	templates/portal/partials/campaign-editor-modules.php
	templates/portal/signup.php
	templates/portal/screens/signup.php
	templates/portal/dashboard.php
	templates/portal/campaigns.php
	templates/portal/campaigns-detail.php
	templates/portal/403.php
	templates/portal/404.php
	templates/portal/partials/rail.php
	templates/portal/partials/brand.php
	templates/portal/partials/campaign-table.php
	templates/portal/partials/icon.php
	templates/portal/screens/dashboard.php
	templates/portal/screens/campaigns.php
	templates/portal/screens/campaign.php
	templates/portal/screens/campaign-not-found.php
	templates/portal/screens/403.php
	templates/portal/organization.php
	templates/portal/account.php
	templates/portal/help.php
	templates/portal/screens/organization.php
	templates/portal/screens/account.php
	templates/portal/screens/help.php
	templates/portal/screens/404.php
)

header_version() {
	grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${PLUGIN_FILE}" | awk '{print $NF}'
}

VERSION="${1:-${AGGR_RELEASE_VERSION:-$(header_version)}}"
ZIP="${BUILD_DIR}/${SLUG}-${VERSION}.zip"

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: ${VERSION}" >&2
	exit 2
fi

if [ ! -f "${ZIP}" ]; then
	echo "No package at ${ZIP}. Run: pnpm release:package" >&2
	exit 1
fi

failed=0
fail() {
	echo "  FAIL: $1" >&2
	failed=1
}

# 1. The archive is the one that was built.
if [ -f "${ZIP}.sha256" ]; then
	( cd "${BUILD_DIR}" && sha256sum -c --quiet "$(basename "${ZIP}").sha256" ) \
		|| fail "checksum does not match the sidecar"
else
	fail "no checksum sidecar beside the archive"
fi

listing=$(unzip -Z1 "${ZIP}")

# 2. Exactly one top-level directory, named for the slug. WordPress unpacks
#    whatever is at the root, so two entries here installs two plugins or none.
top_level=$(echo "${listing}" | awk -F/ '{print $1}' | sort -u)

if [ "${top_level}" != "${SLUG}" ]; then
	fail "expected one top-level directory named ${SLUG}, found: $(echo "${top_level}" | tr '\n' ' ')"
fi

# 3. Nothing that should not ship.
for path in "${PACKAGE_FORBIDDEN[@]}"; do
	if echo "${listing}" | grep -qE "^${SLUG}/${path}(/|$)"; then
		fail "forbidden path in the archive: ${path}"
	fi
done

# 4. Everything that must.
#
# A miss prints what the archive *does* hold in that directory. One lost file
# and a directory that was never built are the same message otherwise, and they
# are completely different problems: the first is a packaging or reproducibility
# fault, the second means a build step did not run. Distinguishing them took a
# re-run and an investigation once already — see docs/known-issues.md.
for path in "${PACKAGE_REQUIRED[@]}"; do
	if echo "${listing}" | grep -qxF "${SLUG}/${path}"; then
		continue
	fi

	fail "required file missing from the archive: ${path}"

	directory="$(dirname "${path}")"
	siblings="$(echo "${listing}" | grep -E "^${SLUG}/${directory}/[^/]+$" || true)"

	if [ -z "${siblings}" ]; then
		echo "        ${directory}/ is empty or absent, so a build step did not run." >&2
		continue
	fi

	# Bounded. A directory with hundreds of entries would bury the finding it is
	# supposed to explain, and the count is the part that identifies the case.
	echo "        ${directory}/ holds $(printf '%s\n' "${siblings}" | wc -l | tr -d ' ') entries:" >&2
	printf '%s\n' "${siblings}" | head -12 | sed "s|^${SLUG}/${directory}/|          |" >&2
done

extracted=$(mktemp -d)
trap 'rm -rf "${extracted}"' EXIT

unzip -qq "${ZIP}" -d "${extracted}"
root="${extracted}/${SLUG}"

# 5. The header version is the version being released. A ZIP named 1.2.0 whose
#    header says 1.1.0 updates to a version WordPress then offers to update
#    again, forever.
packaged_version=$(grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${root}/${PLUGIN_FILE}" | awk '{print $NF}')
packaged_constant=$(
	grep -m1 -oE "define\( 'AGGR_VERSION', '[^']+'" "${root}/${PLUGIN_FILE}" | awk -F"'" '{print $4}'
)
packaged_block_version=$(
	grep -m1 -oE '"version": "[^"]+"' "${root}/dist/blocks-interactivity/ad-slot/block.json" | cut -d'"' -f4
)

if [ "${packaged_version}" != "${VERSION}" ]; then
	fail "header says ${packaged_version}, archive is ${VERSION}"
fi

if [ "${packaged_constant}" != "${VERSION}" ]; then
	fail "AGGR_VERSION says ${packaged_constant}, archive is ${VERSION}"
fi

if [ "${packaged_block_version}" != "${VERSION}" ]; then
	fail "placement block says ${packaged_block_version}, archive is ${VERSION}"
fi

# 6. Every PHP file parses. A syntax error in a shipped file is a white screen
#    on somebody else's site, and it costs two seconds to rule out.
while IFS= read -r file; do
	php -l "${file}" >/dev/null 2>&1 || fail "syntax error in ${file#"${root}/"}"
done < <(find "${root}" -name '*.php')

# 7. Every locale the repository carries reached the archive compiled.
#
#    Skipping the compile step is invisible: the site simply renders English,
#    and the first report arrives weeks later from a user.
#
#    Deliberately iterating the **source** catalogs rather than the archive's.
#    The archive no longer ships .po files — WordPress never reads one — and a
#    loop over `find "${root}" -name '*.po'` would then match nothing and report
#    success over a release with no translations in it at all. Anchoring on the
#    committed catalogs also catches the case the old form could not: a locale
#    whose .mo never made it into the package, where both files are absent from
#    the archive and there is nothing left to notice.
while IFS= read -r po; do
	locale="$(basename "${po}" .po)"
	[ -f "${root}/languages/${locale}.mo" ] || fail "languages/${locale}.mo is missing from the archive"
done < <(find languages -maxdepth 1 -name '*.po' 2>/dev/null)

# 8. Built assets are present whenever there are sources to build them from.
#    Tied to a real fact rather than skipped: the day src/ appears, this starts
#    asserting without anybody remembering to enable it.
if [ -d src ] && [ ! -d "${root}/dist" ]; then
	fail "src/ exists in the repository but the archive has no dist/"
fi

if [ "${failed}" -ne 0 ]; then
	echo >&2
	echo "Package verification failed. See docs/build-and-release.md." >&2
	exit 1
fi

echo "verify-package: ok"
echo "  archive: ${ZIP}"
echo "  version: ${VERSION}"
echo "  files:   $(echo "${listing}" | wc -l | tr -d ' ')"

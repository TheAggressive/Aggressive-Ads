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

SLUG=laao-advertiser-portal
PLUGIN_FILE="${SLUG}.php"
BUILD_DIR=release

PACKAGE_FORBIDDEN=(
	node_modules
	vendor
	tests
	bin
	src
	types
	.git
	.github
	.pnpm-store
	docs
	CLAUDE.md
	composer.json
	package.json
	phpcs.xml.dist
	phpstan.neon
	test-results
	playwright-report
	playwright.config.ts
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
	inc/Repository/class-package-repository.php
	inc/Repository/class-user-repository.php
	inc/Workflow/class-campaign-editor.php
	inc/Workflow/class-creative-manager.php
	inc/Workflow/class-review-actions.php
	inc/Workflow/class-review-readiness.php
	inc/Workflow/class-advertiser-registration.php
	assets/icon.svg
	dist/styles/portal.css
	dist/styles/admin.css
	dist/interactivity/scroll-lock.js
	dist/interactivity/helpers.js
	dist/interactivity/dialog.js
	dist/interactivity/dialog.asset.php
	templates/admin/review-queue.php
	templates/admin/review-campaign.php
	templates/portal/base.php
	templates/portal/base-bare.php
	templates/portal/login.php
	templates/portal/screens/login.php
	templates/portal/partials/campaign-ad-updates.php
	templates/portal/partials/creative-replace-dialogs.php
	templates/portal/signup.php
	templates/portal/screens/signup.php
	templates/portal/dashboard.php
	templates/portal/campaigns.php
	templates/portal/campaigns-detail.php
	templates/portal/403.php
	templates/portal/404.php
	templates/portal/partials/rail.php
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

VERSION="${1:-$(header_version)}"
ZIP="${BUILD_DIR}/${SLUG}-${VERSION}.zip"

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
for path in "${PACKAGE_REQUIRED[@]}"; do
	if ! echo "${listing}" | grep -qxF "${SLUG}/${path}"; then
		fail "required file missing from the archive: ${path}"
	fi
done

extracted=$(mktemp -d)
trap 'rm -rf "${extracted}"' EXIT

unzip -qq "${ZIP}" -d "${extracted}"
root="${extracted}/${SLUG}"

# 5. The header version is the version being released. A ZIP named 1.2.0 whose
#    header says 1.1.0 updates to a version WordPress then offers to update
#    again, forever.
packaged_version=$(grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${root}/${PLUGIN_FILE}" | awk '{print $NF}')

if [ "${packaged_version}" != "${VERSION}" ]; then
	fail "header says ${packaged_version}, archive is ${VERSION}"
fi

# 6. Every PHP file parses. A syntax error in a shipped file is a white screen
#    on somebody else's site, and it costs two seconds to rule out.
while IFS= read -r file; do
	php -l "${file}" >/dev/null 2>&1 || fail "syntax error in ${file#"${root}/"}"
done < <(find "${root}" -name '*.php')

# 7. Every translation has been compiled. Skipping the compile step is
#    invisible: the site simply renders English, and the first report arrives
#    weeks later from a user.
while IFS= read -r po; do
	[ -f "${po%.po}.mo" ] || fail "no compiled .mo for ${po#"${root}/"}"
done < <(find "${root}" -name '*.po' 2>/dev/null)

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

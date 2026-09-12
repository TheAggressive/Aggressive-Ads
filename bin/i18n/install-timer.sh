#!/usr/bin/env bash
#
# Installs the nightly translation timer as a systemd *user* unit.
#
# A user unit, not a system one: the run needs this checkout, this user's git
# credentials and this user's .env.local, none of which a root service has.
#
# Run it once. It is safe to run again — it overwrites the units and restarts
# the timer, which is how you pick up an edit to either file.
#
# To stop: systemctl --user disable --now aggressive-ads-i18n.timer

set -euo pipefail

# shellcheck source=lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

readonly UNIT_DIR="${XDG_CONFIG_HOME:-${HOME}/.config}/systemd/user"
readonly SRC="${AGGR_I18N_DIR}/systemd"

command -v systemctl > /dev/null 2>&1 || aggr_i18n_die "systemd is not available. See docs/i18n.md for the cron and Windows Task Scheduler alternatives."

if ! systemctl --user is-system-running > /dev/null 2>&1; then
	aggr_i18n_info "Warning: 'systemctl --user' is not reporting a running system."
	aggr_i18n_info "On WSL this usually means systemd is off; see docs/i18n.md."
fi

mkdir -p "${UNIT_DIR}"

# The unit has to name an absolute path, and the path is wherever this
# checkout happens to be — so it is stamped in at install time rather than
# committed as somebody's home directory.
sed "s|@PLUGIN_ROOT@|${AGGR_PLUGIN_ROOT}|g" \
	"${SRC}/aggressive-ads-i18n.service" > "${UNIT_DIR}/aggressive-ads-i18n.service"
cp "${SRC}/aggressive-ads-i18n.timer" "${UNIT_DIR}/aggressive-ads-i18n.timer"

systemctl --user daemon-reload
systemctl --user enable --now aggressive-ads-i18n.timer

aggr_i18n_info "Installed. The timer is enabled and running."
systemctl --user list-timers aggressive-ads-i18n.timer --no-pager || true

cat <<'NOTES'

  Next run          systemctl --user list-timers aggressive-ads-i18n.timer
  Run it now        systemctl --user start aggressive-ads-i18n.service
  Watch it          journalctl --user -u aggressive-ads-i18n.service -f
  Stop it           systemctl --user disable --now aggressive-ads-i18n.timer

  The run commits to a scratch worktree and pushes nothing. To have it open a
  pull request as well, add this to .env.local:

      I18N_AUTO_PR=1

  If the model moves, its address lives in .env.local as I18N_LOCAL_URL. The
  timer reads that file on every run, so nothing here needs reinstalling.

NOTES

# ADR-0025 — One Advertising menu; submenus keep distinct caps

**Status:** Accepted — 2026-08-12

## Context

Review, Organizations, and Inventory each registered a top-level megaphone.
A reviewer should not see Settings. An operations role should not need
`aggr_review_campaigns` to map placements. Unifying the *shell* without
unifying the *capabilities* is the requirement in
[suite-roadmap.md](../suite-roadmap.md).

## Decision

**One parent slug: `aggr`.** Menu title is the Brand product name (default
“Advertising”). Position 26, dashicon `megaphone`.

Submenus, each with its own capability:

| Sidebar | Slug | Capability |
|---|---|---|
| Review | `aggr-review` | `aggr_review_campaigns` |
| Organizations | `aggr-organizations` | `aggr_manage_orgs` |
| Inventory | `aggr-placement-mapping` | `aggr_manage_placements` |
| Packages | `aggr-packages` | `aggr_manage_packages` |
| Settings | `aggr-settings` | `aggr_manage_settings` |

Page slugs do not change, so existing bookmarks and e2e URLs keep working.
Inventory is the sidebar label; the screen heading stays “Ad delivery mappings”.

The parent capability is `aggr_access_staff`, **derived** at `user_has_cap`
when the user holds any submenu cap. It is not granted on a role, so a
future placements-only role sees the shell without a second install-time
grant. Clicking the parent redirects to the first submenu the user can
access. The duplicate parent submenu WordPress inserts is removed.

Packages are [ADR-0028](0028-staff-package-catalogue.md). A missing module
(ADR-0023) means the Inventory submenu is not registered.

## Consequences

- Reviewers see Advertising → Review, nothing else from this plugin.
- Administrators see the full tree, including Settings.
- Advertisers still never reach wp-admin (`Admin_Guard`).

## Alternatives rejected

**Parent cap = `aggr_review_campaigns`.** Placement managers would not see
the menu at all.

**Parent cap = `manage_options`.** Reviewers lose the queue.

**Granting `aggr_access_staff` on roles.** Every new staff role has to
remember a shell cap that is a function of the others.

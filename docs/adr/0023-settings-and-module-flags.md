# ADR-0023 — Settings live in one schema; a disabled module is absent

**Status:** Accepted — 2026-08-12; amended 2026-08-12 by [0026](0026-native-delivery.md); amended 2026-08-13 by [0030](0030-reporting-from-native-rollups.md); amended 2026-08-13 by [0031](0031-native-is-the-only-publisher.md); amended 2026-08-13 by [0033](0033-native-delivery-is-not-a-staff-module.md)

## Context

`aggr_manage_settings` has existed since the capability vocabulary landed and
has never gated a surface. Product flags (signup, future billing, native
delivery, reporting) were either WordPress core options or not stored at all.
A second, drifted default — the settings form showing one value while the
site behaves as another — is the failure this decision exists to prevent.

## Decision

**One autoloaded option, `aggr_settings`, read and written only through
`Core\Settings`.** The shape is declared once in `Domain\Settings_Schema`
(pure PHP). First read returns schema defaults. Saves run the schema and
reject the whole payload on any error — no partial writes.

The WordPress Settings API (`register_setting` / `options.php`) is **not**
used. That screen is gated on `manage_options`. Ours is `aggr_manage_settings`,
and reviewers must not see it. Writes go through the same `admin-post.php`
pattern as mapping and review.

**Modules are kill-switches, not CSS.** Off means the route, menu item, or
field is not registered. The flags:

| Key | Default | Phase B effect |
|---|---|---|
| `public_signup` | on | Off: `/advertiser/signup/` is a 404 unless the URL carries an invitation token. WordPress “Anyone can register” still has to be on for public signup to work. |
| `billing` | off | No surface yet. Stored so Phase E does not invent a second flag. |
| `native_delivery` | **always on** | Not a Modules checkbox. Merge and validate force true ([ADR-0033](0033-native-delivery-is-not-a-staff-module.md)). Inventory stays registered. |
| `reporting` | off | Metric tiles appear when this is on (ADR-0030, amended by 0033). Spend stays absent. |

Delivery and Tracking panels exist as of Phase C: fill cache TTL (5–300s,
default 30) and house policy (`when_empty` \| `never`); event retention days
(default 90).

The `aggr_signup_enabled` filter remains, wrapping the conjunction of the
module flag and `users_can_register`.

## Consequences

- A fresh install behaves as today: signup still follows WordPress registration
  policy; Inventory is visible to `aggr_manage_placements`.
- Turning Public signup off makes the route absent, not a “closed” form.
- Invitations keep working when the public module is off — they are membership,
  not open registration.

## Alternatives rejected

**`register_setting` plus `options.php`.** Wrong capability, and a second
write path next to `admin-post.php`.

**Per-module options.** Five autoloaded keys for one control plane.

**`display:none` on a disabled module’s UI.** The surface still exists in the
DOM, the route still answers, and a bookmark still works.

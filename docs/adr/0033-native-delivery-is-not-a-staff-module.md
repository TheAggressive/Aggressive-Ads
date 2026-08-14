# ADR-0033 — Native delivery is not a staff module

**Status:** Accepted — 2026-08-13

Amends [0023](0023-settings-and-module-flags.md): `native_delivery` remains in
the settings document but is not a Modules checkbox. Amends [0026](0026-native-delivery.md)
and [0031](0031-native-is-the-only-publisher.md): off is not an emergency
kill-switch staff can click. Amends [0030](0030-reporting-from-native-rollups.md):
metric tiles appear when Reporting is on; native delivery is not a second
switch.

Does **not** supersede 0031's decision that this plugin is the only publisher,
nor 0027's reserved-slot fill.

## Context

The Modules panel still offered Native delivery as an uncheckable flag. With
AdSanity gone there is no fallback publisher. Unchecking it unregisters fill,
the beacon, the click hop, and the placement block. Public pages go dark, and
the next Settings save would do the same automatically if the checkbox were
merely hidden (HTML checkboxes do not POST when off).

An "emergency kill-switch" that lives next to Public signup is a footgun, not
an operations control.

## Decision

**Native delivery is always on.** `Settings_Schema::merge()` and `validate()`
force `modules.native_delivery` to true. A stored `false` from an earlier
save cannot survive a read. The Modules form does not render the control.

The key stays in the document so the option shape does not fork. Fill, beacon,
hop, and `aggr/placement` stay registered. Reporting tiles gate on the
Reporting module alone.

House policy, fill TTL, and event retention remain the Delivery and Tracking
controls. Those tune serving; they do not turn it off.

## Consequences

- Staff cannot black out the public site from Settings.
- A site that had unchecked Native delivery starts serving again on the next
  settings read, without a migration.
- Reporting-off still omits impression tiles. Reporting-on can show them
  because events are being recorded.

## Alternatives rejected

**Hide the checkbox but keep `! empty( $modules['native_delivery'] )` on
save.** The next Save settings would turn delivery off, because the field is
absent from POST.

**Keep an emergency switch on the Delivery panel.** Same footgun, different
heading. Pause and deactivate already take ads down per campaign and per slot.

**Delete the key from the option.** A second document shape for one boolean
that must always be true.

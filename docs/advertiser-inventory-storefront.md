# Advertiser inventory requests

The authenticated Inventory screen lists the same active, selectable packages as
the campaign editor. Prices, durations, placement names and accepted image sizes
come from the existing catalogue. Size illustrations describe dimensions; public
publisher house images appear when configured. Advertiser creative attachments,
private media, private placement groups and staff planning records are excluded.

The buyer chooses a package and local calendar dates. Fixed packages derive their
end date on the server using the existing campaign rule, including timezone/DST
changes. Custom packages require an end date. A signed POST prepares a campaign
draft with that package/window and its current price snapshot. The advertiser
then adds creative and submits through the existing campaign review workflow.
Later package changes do not rewrite an existing campaign snapshot.

The publisher's current policy is **staff-managed reservations**. Browsing,
checking availability and preparing a draft create no hold, reservation,
payment or delivery commitment. Staff retain the P16 booking and audited
oversell workflow. The storefront does not add a second inventory model or
implicitly turn a campaign goal into reserved inventory.

## Availability semantics

The public answer describes whether some page-opportunity inventory remains;
it does not quote or promise an impression quantity:

- **Available to request:** every included placement has a matching forecast
  made within the last 24 hours and some inventory remains after consuming
  exact-window reservations.
- **Unavailable for these dates:** a complete, current assessment finds no
  remaining inventory for at least one required placement.
- **Limited — confirm with the publisher:** the assessment needs confirmation.
  This includes absent/stale/future-dated snapshots, another overlapping
  reservation window, incomplete UTC-day coverage, or the input read limit.

Forecasts currently describe exact UTC calendar windows. A site-local flight
whose boundaries split UTC days cannot be inferred from those daily totals.
Such flights require confirmation; the reader never prorates a forecast or
pretends traffic is uniformly distributed. A different forecast window is not a
matching assessment. Refresh opportunities are not added to page inventory.
P16 counts opportunities, not unique people, so this screen offers no estimated
reach figure.

Only status text reaches the advertiser view. Capacity, quantities held by other
organizations, confidence, forecast versions, error and oversell details stay
inside the planning repositories. Failed SQL produces a visible check failure,
separate from a missing assessment. Availability is advisory: a valid campaign
request can still be prepared for staff review when dates need confirmation.

## Read and write limits

The existing catalogue limits apply: at most 100 packages and 100 placements per
package. A check reads one selected package, using one existing indexed latest
snapshot seek per included placement and one indexed reservation query. The
reservation query materializes at most 4,097 input records before aggregation;
more than 4,096 records returns Limited, never a partial remaining-capacity
answer. Old/released records count toward that input-work budget too, so they
cannot hide an unbounded scan behind an apparently small result. At most 100
aggregate groups reach PHP. Existing schema 34 indexes suffice; no migration is
added.

Availability checks are limited to 120 per account/hour. Draft creation uses one
shared 20-per-account/hour limit in `Campaign_Editor`, including storefront,
dashboard and REST entry points. Authorization is checked independently of the
form nonce. Organization ownership comes from current membership; submitted
organization ids, prices and availability labels are not inputs to the writer.

Invalid selections are refused before creation. If a package/window save fails
after creation, the original draft and its id are retained for recovery; the
response reports failure rather than success. Existing revision rollback permits
a subsequent save. Refused requests preserve valid selection fields. Draft POSTs
are subject to the shared creation budget, not an exactly-once guarantee.

`InventoryStorefrontTest` exercises real writers, reads, refusal/recovery and
the rendered template. `InventoryReadScaleTest` explains the actual production
statements against larger histories. Browser coverage checks the real POST with
and without JavaScript, accessibility and 320px reflow. Multisite coverage uses
colliding placement ids and one reader across site switches.

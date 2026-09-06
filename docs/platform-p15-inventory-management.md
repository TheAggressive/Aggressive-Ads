# P15 — Inventory management

## Status

- Phase: **P15 — Inventory management**
- Roadmap state: `[ ]`
- Last audited: 2026-09-04
- Authoritative environments: CI's pinned MySQL 8.4 / PHP 8.4 lanes

This document records in-progress work. It does not claim completion.

## Outcome

A publisher can describe what they actually have to sell — grouped placements,
one placement that serves several sizes, categories to sell against — and can
see how much of it is being used. Underneath that presentation sits the part
nobody sees and everything later depends on: **a defined unit of inventory**.

## The grain, and why it is the first thing

[platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md)
gives P15 one obligation the other four deliverables rest on:

> P15 must define the inventory grain used consistently by P16, P19 and reports.
> Refreshable inventory must distinguish page opportunity from refresh
> opportunity, apply viewability and policy gates, and avoid forecasting
> infinite supply from a timer.

**Today there is no such distinction anywhere.** `aggr_rollups` counts
`impressions`, and a rotation's impression is summed into the same column as a
first view's. Nothing in `inc/` carries a refresh concept at all.

That is not theoretical. Rotation ships: `MAX_ROTATIONS` is 100 per page view,
a beacon fires per fill, and `rotateSeconds` is a block attribute any editor can
set to the one-second floor. So **one page view can mint a hundred impressions
that are indistinguishable from a hundred page views**. The delivery code
already says so, and says it cannot fix it from where it sits:

> Every rotation is still a new impression, so a slot at this floor records
> sixty an hour per minute of viewing, which is the kind of volume an exchange
> classifies as invalid traffic. Nothing here can prevent that.
> — `src/blocks-interactivity/ad-slot/view.js`

It is right that it cannot. A floor on an interval is a client-side clamp on a
number an editor chose; what is missing is a **publisher policy about their own
inventory**, which is this phase's to own.

### Two countable things, not one

- **Page opportunity** — a slot rendered on a page view, asked to fill for the
  first time. This is *supply*: it exists because somebody loaded a page, and it
  is what P16 may forecast against.
- **Refresh opportunity** — a later fill of the same slot inside the same page
  view, produced by a timer. It is real delivery and a real impression, and it
  is **not** independent supply. A forecast built from it is forecasting a
  `setInterval`.

Both remain impressions. Billing and reporting do not change meaning. What
changes is that supply can be counted separately from the timer that multiplies
it — which is the only way P16 can avoid forecasting infinite inventory, and the
only way a utilisation dashboard can be honest about how much of a placement is
genuinely spoken for.

### Why the client has to say which

The fill endpoint is stateless per request and the page cache sits in front of
it, so the server cannot infer whether a given fill is a page's first. The
client already knows — `run()` versus `startRotation()` in the view store — so
the sequence travels on the fill request.

That is client-declared and therefore untrusted, and the mitigation is to be
careful about what it is trusted *for*. It does not gate impression counting,
which keeps its existing token path unchanged; it partitions a supply metric.
A forged sequence can distort a publisher's own utilisation view; it cannot mint
an impression, credit a campaign, or move money.

## Scope boundary

This phase owns:

- the inventory grain, and the page/refresh split that makes it countable;
- per-placement refresh policy, owned by the publisher rather than the editor;
- placement groups and categories;
- responsive multi-size mapping; and
- utilisation presentation.

This phase does not own:

- forecasting demand or reserving capacity (P16);
- pricing or billing against the grain it defines (P19); or
- creative variants and formats (P17, P18).

## Invariants

- A placement identity used by history is retired, never reused for a different
  inventory meaning.
- Responsive mappings are deterministic: one request context cannot map to two
  conflicting billable inventory units.
- **The publisher's refresh policy bounds the editor's request, never the
  reverse.** A block asking for a one-second rotation on a placement whose
  policy forbids refresh does not refresh.
- A refresh opportunity is never counted as page opportunity, in any surface.
- Turning refresh policy off cannot retroactively change what was already
  recorded.

## Delivery slices

Ordered by dependency, not by size.

1. **Grain and refresh policy.** *Shipped.* The page/refresh split,
   per-placement policy, server-side clamping of the block's request.
   Contract-mandated and fixes a live integrity problem.
2. **Responsive multi-size mapping.** *Shipped.* Carries the determinism
   invariant.
3. **Groups and categories.** *Shipped.* Two independent halves: a private,
   flat `aggr_placement_group` taxonomy for the publisher's own filing, and page
   context supplied into the targeting facts so content categories can be sold
   against. Neither is a package — see `domain-model.md` for why that
   distinction is load-bearing.
4. **Utilisation dashboard.** *Shipped.* Per-placement and per-group page
   utilisation on the reports screen. Last deliberately — it cannot be honest
   before slice 1 exists, and it needed slice 3's groups to have anything to
   total by.

A slice is marked shipped when its evidence below is executable and the screen
that configures it exists. Both halves are the bar deliberately: slice 2 had
storage, a resolver, a gate and a REST field days before a publisher could set
one, and that is not a shipped slice.

## Required executable evidence

- A rotation records an impression and a refresh opportunity, and does **not**
  record a page opportunity. Asserted through the production path, not a
  hand-built row — the counter defect in `testing-strategy.md` is what that rule
  came from.
- A placement whose policy forbids refresh does not refresh, whatever the block
  attribute says.
- A policy change does not alter already-recorded counters.
- The split survives the projector: what the ledger records and what reports
  read agree.
- **A size map resolves identically for the same viewport, every time.**
  `SizeMapTest` sweeps every width from 0 to 1400 rather than sampling the
  interesting ones, because the defect this invariant guards against is a
  boundary nobody thought to pick.
- A map missing its zero floor is not silently treated as a map — it falls back
  to the placement's single size, and the form says so before saving.
- **A campaign sold against a category serves on that category and nowhere
  else**, proven through `Fill_Service` rather than against the comparator. The
  comparator always worked; nothing ever supplied the page, so a category rule
  matched nobody. A test of the rule engine alone passes over exactly that.
- A fill that reports no page does not satisfy a targeted campaign, and an
  untargeted campaign is unaffected by page context either way.
- A private taxonomy is never a targeting dimension, and an unpublished post
  supplies no facts at all.
- A creative whose size does not match what the viewport resolved to is
  excluded. When *every* candidate lost for that one reason, the placement
  reports `size_unavailable` rather than a generic no-fill — a mixed field still
  reports no-fill, because "some were the wrong size" does not explain why the
  rest lost and a reason that is only sometimes true is worse than none.
- **A rotation does not raise utilisation.** Adding nine hundred refresh fills
  to a placement leaves its utilisation figure byte-for-byte identical. This is
  the assertion the whole grain split exists to make possible: without it a
  publisher could raise their apparent sold-through by rotating faster.
- A placement nobody requested reads as no data, not as nought per cent, and a
  placement that was requested and never filled reads as a measured zero. Every
  active placement appears either way — an empty placement is the one a
  publisher most needs to see.
- **A group totals its placements rather than averaging their rates.** Proven
  with a deliberately skewed pair where the mean of the rates is 0.75 and the
  true share sold is 0.11.

## Exit criteria

All four slices shipped and merged. The phase is closed.

- The grain is defined, recorded and countable, and the projector agrees with
  the ledger.
- Refresh policy belongs to the publisher and bounds the block, enforced where
  a client cannot skip it.
- Responsive mappings are deterministic across the full width sweep, and a
  publisher can configure them.
- Placements can be grouped, and content categories can be sold against.
- Utilisation is presented per placement and per group, and excludes refreshes.

Two things this phase deliberately did **not** do, recorded so a later phase
does not assume otherwise:

- **Archives supply no page facts.** A targeted campaign therefore does not
  serve on a category archive — the safe direction, but contextual selling
  there is unfinished.
- **A group is not a sellable unit.** It has no price, no duration and no
  snapshot, and nothing in the decision path reads one. Selling a bundle is
  what `aggr_package` already does; if a group ever needs to be bought, that is
  a pricing decision for P19 rather than a field to add here.

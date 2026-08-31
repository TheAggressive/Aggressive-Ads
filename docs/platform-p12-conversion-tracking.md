# Platform P12 conversion tracking

P12 answers "did the ad cause anything?" rather than "was it seen?". The
measurement model already has the word: P10 defined `conversion` and
`Measurement_Rules::is_valid_transition()` already permits it after a `click`
or a `viewable`. Nothing writes it, and no definition of one exists.

This document defines the work and exit criteria. It does not claim that P12 has
started or that any item below is implemented.

## Status

- Phase: **P12 — Conversion tracking**
- Roadmap state: `[ ]`
- Last audited: 2026-08-30. Click-through ingestion, definitions, the carrier,
  the projection, its reconcile, the staff screen, Site Health and
  **server-to-server ingestion under a scoped, revocable credential** have all
  shipped. What remains is refusal counters (blocked on an undecided question,
  see `open-work.md`), a staff screen for credentials, reporting surfaces
  (P14's), and view-through attribution (gated on P27).
- Authoritative environments: the Docker CI lanes, plus the Playwright lane for
  the browser endpoint and the click-through carrier.

## Outcome

A publisher defines what a conversion is. An advertiser's click that later ends
in that outcome records exactly one conversion, attributed to the fill that
earned it, and attributed the same way whether it is reported by the browser or
by the advertiser's server.

The same outcome reported twice — a retried beacon, a reloaded thank-you page,
a redelivered webhook — counts once. An outcome reported for a fill that never
happened counts never.

## Scope boundary

**This phase owns:**

- **conversion definitions** — the publisher-owned record of what counts, its
  attribution window, its value and currency;
- **browser ingestion** — a public endpoint carrying a signed attribution
  token and a client-supplied idempotency key;
- **server-to-server ingestion** — the same operations under a scoped,
  revocable organization credential;
- **click-through attribution** — the click hop carries the signed token onto
  the destination, because nothing else can;
- **the attribution window and model** — last click, evaluated server-side
  against the recorded click, not against the token; and
- **the `conversions` rollup column** and its reconcile.

**This phase does not own:**

- **view-through attribution.** Defined here, deliberately not built — see
  *The identifier P12 refuses to invent*;
- **the analytics schema (P13)** — no normalized dimensions, no schema
  versioning of the ledger;
- **reporting surfaces (P14)** — P12 adds one column and the counters behind
  it; the advertiser-facing conversion report is P14's;
- **money.** A conversion carries an advertiser-declared value. That is not
  spend, not revenue and not a billing fact. `spend` stays absent until P19
  gives it a source, and P12 may not become the source by accident;
- **conversion-aware pacing or bidding.** A conversion goal is P6 vocabulary.
  The decision engine may not read conversions in this phase; and
- **commerce adapters.** A WooCommerce adapter is optional, ships behind the
  same public ingestion contract as anyone else's, and is never a dependency.

**Compatibility:** every existing measurement path keeps its current meaning.
`POST /aggr/v1/i` is not extended to accept `conversion`; see below for why the
symmetry is refused on purpose.

## Entry criteria

- P10's lifecycle is in place, and `Measurement_Rules::is_valid_transition()`
  already answers `click → conversion` and `viewable → conversion`.
- P11 is complete, so a `viewable` exists to attribute against when view-through
  is eventually unblocked.
- The complete P0 regression baseline is green.

## Canonical model and ownership

**A conversion does not go in `aggr_events`.** This is the first measurement
event that gets its own table, and the reasons are load-bearing:

- `aggr_events` is `UNIQUE KEY token_event (token_hash, event)`. That key is
  what makes replay a database refusal for every other event type, and it is
  exactly wrong here: it permits **one conversion per fill, for all time**. Two
  definitions — a signup and a purchase — from one click are both legitimate,
  and the second would be silently refused as a replay.
- `event` is `varchar(16)`. A per-definition type is the obvious escape and it
  is a trap: `conversion_purchase` is 19 characters, so it truncates on write
  and never matches on read. That is `data-schema.md`'s `varchar(20)` incident
  one size smaller, and it fails the same silent way.
- A conversion carries a definition, a value, a currency, an idempotency key and
  an occurrence time distinct from receipt. None of those are columns on
  `aggr_events`, and widening the highest-volume table in the schema to carry
  the lowest-volume event is backwards.

So: `aggr_conversions`, append-only, unique on `(definition_id,
idempotency_key)`. Atomic duplicate refusal at the database boundary, the same
guarantee `aggr_events` gives — a different key, the same mechanism.

`Measurement_Event_Type::TYPE_CONVERSION` and the transition rule stay. They
stop describing a row in `aggr_events` and start describing **what a conversion
may attribute to**: the domain rule is unchanged, its enforcement moves.

| Fact | Owner |
|---|---|
| What counts as a conversion? | the conversion definition record |
| Did this outcome already count? | `(definition_id, idempotency_key)` |
| Which fill earned it? | the signed attribution token, resolved server-side |
| Is it still in window? | the recorded `click` row's `created_at_ts` |
| How many converted? | `aggr_rollups.conversions`, a projection |

`aggr_rollups` gains `conversions`, **nullable, for P11's reason**: NULL means
nobody was measuring, which is every row written before this phase. Zero means
we were and none happened. Projecting zero over history would make an
unimplemented feature indistinguishable from a campaign that converted nobody —
the more alarming reading, and the wrong one.

Value is stored on the conversion row and **is not rolled up in this phase**. A
money column in reporting is the thing P19 owns, and a column named `revenue`
that P14 renders would become a billing number nobody decided to ship.

## The identifier P12 refuses to invent

Click-through attribution needs no new identifier. The fill token is already
signed, already bound to `blog_id`, placement, campaign and creative, and the
click hop already handles it. It travels to the destination in the URL, and
comes back in the conversion report. Nothing is stored on the visitor.

**View-through attribution is different in kind.** It requires remembering, on
the visitor's device and across later page views, that this browser saw an ad.
That is a cross-visit identifier. P11 shipped with "no cookie, no fingerprint,
one boolean per fill" as a stated invariant, and P12 is not the phase that
quietly reverses it.

`platform-api-privacy-contract.md` already names this: P27 gates
identifier-dependent behavior in P9 and P12 regardless of its later number. So:

- **P12 builds click-through attribution completely.**
- **P12 defines the view-through window, model and storage** so that P27 has a
  concrete thing to gate rather than a blank space.
- **P12 does not ship view-through**, and the setting that would enable it does
  not exist yet. An absent setting cannot be turned on by an administrator who
  did not read this document.

Writing the window down without building the identifier is the deliverable, not
a shortfall. Say so in the exit evidence.

## Invariants

- **Attribution derives from a signed token, never from the request body.** A
  conversion report may not name a campaign, line item, creative or
  organization. It presents a token; the server resolves the rest. A report
  naming a campaign id is refused, not helpfully honoured.
- **A conversion requires a real attributable interaction.** The token must
  resolve to a fill that recorded a `click` (or, once P27 unblocks it, a
  `viewable`). A token that was minted and never clicked converts nothing.
- **The window is measured against server state.** Not against the token's
  `exp`, which is `Fill_Token::TTL_SECONDS` — five minutes — and is a bound on
  when reporting may *start*, not on how long attribution lasts. The window runs
  from the `created_at_ts` of the recorded interaction, which the server wrote.
- **Ingestion parses with `allow_expired`.** A conversion legitimately arrives
  days later; this is the same allowance P11 made for a view below the fold, and
  for the same reason. Authenticity is the HMAC, not the clock.
- **Value and currency come from the definition**, except where an authenticated
  server-to-server integration supplies them, and then both are validated
  against the definition's bounds. An anonymous browser may never state a value.
- **Once per outcome, at the database.** `(definition_id, idempotency_key)`, not
  a client flag and not a `SELECT` before an `INSERT`.
- **Tenancy is unchanged.** The token binds `blog_id`; a definition belongs to
  an organization; a credential is scoped to one organization. No conversion may
  be attributed across a site or a tenant boundary.
- **Conversion collection never blocks delivery.** An ingestion outage does not
  stop a fill, and a failed conversion write never reverses an event.

## Migration and compatibility contract

- One new table, `aggr_conversions`, and one additive nullable column,
  `aggr_rollups.conversions`, each with its own schema version.
- **No backfill, and none is possible.** No historical fill recorded an outcome.
  Reporting shows conversions as unavailable before the first day of
  measurement, exactly as viewability does.
- Definitions are new records; nothing existing is reinterpreted.
- `dbDelta` adds; nothing is dropped, so no index is retired. Note that
  `dbDelta` adds an index and never drops one — if the unique key's definition
  is changed after first release, it must be dropped explicitly from
  `install_table()` as well as from the migration, and the test that asserts the
  old key is gone must **recreate it first**.
- Rollback is the ordinary forward-only path: a build predating the table
  ignores it.
- The compatibility code retires when nothing does — there is no old
  representation to read.

## Workflows and API

Operations, not controllers:

1. **Define a conversion** — staff, capability-gated, audited. Name, attribution
   window, default value and currency, and whether server-to-server reporting is
   permitted.
2. **Report a conversion from a browser** — public when the module is on. Carries
   the attribution token and an idempotency key. Rate limited per client on its
   **own bucket**, not the beacon's; sharing a bucket with `served` would let a
   busy page starve conversion reporting, and separating it is why this is not
   simply another `event` value on `POST /aggr/v1/i`.
3. **Report a conversion server-to-server** — authenticated by a scoped,
   revocable organization credential. May state value and currency within the
   definition's bounds. Rate limited per credential.
4. **Issue and revoke a credential** — staff, audited. Stored as a digest under
   `wp_salt( 'auth' )`, deliberately: a bearer credential *should* stop working
   when the salt rotates. That is the opposite of the `active_key` decision in
   schema v10 and the difference is the point — one is a lookup index over
   plaintext, this is a secret.

Every public operation verifies token authenticity, definition ownership, window
validity, capability where applicable, raw-input validation, payload size cap and
rate limit, and returns a response shape that does not enumerate. A token for
another tenant, an unknown definition and an out-of-window interaction must all
be indistinguishable in the response.

The click hop gains one behaviour: it **appends the signed attribution parameter
to the destination URL**. It has to. `Click_Hop` sets `Referrer-Policy:
no-referrer` on purpose, so the advertiser's landing page learns nothing about
the click otherwise — which is correct, and which means the carrier must be
explicit. A destination that already carries the parameter name has it replaced,
not duplicated.

## Security, privacy and abuse cases

- **A conversion is advertiser-attested or client-attested, and the value more
  so than the fact.** State it plainly, in `threat-model.md`, in the same terms
  P11 used for viewability. What the server controls: the interaction must be
  real, signed, in window, and spendable once.
- **The forged-conversion ceiling.** A dishonest client can report only against
  fills it was actually served and clicked — inventory it could already consume.
  This adds no leverage it did not have. A dishonest *advertiser* with a
  credential can inflate its own conversions, which harms only its own reporting
  and is why the credential is scoped to one organization and revocable.
- **The token in a URL is a new exposure surface.** It reaches the advertiser's
  server logs, their analytics, and any third party on their landing page. It
  must therefore be worth nothing except attribution: it already is — it names a
  placement, campaign and creative, contains no visitor identity, and can be
  spent only against a definition that organization owns.
- **Never logged:** credentials, tokens, idempotency keys, raw IP, and anything
  identifying a visitor. Conversion and interaction ids are correlatable without
  any of those, and that is what the observability contract asks for.
- **Resource exhaustion:** payload cap, per-client and per-credential limits, and
  a bounded idempotency key length — an unbounded key is an unbounded index.

## Failure, recovery and rollback

Each dependency gets one stated behaviour, not an incidental exception:

- **The ledger write fails** → an explicit retryable error. Success is never
  returned before durability.
- **The rollup projection fails** → the conversion row is already durable and
  the daily reconcile rebuilds the counter exactly, as impressions and viewables
  do.
- **The report arrives out of window** → terminal, refused, counted as such in
  the observability signal. Not silently dropped, and not attributed to the
  nearest thing.
- **The token does not resolve** → terminal refusal, non-enumerating.
- **The same key arrives twice** → accepted idempotently, reported as a
  duplicate, counted once.
- **Ingestion is entirely down** → fills continue. Delivery has no dependency on
  this path in either direction.

## Performance and scale contract

- Expected cardinality: conversions are orders of magnitude below served events.
  The table is the smallest in the measurement group and must not be indexed as
  though it were the largest.
- Write budget: one insert, one indexed interaction lookup to validate lineage
  and window, one rollup upsert. Fixed, independent of ledger size.
- Hot-path budget **unchanged**. A decision reads no conversion data in this
  phase, and may not begin to without amending P6.
- The lineage lookup must use an existing index on `aggr_events`. If it needs a
  new one, that index is justified against a measured query plan on the
  authoritative MySQL version, not assumed.
- Large fixture: a definition with a long window and a realistic backlog of
  in-window interactions, so the lookup is proven selective rather than proven
  correct on three rows.

## Observability and operations

- Site Health reports ingestion success, duplicate rate, and refusals split by
  reason — **invalid lineage and out-of-window must not be one number.** They
  mean different things: the first is abuse or a bug, the second is usually a
  window set too short.
- The projection watermark and reconcile lag are visible, as for other rollups.
- The runbook gains: how to revoke a credential, how to tell a misconfigured
  window from a broken carrier, and how to read a conversion count of zero
  against a healthy click count — which almost always means the destination URL
  is dropping the parameter.

## Accessibility and internationalization

The staff definition screen is new UI and inherits the plugin's design system
and its axe coverage: keyboard-navigable, labelled, focus-managed, colour
independent, and reflowing. Its strings are translated, and — because `t()`
answers a missing key with an empty string and an unlabelled input merely looks
unfinished — its string catalog is guarded by test, as `ReviewStringsTest`
guards the review screen's.

The browser ingestion path renders nothing, announces nothing and moves no
focus. A conversion measured on a page must not change that page.

## Required executable evidence

- Pure window-boundary tests: one second inside, exactly on, one second outside.
- A conversion whose token resolves to a fill with **no click** is refused.
- A conversion for another site's token is refused, proven with a foreign-site
  token via `mint_on_site()`.
- The same `(definition_id, idempotency_key)` twice counts once — asserted as a
  **row count**, and proven to survive concurrent arrival, not just sequential.
- Two different definitions from one click both count. This is the assertion
  that fails if conversions are ever moved back into `aggr_events`.
- A request naming a campaign id does not attribute to it.
- A browser request stating a value does not set one.
- A revoked credential is refused; a credential scoped to organization A cannot
  report against organization B's definition.
- The click hop's destination carries the parameter, and a destination that
  already has one is not given two — browser evidence in the Playwright lane,
  end to end, not a unit test of a URL builder.
- Rollup projection and exact reconcile from the ledger.
- Fresh install, real-version upgrade, interrupted migration, multisite and
  site-deletion behavior.
- The complete P0 baseline.

A test asserting that ingestion *returned 200* is not evidence that a conversion
was recorded, attributed or deduplicated. Assert the row, its attribution, and
the count.

## Documentation deliverables

`domain-model.md`, `data-schema.md`, `rest-api.md`, `threat-model.md` — the
attested-signal caveat belongs there in as many words — `administration.md`,
`runbook.md`, `testing-strategy.md`, `platform-measurement-contract.md` where it
describes P12's shipped boundary, and
`platform-implementation-progress.md`.

## Exit criteria

The phase may move to `[x]` only when:

1. A publisher can define a conversion, and a click that reaches it records
   exactly one, end to end.
2. Browser and server-to-server ingestion attribute identically, and neither can
   attribute from client-supplied ids.
3. The attribution window is enforced against server-recorded state, and its
   boundaries are proven by test.
4. The same outcome reported twice counts once, proven concurrently, and two
   definitions from one interaction both count.
5. Credentials are scoped, revocable, and proven to be both.
6. Conversion collection cannot block, slow or reverse delivery.
7. The rollup projects and reconciles exactly, and reporting distinguishes *not
   measured* from *zero*.
8. View-through is documented as defined-and-not-shipped, with its P27 gate
   named, rather than partially implemented.
9. Documentation describes what shipped, including that the signal is attested.
10. Required tests and the P0 baseline are green authoritatively.

The existence of a table, an endpoint or a definition screen is not sufficient
evidence for completion.

## Exit evidence and decision

To be completed at closeout.

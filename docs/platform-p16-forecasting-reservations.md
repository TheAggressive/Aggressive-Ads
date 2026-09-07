# P16 — Forecasting and reservations

## Status

- Phase: **P16 — Forecasting and reservations**
- Roadmap state: `[ ]`
- Last audited: 2026-09-06
- Authoritative environments: CI's pinned MySQL 8.4 / PHP 8.4 lanes

This document records in-progress work. It does not claim completion.

## Outcome

A publisher can find out how much inventory a placement is likely to produce
over a window, hold some of it against a campaign, and be told — not silently
prevented — when they are about to sell more than they have. Forecasts advise.
They do not block authorized staff, and they do not present uncertainty as
capacity.

## What the contract fixes before this phase starts

[platform-inventory-commerce-contract.md](platform-inventory-commerce-contract.md)
settles two things that are therefore not open here.

**The audience.** A forecast is staff-only; an advertiser sees availability,
never capacity. The three reasons are recorded in that document so this is not
re-litigated as a UI preference — a forecast is the publisher's negotiating
position, a number shown to a buyer stops being advisory, and override, actor
and oversell have no advertiser-facing meaning.

**The declarations.** The contract requires this phase to state its source
history, exclusions, seasonality assumptions, minimum data, confidence
representation and conservative fallback. Those are not recorded here in prose
that can drift from the code. They are in the class docblock of
`Domain\Supply_Forecast`, beside the arithmetic they govern, and asserted in
`SupplyForecastTest` — because a declaration in a document is a claim, and a
declaration a test kills a mutant for is a property.

## Slice 1 — the supply estimate *(built)*

`Domain\Supply_Forecast` answers one question: given a placement's observed
daily opportunity history, how much will it produce over a window?

**It reads the grain P15 defined.** Page opportunities and refresh
opportunities are separate series and are never summed. Refresh supply is
forecast from refreshes that were actually recorded, never from `rotateSeconds`
— which is the contract's "avoid forecasting infinite supply from a timer"
obligation, discharged by construction rather than by a check. A timer
describes an upper bound on a page nobody has visited.

**A quantile, not a mean.** This is the decision the phase turns on. A mean is
missed on about half of days, so a publisher who sells to it under-delivers
half of what they sell — which is the failure P16 exists to prevent. The
estimate is the 20th percentile of observed days: on four days in five, this
placement produced at least this much. It is also robust in the direction that
matters, because one viral day lifts a mean and does not move a low quantile.

**Nearest rank, not interpolation.** Every figure returned is a day that
actually happened, which is what lets an estimate be described to a buyer as a
day the publisher has already had.

**No data is not no inventory.** An unobserved placement returns `null`, not
zero. Zero would tell a publisher they have nothing to sell and refuse a
booking on the strength of no evidence at all. The same distinction
`Domain\Fill_Figures` draws for a rate with no denominator.

**Two fields, not three.** The result carries `estimate` and `optimistic`. An
earlier shape had `estimate`, `low` and `high`, where `estimate` and `low` were
always the same number — one value in two fields, and an invitation to read the
pair as a range with the forecast in the middle. It is not in the middle. The
conservative bound *is* the answer.

**The spread and the confidence answer different questions.** Confidence names
how much history backs the figure — and, one for one, which method produced it.
Volatility is in the gap up to `optimistic`. Thirty days of wildly swinging
traffic is well observed and therefore high confidence; the wide spread is the
honest way to say the swing is real, and the estimate is already protected from
it because volatility drags a low quantile down unaided.

### The timezone is load-bearing, and only one test proved it

`Supply_Forecast` pins every date to UTC explicitly. The first test written for
this swapped the ambient zone between two Pacific zones, asserted the forecast
was unchanged, and passed — over an implementation that read the ambient zone.
Both zones have every calendar date, so parsing `2026-02-03` in either lands on
`2026-02-03`, and the assertion was true of the mutant too.

The case that reaches it is a zone that skipped a day. Samoa crossed the date
line at the end of 2011 and `2011-12-30` never happened in `Pacific/Apia`:
parsing it there yields the 31st, so a window loop silently drops a day and a
history key silently fails its round-trip check. A publisher in such a zone
would get a window a day shorter than they asked for and history a day thinner,
with nothing reporting either.

The general shape is in
[testing-strategy.md](testing-strategy.md): the mutation run is what caught it,
and the mutation *harness* nearly hid it — see below.

### A mutation harness that reported every mutant killed

The first run reported 14 of 14 killed. It was matching PHPUnit's summary with
`grep -E "^OK"`, and PHPUnit colours that line, so `OK` is never at the start of
it. Nothing matched, every mutant looked killed, and the two that actually
survived were invisible.

Re-run against PHPUnit's exit code with a no-op control mutant that must be
reported as surviving, it found both. **A mutation harness needs a control**,
for the same reason a `bin/ci/` guard needs to print a count: an instrument that
reports success over code it is not reading is worse than no instrument.

21 real mutants, all killed.

## Not built yet

- **Persisted forecast snapshots.** The contract requires forecasts to be
  immutable and versioned, with reforecasting creating a new version and
  preserving the old one's observed error. Nothing is stored yet;
  `Supply_Forecast` computes and returns.
- **Recorded forecast error.** Needs snapshots first, and needs actuals to
  mature before there is anything to compare.
- **Reservations.** Table, lifecycle, concurrency-safe quantity and status
  changes, audit.
- **Oversell warning and audited override** naming actor, reason, forecast
  version and expected impact.
- **The staff surface**, behind its own capability, and the derived
  advertiser-facing availability answer that exposes no number.

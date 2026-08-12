# ADR-0016 — All portal times are UTC Unix integers

**Status:** Accepted — 2026-08-08

## Context

Campaign scheduling is the product's most visible correctness property. A campaign that goes live a day late, or expires a day early, is a billing conversation.

WordPress makes this harder than it should be. It carries a site timezone, a `gmt_offset` option, `current_time()` in two flavours, `post_date` alongside `post_date_gmt`, and a long history of code that stores site-local strings and compares them to UTC ones. AdSanity, for its part, stores `_start_date` and `_end_date` as **Unix timestamps** — converted with `strtotime()` at save and compared with `'type' => 'numeric'` everywhere.

## Decision

**Every timestamp the portal stores is a UTC Unix integer.** No date strings, no site-local times, no `DateTime` objects in storage, anywhere.

That covers `_laao_ads_start_ts`, `_laao_ads_end_ts`, `_laao_ads_submitted_at`, `_laao_ads_reviewed_at`, and `created_at_ts` in the audit table.

`0` in `_laao_ads_end_ts` means open-ended, and becomes `ADSANITY_EOL` at publish.

Conversion happens at exactly two boundaries:

- **Input** — an advertiser picks a date in the site's timezone; it is converted to a UTC integer at the validation edge, once.
- **Display** — `wp_date()` formats it in the viewer's timezone, at render.

Campaign dates represent complete local calendar days: start is `00:00:00` and
the inclusive end is `23:59:59`. The shared workflow validates those boundaries
for both HTML and REST clients. AdSanity uses inclusive second-precision
comparisons, so this representation serves through the selected final day and
stops at the following midnight. Boundaries are constructed as local calendar
times rather than by assuming every day contains 86,400 seconds.

The audit table also carries a `DATETIME` column alongside the integer, for humans reading the table directly and for `BETWEEN` reporting. Both are written from the same value.

## Consequences

- Comparison is integer comparison. No timezone reasoning at any comparison site, which is where these bugs actually live.
- `meta_query` uses `'type' => 'numeric'` and works correctly, unlike string date comparison, which sorts `'2026-1-5'` in a way nobody wants.
- Publishing to AdSanity needs no conversion — its meta keys already want integers. The stored value is written directly.
- Changing the site timezone does not change what any stored value means. Only its display changes, which is the correct behaviour and the one people expect.
- DST transitions cannot produce a campaign that starts an hour early or a duplicated hour, because there is no local time in storage to be ambiguous.
- The cost is that a value read straight out of the database is not human-readable. Accepted; the audit table's `DATETIME` companion covers the case where someone is actually reading rows by hand.
- `ADSANITY_EOL` is defined by AdSanity as the **string** `'2082672000'`. We cast to int on write. Every consumer compares numerically so the string form works, but storing an int keeps our own comparisons honest.

## Alternatives rejected

**`Y-m-d H:i:s` in site time,** as core does for `post_date`. Every comparison needs the site timezone, and the answer changes if the timezone setting changes. Core carries this for backward compatibility, not because it is right.

**`DateTimeImmutable` in storage.** Serialization, or a string plus a timezone, both of which are the previous alternative wearing a better API.

**Store dates only, no time component.** Loses the ability to start a campaign at a specific hour, and the day boundary is still a timezone question — the ambiguity does not go away, it just becomes 24 hours wide.

**Follow whatever AdSanity does, whenever we talk to it.** That is already the outcome here; the point of the ADR is that it applies to *our* storage too, so there is never a conversion step in the publish path where a bug can live.

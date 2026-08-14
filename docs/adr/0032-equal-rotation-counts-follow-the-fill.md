# ADR-0032 — Equal rotation among live campaigns; counts follow the fill

**Status:** Accepted — 2026-08-13

Amends [0026](0026-native-delivery.md): until this decision, one placement
returned the live campaign with the lowest post id. Amends [0027](0027-cache-safe-fill.md):
the fill cache stores the **candidate set**, not a single winner.

Does **not** supersede 0026's live set (`aggr_live` + active creative + active
placement), nor 0027's reserved-slot HTML, HMAC tokens, or beacon-after-paint
counting. Does **not** introduce weighted rotation, frequency caps, or geo.

## Context

AdSanity is gone. Several paid campaigns can occupy the same Inventory slot.
Serving only the lowest post id made every other live campaign invisible and
unbillable. Counting "whoever is live on this placement now" would be worse
once ads rotate: a beacon arriving after the next fill would credit the wrong
advertiser.

The object cache still must not freeze one creative for every visitor until
TTL, and the public fill JSON must not list every candidate — that is an
artwork and destination leak.

## Decision

**Every servable live campaign on the placement is a candidate.** Fill picks
one per request with equal probability (`random_int`). House creative is
unchanged: it fills only when the candidate set is empty and house policy is
`when_empty`.

**The fill cache stores candidates, never the winner.** Tokens are still
minted per request (ADR-0027). A TTL hit can rotate. Pause, complete, and
creative replacement still delete that placement's fill key in the same
request.

**The public payload is still one creative or house.** Internal ids stay off
the wire. The candidate list never leaves the cache.

**Impression and click credit the token's campaign and creative.** The beacon
and hop already bind `campaign_id` / `creative_id` at mint and re-check that
the named campaign is still live before counting. Rollups increment that
campaign, not the current fill winner. House (`campaign_id = 0`) still never
joins an advertiser org (ADR-0030).

Weighted rotation (package weight, sold share) remains a later ADR.

## Consequences

- Two live campaigns on one slot both appear, and each view is attributed to
  the campaign that was actually painted.
- A leftover token after pause is still refused (`Fill_Service::accepts()`).
- Equal random is not a sold-share guarantee. Do not describe it as weighted
  until that ADR exists.
- Editors still place a slot (`aggr/placement`), never a campaign.

## Alternatives rejected

**Keep lowest-id until "Phase E" forever.** With AdSanity removed, that is a
blackout for every campaign that is not the oldest row.

**Cache the winner.** Every visitor in the TTL window would see the same ad,
which is not rotation.

**Return the candidate set to the client and let JS pick.** Leaks other
advertisers' creatives and destinations, and lets the client choose who is
counted.

**Count against "the placement's current live campaign."** Rotation would
mis-attribute as soon as the next fill ran.

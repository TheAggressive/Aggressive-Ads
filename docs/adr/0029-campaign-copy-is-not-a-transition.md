# ADR-0029 — Campaign copy is a new draft, not a transition

**Status:** Accepted — 2026-08-12

## Context

`aggr_complete` and `aggr_cancelled` have no outgoing edges. Reopening a
finished campaign would revive provider ads, reviewer identity, and a window
that has already elapsed. Phase 9 still needs advertisers to run the same
flight again, and to start from any existing campaign without re-entering the
wizard from a blank draft.

## Decision

**Renew and duplicate are one copy workflow.** The source status only changes
the button label: complete campaigns say “Renew campaign”; every other readable
campaign says “Duplicate campaign”. Both call `Campaign_Copier::copy()`.

The copy is a new `aggr_draft` in the caller’s organization. It is not a
lifecycle edge. `Campaign_State_Machine::apply()` is unchanged.

Copied: title (with a bounded suffix), the stored package snapshot, placements,
advertiser notes, and each *active* creative’s private bytes (new UUID and
token). Dates, reviewer fields, review notes, internal notes, revision,
submission timestamps, pending replacements, attachments, and provider ad ids
are not copied. The new draft never inherits a live or scheduled window.

Authorization is `aggr_submit_campaign` plus `create_aggr_campaigns` plus
`read_aggr_campaign` on the source. Edit is deliberately not required: a
completed campaign is not advertiser-editable. `org_id` is never accepted from
the request.

If a later creative copy fails, the new draft and its private files are
deleted. The source is not written.

## Consequences

- A completed campaign stays completed. The audit row is `campaign.copied`
  with the source id in context.
- Catalogue changes after the original selection do not block the copy: the
  snapshot is copied as stored (ADR-0028). Submission still re-validates.
- Missing private bytes (retention) skip that creative and land the wizard on
  the creative step instead of failing the whole copy.
- HTML and REST share the workflow. REST is `POST /campaigns/{id}/copy`.

## Alternatives rejected

**A complete → draft transition.** It would reuse provider ads and past dates,
and it contradicts the terminal-status rule already recorded in ADR-0008.

**Re-resolving the package from the live catalogue.** That would silently
reprice a renewal and contradict the snapshot rule.

**Sharing private files between campaigns.** Deleting or purging one campaign
would take the other’s artwork with it.

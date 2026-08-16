# Notifications

Campaign email is transactional workflow output, never a mailing list. The
implementation lives in `Notification\Notification_Service` and listens to the
state machine's `aggr_notify_campaign_transitioned` hook. That hook runs
last, after the status, transition metadata, audit row, domain event, and any
publisher effects have succeeded.

## Submission notifications

The staff queue is notified for these advertiser actions only:

- `aggr_draft → aggr_submitted` — initial submission, or a withdrawn/reopened
  campaign submitted again;
- `aggr_changes → aggr_submitted` — corrected campaign resubmission.

`aggr_review → aggr_submitted` is a staff unclaim and deliberately sends nothing.
Treating every transition into submitted as new work would email the whole team
when a reviewer merely releases an assignment.

Messages are localized in each recipient's user locale and sent as plain text.
They contain the organization, campaign title, revision, and authenticated
staff-review URL. They never contain internal notes, creative paths or tokens,
advertiser contact data, or another reviewer's address.

## Recipient resolution

Recipients are resolved fresh for every submission. There is no option, role
slug allowlist, or maintained email list: every current WordPress user for whom
`user_can( $user, aggr_review_campaigns )` is true receives the message.
Resolution includes custom reviewer roles, administrators, direct per-user
grants, and grants or revocations supplied through `user_has_cap` filters.

Users are scanned in deterministic ID order and bounded batches. This happens
only on submission, not on a page-read path; honoring the real capability
pipeline is more important than optimizing for a small candidate role list
that would silently omit filtered grants.

Each recipient gets a separate `wp_mail()` call. Reviewer addresses never
appear together in `To`, `Cc`, or the message body.

## Idempotence and partial failure

After WordPress accepts a message, the campaign records a protected repeated
`_aggr_notification_receipt` value containing the notification type,
submission revision, and recipient user ID. Replaying the hook skips that
recipient. A new revision produces a new receipt and a new email.

A receipt is reserved before calling the transport. If `wp_mail()` returns
false or throws, that recipient's receipt is released. A retry therefore sends
only failed recipients; people whose messages were already accepted are not
emailed twice.

Failure schedules bounded single-run WP-Cron retries after 5 minutes, 30
minutes, and 2 hours. Each retry first confirms that the campaign is still in
`aggr_submitted` and still has the same revision; a withdrawn, claimed, changed,
or superseded submission quietly cancels itself. The final failure writes
`campaign.notification_retry_exhausted` instead of scheduling forever.

WordPress returning true means the configured mail transport accepted the
message for processing. It does **not** prove inbox delivery, so the audit event
is named `campaign.notification_queued`, never `notification_delivered`.

## Failure and audit policy

One recipient's failure does not stop attempts to the remaining recipients.
After fan-out, any failure raises a generic exception containing only a count.
The state machine catches it and writes `campaign.notification_failed`; the
campaign stays submitted because notification runs after the business fact.

Successful accepts write `campaign.notification_queued` with only the
notification type, revision, and recipient count. Email addresses are not
stored in audit messages or context, and `Audit_Event` rejects common email
context keys defensively.

Focused integration coverage proves service and cron wiring, role/direct/filter-based
recipients, individual-address privacy, duplicate suppression, revision email,
partial retry, bounded exhaustion, ignored staff unclaims, and
failure-without-rollback.

## Advertiser requests

An advertiser with a *running* campaign cannot edit it or stop it themselves.
They ask, through `Workflow\Campaign_Change_Manager`: `submit()` sends staged
field changes, `request_action()` asks staff to perform a transition the
advertiser has no edge for. Both are meta writes against a campaign whose status
does not move, so neither reaches `aggr_notify_campaign_transitioned`. They fire
`aggr_notify_advertiser_request` instead, with `$campaign_id` and a `$kind` of
either `edits` or the requested status slug.

`Notification\Request_Mailer` handles it, with the same recipient resolution as
a submission — every user for whom `aggr_review_campaigns` is true, one
`wp_mail()` each. The message carries the organization, the campaign title, what
was requested, the advertiser's stated reason, and a link to the review screen's
**requests** tab. Proposed *values* are deliberately left out: a proposal can
change while it waits, and an email quoting a superseded destination URL is
worse than one that says to go and look.

Receipts are keyed `staff_request:{kind}:{revision}:{user_id}`, where the
revision is `_aggr_request_revision` — a counter of *requests*, not of
submissions. `revision()` would not do: it only moves on a transition, so it
would be identical across a withdraw and a resubmit and the second ask would be
silently suppressed. The counter is bumped by the workflow before the hook
fires, never by the mailer, so a cron retry re-reads the same number and reserves
the same receipt rather than mailing the whole review team on every tick.

Retries follow the shared bounded backoff and first re-check that the request is
still outstanding — still-submitted edits, or an action request still naming the
same status. A withdrawn or already-decided request cancels its own retry.

A delivery failure never becomes the advertiser's error. Their request is
already stored when mail is attempted, so `Campaign_Change_Manager` audits
`campaign.notification_failed` and still returns success — the same reason
`Campaign_State_Machine::notify()` swallows what its notifications throw.

## Ending-soon reminders

Live and paused campaigns with a finite `_aggr_end_ts` inside the next
seven days are swept hourly by `Workflow\Ending_Soon_Notifier`. Delivery lives
in `Notification\Ending_Soon_Mailer`: one plain-text message per organization
member, receipt-keyed on `ending_soon:{end_ts}:{user_id}` so the same end date
never double-sends and a later schedule change can re-arm. Open-ended campaigns
(`end_ts` of 0) are never candidates. Failures retry with the same bounded
backoff as decision mail and never reverse status.

## Private-file retention

`Workflow\Creative_Retention` runs daily. For campaigns in a terminal status
whose relevance timestamp is more than ninety days old, it deletes each
remaining private creative file and clears `_aggr_private_path` /
`_aggr_private_token`. Relevance is `end_ts` when set, otherwise last
modified. Campaign posts, checksum metadata and Media Library attachments are
kept. Audit context carries counts only — never paths.

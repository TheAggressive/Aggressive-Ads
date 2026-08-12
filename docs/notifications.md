# Notifications

Campaign email is transactional workflow output, never a mailing list. The
implementation lives in `Notification\Notification_Service` and listens to the
state machine's `laao_ads_notify_campaign_transitioned` hook. That hook runs
last, after the status, transition metadata, audit row, domain event, and any
AdSanity work have succeeded.

## Submission notifications

The staff queue is notified for these advertiser actions only:

- `lap_draft → lap_submitted` — initial submission, or a withdrawn/reopened
  campaign submitted again;
- `lap_changes → lap_submitted` — corrected campaign resubmission.

`lap_review → lap_submitted` is a staff unclaim and deliberately sends nothing.
Treating every transition into submitted as new work would email the whole team
when a reviewer merely releases an assignment.

Messages are localized in each recipient's user locale and sent as plain text.
They contain the organization, campaign title, revision, and authenticated
staff-review URL. They never contain internal notes, creative paths or tokens,
advertiser contact data, or another reviewer's address.

## Recipient resolution

Recipients are resolved fresh for every submission. There is no option, role
slug allowlist, or maintained email list: every current WordPress user for whom
`user_can( $user, laao_ads_review_campaigns )` is true receives the message.
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
`_laao_ads_notification_receipt` value containing the notification type,
submission revision, and recipient user ID. Replaying the hook skips that
recipient. A new revision produces a new receipt and a new email.

A receipt is reserved before calling the transport. If `wp_mail()` returns
false or throws, that recipient's receipt is released. A retry therefore sends
only failed recipients; people whose messages were already accepted are not
emailed twice.

Failure schedules bounded single-run WP-Cron retries after 5 minutes, 30
minutes, and 2 hours. Each retry first confirms that the campaign is still in
`lap_submitted` and still has the same revision; a withdrawn, claimed, changed,
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

## Ending-soon reminders

Live and paused campaigns with a finite `_laao_ads_end_ts` inside the next
seven days are swept hourly by `Workflow\Ending_Soon_Notifier`. Delivery lives
in `Notification\Ending_Soon_Mailer`: one plain-text message per organization
member, receipt-keyed on `ending_soon:{end_ts}:{user_id}` so the same end date
never double-sends and a later schedule change can re-arm. Open-ended campaigns
(`end_ts` of 0) are never candidates. Failures retry with the same bounded
backoff as decision mail and never reverse status.

## Private-file retention

`Workflow\Creative_Retention` runs daily. For campaigns in a terminal status
whose relevance timestamp is more than ninety days old, it deletes each
remaining private creative file and clears `_laao_ads_private_path` /
`_laao_ads_private_token`. Relevance is `end_ts` when set, otherwise last
modified. Campaign posts, checksum metadata and Media Library attachments are
kept. Audit context carries counts only — never paths.

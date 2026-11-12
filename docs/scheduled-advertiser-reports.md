# Scheduled advertiser report delivery

This is the first delivery slice of #279. Weekly/monthly organization summaries
are personal opt-ins for current portal members. Each account receives its own
message at its current sign-in address. No subscription is enabled by upgrade.

Account settings offer Off, Weekly, or Monthly. Weekly covers the previous
Monday–Sunday; monthly covers the previous calendar month. Release is after
09:00 in the site's timezone. A late run sends only the most recently released
period. Changing the site timezone reschedules future delivery without sending
an old window under a new clock. WP-Cron needs the existing production system
cron configuration; the displayed time is a schedule, not an inbox guarantee.

Email uses the site's configured product name, the portal's metric formatting,
native report reads, freshness state and history disclosures. The links open
the authenticated portal and its authoritative bounded CSV export. No report
attachment or public bearer link is created in this slice. Campaign goal/pacing
summaries and optional share links remain parent-issue work; #279 stays open.

The per-site subscription row holds current delivery state, attempt count,
period and next action time. Workers process bounded indexed batches. Lock names include the database, table
and site. A worker lock and subscription locks serialize processing and settings
changes. Membership, account capability, organization state, recipient address,
Reporting state and timezone are checked again before sending.

Workers and preference writes must use the same MySQL writer. Lock acquisition
and release must stay on its connection; independent writers are not coordinated
by [MySQL named locks](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html).
The SQLite development browser run does not prove database-lock serialization;
that is exercised separately against MySQL with a second connection.

Transport acceptance is **queued**, not inbox delivery. Explicit failure retries
after 5 minutes, 30 minutes, then 2 hours. Interrupted `sending` work and thrown
transport calls have an **uncertain** outcome and are not automatically resent
for that period. A mail API cannot guarantee exactly-once delivery across a
process crash after transport acceptance. Next periods continue normally.
Users see the next release and last result in Account settings; operators get
bounded audit events with ids, periods and outcome codes, never addresses,
message contents or raw transport exceptions. Provider bounce processing is
outside the current mail adapter's capabilities; do not label queued messages
delivered or bounce-free.

Disabling, revoked membership/capability, suspended/deleted organizations and
deleted users stop delivery. Reporting-off pauses workers. Uninstall and site
lifecycle manage the table and schedule. Tests must intercept mail on disposable
sites; development never enables real user subscriptions or sends live mail.

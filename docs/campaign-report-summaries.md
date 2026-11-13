# Campaign goals in advertiser reports

The dashboard and scheduled report emails share `Campaign_Report_View_Data` and
`Campaign_Report_Repository`. Reports use native projection counters and the
portal's metric formatter. They do not create delivery plans, alter delivery
policy, or introduce another event/counter projection.

Each delivery plan remains a separate row. Impression, click and conversion
goals use their own recorded units; unlike targets are never added together.
Spend and share-of-voice progress remain **Not measured**. Their goal type is
shown, but no currency or amount interpretation is invented.

## Time and measurement

Selected-period impressions, clicks, CTR, viewability and conversions follow
the existing report timezone, history and freshness contract. Current goal,
recorded progress, schedule and settings-update time are a separate snapshot,
labelled with its generation time. A current setting is not a historical goal.
Changing a goal cannot change the selected period's delivery counts.

Current progress reads retained UTC daily counters through the open day. It can
change while reconciliation finishes even when the selected period is already
reconciled. It is not a reconstruction of progress at the selected period's end.
The existing CSV remains authoritative for selected-period delivery; current
configuration is deliberately not inserted into historical CSV rows.

Even goal pacing compares goal completion with elapsed schedule using the
serving policy's shared elapsed-flight calculation and ten percentage points of
tolerance. This describes progress toward the configured goal, not a delivery
prediction or the serving engine's cap/throttle decision. ASAP has no linear
schedule target. Draft, ready, paused, completed, cancelled, unstarted and
incomplete-schedule states are stated explicitly.

Zero impressions/clicks, an undefined rate, and an unmeasured nullable metric
remain different answers. Legacy campaign rows without delivery-plan
attribution make the affected plan totals unavailable rather than assigning
history to a guessed plan. Missing local history retains the portal disclosure.
Counter overflow or failed SQL is an error, not a measured-zero fallback.

## Bounds and authorization

- Read at most ten plans plus one sentinel through `(organization_id,id)`.
  Paging uses an exclusive id cursor, never an offset or total-count query.
- Validate each candidate's parent campaign ownership with at most ten exact
  post ids. Both stored plan scope and authoritative parent scope must match.
- Read at most 65,537 projection records per current/selected source through
  `(org_id,campaign_id,line_item_id,day_utc)` or its `bucket_utc` equivalent.
  Aggregate inside MySQL and return at most 110 bounded groups to PHP. At most
  65,536 input records can contribute a complete total; overflow makes that
  source's totals **Not measured** with a disclosure. A prefix is never presented as a complete total.
- Clip campaign/plan names to 127 characters plus an ellipsis in SQL, before
  fetching them. Do not fetch large policy JSON merely to describe a goal.
- Resolve tenant scope from the authenticated portal context or authorized
  report worker. URL cursors cannot select an organization. Escape HTML at
  output; email names are single-line text, and links require portal login.

Plans are listed by newest id, regardless of selected-period delivery. The
summary says this, discloses omitted plans, and links to older pages. It does
not claim to enumerate historical campaign membership or reconstruct old
settings. SQL failure refuses the shared summary and report message; the
existing worker applies its bounded retry/recovery policy.

## Schema and operations

Schema 34 adds `organization_report`, `org_campaign_line_day` and
`org_campaign_line_bucket` to existing tables. Fresh/repair installs and the
numbered migration establish the same access paths. Exact index column order
is checked before migration progress advances. Refused/interrupted DDL leaves
the migration pending for retry; no reporting counters are cleared or rebuilt.

Choose the index-build maintenance window from actual table sizes and the
existing database backup/recovery procedure. Verify `plugin.upgrade_failed`
events and `aggr_db_version` if schema installation is incomplete. Rolling back
application code does not require removing the additive indexes or lowering
the schema marker. Scheduled reporting still requires the documented cron,
mail transport and single-writer coordination in
[scheduled report delivery](scheduled-advertiser-reports.md).

`CampaignReportScaleTest` explains the SQL issued by the production reader and
asserts actual iterator rows/loops, including a fixture larger than the input
budget. Tests also cover authorization, escaping, cursor validation, mixed goal
units, legacy history, SQL recovery, failed index installation and shared
portal/email values. Browser tests exercise keyboard disclosure controls,
pagination, accessibility and 320px reflow on the disposable site.

# CLAUDE.md — LAAO Advertiser Portal

Guidance for AI assistants working in this plugin.

Architectural patterns are adapted from the LAAO and Aggressive Apparel themes.
**Nothing is inherited at runtime**, and where the three differ, this file is
authoritative here. In particular: this is a plugin, not a theme; there is no
WooCommerce, no Tailwind history, no block set to speak of, and the test stack
is deliberately older than LAAO's.

## Read the docs first

This repository's architecture is already written down, in `docs/`. Do not
re-derive it, and do not restate it here.

| Question | File |
|---|---|
| How are the layers separated, and what enforces it? | `docs/architecture.md` |
| What are the entities and their invariants? | `docs/domain-model.md` |
| How does a campaign change status? | `docs/campaign-workflow.md` |
| Who may do what? | `docs/roles-and-capabilities.md` |
| What does AdSanity actually do? | `docs/adsanity-integration.md` |
| What are we defending against? | `docs/threat-model.md` |
| Why was X decided? | `docs/adr/` — 18 records |

**The ADR contract:** a change that reverses a decision recorded in an ADR must
supersede that ADR in the same change. Add a new one marked `Supersedes NNNN`
and set the old one's status to `Superseded by NNNN`. Never edit an ADR in
place to say something different from what was decided.

## Status — read this before assuming anything exists

The runtime and security foundation is built, while i18n/release automation and
implementation across later roadmap phases remain in progress. Phase 4's
advertiser creation UI is the largest open product area. What is built:

- root plugin file, autoloader, service container, composition root
- five private post types, eleven campaign statuses
- the capability vocabulary and the two role matrices, installed by the installer
- the audit table, `Audit_Event`, and `Audit_Repository`, including
  object-and-organization-scoped timeline reads
- installer, version-driven upgrader with a tested migration walker, `uninstall.php`
- org-scoped `Ownership::map()`, `Org_Repository`, `Admin_Guard`, `Portal\Routes`
- `Domain\Transition_Table` — the 22 legal edges, with all 121 pairs asserted
- `Campaign_State_Machine`, the campaign validator, and the campaign/creative/
  placement repositories — submission works end to end
- `Integration\Adsanity\` — the fail-closed placement resolver and the ad
  publisher, with idempotent reconciliation and read-back verification
- wp-env (dev `:9960`, tests `:9970`) and the integration/security/rest/upgrade suites
- PHPCS / PHPStan / PHPUnit / structural guards, wired into `bin/ci/verify.sh`
- creative upload, two-stage private storage, the promoter, and the REST routes
- the portal route, `Portal\Router`, `Portal\View_Data`, and the screens it
  serves: dashboard, campaign list, campaign detail, 403, 404, placeholder
- advertiser draft creation and the first wizard step (details, placements,
  schedule, and advertiser notes), with an HTML form path, REST create/autosave,
  optimistic concurrency, and shared `Campaign_Editor` validation
- `assets/portal.css` — the `--laao-ads-*` token layer and its components,
  contrast-gated by `tests/php/Unit/Assets/PortalContrastTest.php`
- the capability-gated staff review queue and campaign review detail, with
  private creative previews, internal notes, transition controls, and audit
  history using the same design tokens
- capability-resolved, individualized submission/resubmission notifications,
  with per-recipient duplicate suppression, bounded cron retry, localized
  plain-text messages, and failure auditing that cannot reverse a transition
- release packaging and independent archive verification
- `Workflow\Campaign_Clock` — the hourly reconcile that drives approved →
  scheduled → live → complete, without which status freezes at approval
- staff review queue and placement mapping screens, and the advertiser-facing
  notifications for changes, rejection, approval, going live and completion
- pause, resume and cancel, which need no new UI: the review screen's buttons
  are derived from `Transition_Table`, so an edge added there appears by itself
- Playwright + axe (`tests/e2e/`), wired into `ci:verify` as its own lane
- the portal's own sign-in form (`Portal\Login_Actions`), authenticated by
  core's `wp_signon()`. wp-login.php is untouched and is still how staff reach
  wp-admin; only the portal's gate was redirected away from it
- every portal route now has its own screen: dashboard, campaigns, organization,
  account, help and login. `Portal\Account_Actions` is the only way a portal user can
  write anything about their own user record, because `Admin_Guard` closes
  wp-admin to them

What does **not** exist yet, despite being described in `docs/`: **advertiser
sign-up** (accounts and organizations are still created by hand), organization
editing and invitations, self-service email change, the private-file retention
purge, analytics, i18n tooling, semantic-release, and self-hosted Archivo.
`docs/` describes the design; `docs/roadmap.md` says which phase builds it. If a
doc describes something you cannot find, it has not been built — that is
expected, not a bug.

The screens that exist are the ones with real data behind them. The design's
impression/click/CTR/spend tiles are deliberately absent until there is
something to put in them — see
[ADR-0018](docs/adr/0018-portal-ui-from-the-design-with-three-deviations.md).

## Commands

```bash
composer install        # dev tooling only; vendor/ never ships
pnpm wp-env start       # dev :9960, tests :9970
pnpm dev:seed           # an advertiser, an org and five campaigns to look at
pnpm ci:verify          # the contract for declaring a change finished

pnpm lint:php           # PHPCS
pnpm analyse:php        # PHPStan level 8, no baseline
pnpm test:php:unit      # unit suite — no WordPress, no database
pnpm lint:files         # file length + architecture boundaries + permission callbacks
pnpm lint:php:fix       # phpcbf
```

Every `ci:*` script maps 1:1 onto a CI job. Adding a lane means adding it to
**both** the workflow and `bin/ci/verify.sh`; adding it to one is how the two
drift.

## Architecture, in brief

```
laao-advertiser-portal.php   header, constants, floor guard, hand-off. Never a fifth job.
  └ inc/class-autoloader.php   LAAO_Advertiser_Portal\X\Y_Z → inc/X/class-y-z.php
      └ inc/class-plugin.php   register_services() / init_services(), explicit order
```

Registration stores a closure and runs nothing. Behaviour begins only at
`init_services()`. Adding a service costs two edits in one file, deliberately —
the wiring stays greppable and there is no autowiring to reverse-engineer.

Two boundaries carry most of the weight, and both fail the build when crossed:

- **`inc/Repository/` is the only place data access appears.** No `WP_Query`,
  `get_posts()`, `get_post_meta()`, `$wpdb` anywhere else in `inc/`.
- **`inc/Integration/Adsanity/` is the only place AdSanity exists.** Its
  constants, classes, hooks, taxonomy, post type and meta keys appear nowhere else.
- **`inc/Domain/` calls no WordPress function.** Constants from other classes
  are fine; a function call is not.

`inc/Domain/` may not call WordPress **at all**. That is what makes the campaign
rules testable in milliseconds with no bootstrap, which is what makes it
affordable to test them exhaustively.

## Testing

PHPUnit is pinned to **9.6**, not 13 like the LAAO theme. This is deliberate and
test-only: the assertions that matter here — org-scoped `map_meta_cap`, `dbDelta`
idempotence, real REST authorization, real uploads — are not expressible under
Brain\Monkey, and the WordPress core test suite requires 9.x. See ADR-0013.

Two config files because **PHPUnit allows exactly one bootstrap per file**. The
unit suite must not load WordPress; the integration suite must.

`failOnWarning`, `failOnRisky`, `failOnSkipped` and `failOnIncomplete` are all
true. A skipped security test is a security test that is not running.

### Prove the test works

Write it, watch it pass, **break the implementation deliberately**, watch it
fail, read the failure message, restore, watch it pass.

This is not ceremony. Three tests here have already been caught passing for the
wrong reason, and each hid a real defect:

- The autoloader's path-traversal test asserted null against a path where
  nothing existed, so `is_file()` rejected it for an unrelated reason.
- The "no `wp/v2` route" test scanned a locally built `WP_REST_Server`, which
  never receives the routes — `register_rest_route()` writes to the global.
- The ownership tests exposed that `map_meta_cap` **never passes a custom meta
  capability to the filter**; it recurses with the generic `edit_post`. The
  filter was silently inert, and reads were being granted by core.

A test that passes for the wrong reason is worse than no test, because it
produces confidence. Assert your fixture is real before asserting on it.

## Gotchas that cost real time

- **`wp_posts.post_type` and `post_status` are `varchar(20)`.** A longer slug does
  not error — it truncates on write and then never matches on read, producing
  rows that exist and cannot be queried. This is the entire reason statuses use
  the `lap_` prefix while everything else uses `laao_ads_`. Do not "fix" that
  inconsistency.
- **No runtime Composer dependencies, ever.** WordPress has no dependency
  isolation; two plugins shipping different versions of one package fatal the
  site. `composer.json` `require` is `{"php": ">=8.4"}` and stays that way. See
  ADR-0011 for the core substitution table before reaching for a package.
- **The production autoloader is ours, not Composer's.** `inc/class-autoloader.php`
  is listed in the packaging script's required files for that reason.
- **Your editor's PHPCS is not the project's.** IDE integrations often run stock
  WordPress standards and will flag `tests/php/**/FooTest.php` filenames.
  `phpcs.xml.dist` excludes `WordPress.Files.FileName` there on purpose — tests
  follow PHPUnit conventions. `vendor/bin/phpcs` is the authority.
- **Exception messages are exempt from the escaping sniff**, narrowly and with a
  reason in `phpcs.xml.dist`: they are boot-time developer diagnostics, never
  rendered. Anything a user can cause returns `WP_Error` instead.
- **AdSanity writes have no safety net.** `AdSanity_Ads_CPT::save_post()` requires
  `$_POST['ads_nonce']` and so returns immediately for programmatic writes. It
  will store whatever we write and then fail to display it. The publisher must
  read back every key it wrote.
- **AdSanity has no cron.** Scheduling is a read-time `meta_query`, so an ad
  missing either date key is *invisible everywhere* — not "expired", absent. That
  is the failure mode that produces "we billed for a campaign nobody ever saw".

## Working style

- **Verify before asserting.** Read the installed source rather than inferring
  from documentation or a plugin's UI. Everything in
  `docs/adsanity-integration.md` carries a file and line reference for this reason.
- **Do not weaken a gate to get green.** Fix the cause, or change the rule
  deliberately with an ADR. A gate that fires on legitimate code is itself a
  defect — fix the pattern, do not add an exception.
- **Comments explain why, not what.** A comment restating the line below it is
  noise; one recording the incident that made the line necessary is the most
  valuable thing in the file.
- **Security, accessibility, idempotency and failure recovery are requirements,
  not a later pass.** They block a release.
- Conventional commits (`feat:`, `fix:`, `docs:`, `ci:`, …). semantic-release
  owns the version — never hardcode it.

# CLAUDE.md — Aggressive Ads

Guidance for AI assistants working in this plugin.

Patterns are adapted from the LAAO and Aggressive Apparel themes, but
**nothing is inherited at runtime** and this file is authoritative where they
differ: this is a plugin, not a theme; no WooCommerce, no Tailwind history, one
public block (`aggr/ad-slot`), and the WordPress suites run an older PHPUnit
than LAAO's — the unit suite does not.

## Working with me — read this first

- **Be concise.** Answer what was asked. No preamble, no plan-recap, no summary
  of what you just did unless it changed a decision.
- **Route mechanical work to a Haiku sub-agent** (`model: "haiku"`): renames,
  reformatting, summarising a file, scraping a list out of output, mechanical
  find-and-replace across many files. Opus at high effort costs 5x input and 5x
  output for work where the answer is not in doubt. Judgement work —
  architecture, security, anything touching `inc/Domain/` or a `bin/ci/` guard —
  stays on the main model.
- **Never suggest `/compact` as a cost-saving measure.** It is not one: it
  spends a large model call to summarise, then rebuilds the whole cache, so the
  next turn pays full cache-write price instead of the 10% read price it had.
  It is for surviving a full context window. When a session is expensive the
  answer is `/clear` and a fresh session scoped to one job.
- **Batch questions into one message.** Each turn re-sends the whole conversation.

## Read the docs first

Architecture is already written down in `docs/`. Do not re-derive it, and do not
restate it here.

| Question | File |
|---|---|
| How are the layers separated, and what enforces it? | `architecture.md` |
| What are the entities and their invariants? | `domain-model.md` |
| How does a campaign change status? | `campaign-workflow.md` |
| Who may do what? | `roles-and-capabilities.md` |
| What are we defending against? | `threat-model.md` |
| How is this tested, and with which PHPUnit? | `testing-strategy.md` |
| What is half-finished right now, and why? | `open-work.md` |

Also in `docs/`: `suite-roadmap.md`, `platform-implementation-progress.md`,
`pull-request-automation.md`, `build-and-release.md`. Product rules live in
`docs/` — put a reversed decision in the same living doc in the same change;
do not add an `adr/` log.

## Status — what exists

Everything `docs/` describes is built; CSV reporting and deeper analytics are
the open edge. **Do not assume from this file what exists** — check
`docs/roadmap.md`, then `docs/open-work.md`, then the source. A per-feature
inventory used to live here and was archived rather than maintained; it is gone
now, because it went stale faster than it was read either way. Git history has
it if it is ever wanted. Screens exist only where real data backs them: impression,
click and CTR tiles, the sparkline and table CTR appear only when Reporting is
on. Spend stays absent until billing has a source.
## Commands

```bash
composer install        # dev tooling only; vendor/ never ships
pnpm install            # webpack / TypeScript / Playwright
bash bin/ci/install-wp-runner.sh   # PHPUnit 9.6 for the WordPress suites
pnpm build              # src/ → dist/
pnpm env:start          # disposable WordPress 7.1 at :9960
pnpm dev:seed           # an advertiser, an org and five campaigns to look at
pnpm qa:local           # Docker-free checks + E2E against WordPress Studio
pnpm ci:verify          # the contract for declaring a change finished

pnpm lint:php / lint:js / lint:css / lint:files / typecheck
pnpm analyse:php        # PHPStan level 8, no baseline
pnpm test:php:unit      # PHPUnit 13 — no WordPress, no database
pnpm test:php:native    # the WP suites natively: local MySQL, no Docker
pnpm test:php:integration   # WP integration/security/rest/upgrade (needs env:start)
pnpm test:php:multisite     # colliding-id tenancy (needs env:start)
```

`lint:files` covers file length, architecture boundaries and permission
callbacks. Every `ci:*` script maps 1:1 onto a CI job — adding a lane means
adding it to **both** the workflow and `bin/ci/verify.sh`; adding it to one is
how the two drift.
## Architecture, in brief

```
aggressive-ads.php   header, constants, floor guard, hand-off. Never a fifth job.
  └ inc/class-autoloader.php   Aggressive\Ads\X\Y_Z → inc/X/class-y-z.php
      └ inc/class-plugin.php   boot + ordered init_services()
      └ inc/class-service-registrar.php   register() factories — instantiates nothing
```

Registrars split by responsibility, not size — `Service_Registrar` (domain),
`Rest_Service_Registrar` (HTTP), `Runtime_Service_Registrar` (hooked
admin/delivery/lifecycle), `Install\Migration_Map` (version→migration). A
mistake in a route table exposes an endpoint; in a factory it throws on boot; in
a migration it runs once against real data — different review standards,
different files.

Registration stores a closure and runs nothing; behaviour begins at
`init_services()`. Adding a service costs two greppable edits: a
`Service_Registrar::register()` line and, when hooks are needed, a
`Plugin::service_init_order()` entry. There is no autowiring to reverse-engineer.

Three boundaries fail the build when crossed:

- **`inc/Repository/` is the only place data access appears.** No `WP_Query`,
  `get_posts()`, `get_post_meta()`, `$wpdb` anywhere else in `inc/`.
- **AdSanity identifiers appear nowhere in `inc/` or `templates/`.**
- **`inc/Domain/` calls no WordPress function at all.** Constants from other
  classes are fine; a function call is not. That is what makes the campaign
  rules testable in milliseconds with no bootstrap, which is what makes it
  affordable to test them exhaustively.

## Testing

Suites, the two-PHPUnit split and the failure policy are in
`docs/testing-strategy.md`. Two rules that live here because that doc does not
cover them, and both came from real defects:

- **Test the dangerous things first.** Anything that deletes, grants, denies or
  guards gets a test before it is called done; hand verification does not count.
  **A guard that stops matching reports success over code it is no longer
  reading** — most guards in `bin/ci/` had never worked when first audited, so
  check what one reads before trusting it, and make it print a count.
- **Assert a count, not just absence**, and for destructive code **assert the
  negatives**: what it must *not* touch is usually the more valuable half.

`docs/testing-strategy.md` carries the "prove the test works" loop and the
tests caught passing for the wrong reason, incident by incident.

## Gotchas that cost real time

- **`wp_posts.post_type` and `post_status` are `varchar(20)`.** A longer slug
  does not error — it truncates on write and never matches on read, producing
  rows that exist and cannot be queried. Do not invent a longer status slug.
- **No runtime Composer dependencies, ever.** WordPress has no dependency
  isolation; two plugins shipping different versions of one package fatal the
  site. `composer.json` `require` is `{"php": ">=8.4"}` and stays that way.
  `docs/build-and-release.md` has the core substitution table.
- **The production autoloader is ours, not Composer's** — hence `inc/class-autoloader.php` in the packaging script's required files.
- **Your editor's PHPCS is not the project's.** IDE integrations run stock
  WordPress standards and flag `tests/php/**/FooTest.php` filenames;
  `phpcs.xml.dist` excludes `WordPress.Files.FileName` there on purpose.
  `vendor/bin/phpcs` is the authority.
- **Exception messages are exempt from the escaping sniff** (reason in
  `phpcs.xml.dist`): boot-time developer diagnostics, never rendered. Anything a
  user can cause returns `WP_Error` instead.
- **`dbDelta` adds an index and never drops one.** A key whose definition
  changes leaves the old one in place, still enforcing the old rule. `slot_day`
  and `token_hash` are dropped explicitly from `install_table()`, not only from
  the migration, so a repair install heals a site the upgrade missed. A test
  asserting the old key is gone must **recreate it first** — a fresh table never
  had it, and the assertion passes over a migration that does nothing.
- **A decision stage reads the candidate row, and that row is not the query.**
  `candidates_for_placement()` returns the *assignment's* columns; priority,
  pacing, caps, targeting and frequency policy live on `aggr_line_items`, and
  delivered counters come from `aggr_rollups`. `Decision_Engine::enrich()`
  attaches them before the pipeline runs. P5, P6, P8 and P9 all shipped `[x]`
  with passing tests, reading defaults for fields nothing supplied — every stage
  was unit-tested against a hand-built row carrying keys the real query never
  returns. **If a stage reads a key, something must be proven to put it there**;
  `DecisionPolicyInputsTest` goes through the engine for that reason.
- **A read half and a write half have to meet in a test.** Frequency capping
  shipped complete and capped nobody: `get_count()` was correct and nothing
  called `increment()`. Every test arranged its own count, so all passed. A
  counter test that never writes through the production path tests arithmetic.

## Working style

- **Verify before asserting.** Read the installed source, not the docs or UI.
- **Do not weaken a gate to get green.** Fix the cause, or change the rule
  deliberately. A gate that fires on legitimate code is itself a defect — fix
  the pattern, do not add an exception.
- **Comments explain why, not what.** A comment restating the line below it is
  noise; one recording the incident that made the line necessary is the most
  valuable thing in the file.
- **Security, accessibility, idempotency and failure recovery block a release.**
- Conventional commits (`feat:`, `fix:`, `docs:`, `ci:`, …). semantic-release
  owns published versions; release packaging stamps the planned version into
  the staged plugin without rewriting the checkout.

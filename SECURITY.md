# Security

This plugin handles commercially sensitive data: unreleased advertising
creative, campaign budgets and schedules, and the approval action that
publishes to a public website and can bill a customer.

## Reporting a vulnerability

Email **security@theaggressive.com** with enough detail to reproduce. Please do
not open a public issue for anything exploitable.

You should get an acknowledgement within two working days. If you do not,
assume the mail did not arrive and try again rather than assuming it was
ignored.

## Supported versions

Security fixes are issued for the latest published stable release. Older
releases and development snapshots are not maintained; upgrade to the latest
release before requesting a backport. Before the first stable release, security
fixes land on `master` and are included in the next tagged release.

## What we consider in scope

Anything that lets one advertiser reach another's data, lets an advertiser
reach a staff action, or puts attacker-controlled markup on the public site.
Concretely, the surfaces we care most about are listed in
[docs/threat-model.md](docs/threat-model.md), and each one names the test that
proves its mitigation.

The highest-value targets, in order:

1. **Unapproved creative files.** Held outside the Media Library under a
   generated name, readable only through an authorized endpoint that streams
   bytes and never redirects.
2. **Campaign data across organizations.** Every object check resolves through
   one org-scoped `map_meta_cap` filter; controllers never compare ids.
3. **The approval action.** Publishing writes to a public website and can bill
   a customer, and is a separate capability from reviewing.

## What is out of scope

Named so their absence is deliberate rather than overlooked:

- Two-factor authentication and brute-force protection on `wp-login.php` —
  WordPress core plus site infrastructure, not this plugin's layer.
- Cross-network tenancy. Standard site-scoped multisite is supported and tested;
  organizations, campaigns, caches, and lifecycle tables remain isolated per site.
- Payment data — none is stored; there is no payment feature.
- Open redirect via a creative's destination URL. That URL is by design a
  third-party destination rendered as an `href` on a public page and cannot be
  restricted to an allowlist without breaking the product. The control is human
  review plus the audit trail, and it is recorded in the threat model as an
  accepted risk with a named control rather than as an oversight.

## Our side of it

- Security is a release blocker, not a hardening pass.
- Every mitigation names the test that proves it. A mitigation without a test
  is an intention.
- Security tests assert both that a guard behaves correctly **and** that it is
  actually attached. A refactor that drops an `add_filter` leaves behavioural
  tests green and the guard entirely absent.
- Tests are verified by breaking the implementation and confirming they fail.
  Several controls in this codebase were found to be untested that way, having
  looked fully covered.

## Production delivery controls

High-volume native delivery requires persistent Redis or Memcached, a real
system cron invoking WordPress cron, and CDN/WAF abuse controls. Site Health
checks the plugin-owned parts, including a representative 1,000-creative cache
item, atomic counter support, and tracking-maintenance schedules. Reverse
proxies must restore a validated client address into `REMOTE_ADDR`; forwarded
headers are not trusted by the plugin. See
[delivery performance and operations](docs/delivery-performance.md).

## Software supply-chain controls

- Third-party GitHub Actions are pinned to immutable commit SHAs and checked in
  CI. Actionlint and Zizmor independently validate workflow correctness and
  security; CodeQL analyzes JavaScript and TypeScript.
- Composer and pnpm lockfiles are installed frozen and audited. Audit exceptions
  require a local source-level regression check and a documented removal
  condition.
- The release workflow never rebuilds the plugin. It downloads the exact ZIP
  accepted by the successful `master` CI run, verifies its SHA-256 sidecar,
  creates a provenance attestation, compares the uploaded assets byte-for-byte,
  and only then publishes the release.
- The protected release branch requires signed linear history, squash-only pull
  requests, resolved review threads, and successful CI and workflow-security
  checks.

The implementation and operator procedure are documented in
[build-and-release.md](docs/build-and-release.md).

## Patched development dependency

`pnpm ci:security` audits the complete development tree. `adm-zip` 0.5.18 is
locally patched with the upstream CVE-2026-39244 allocation fix because npm's
advisory marks every version below 0.6.0 and 0.6.0 is not available from the
configured registry. The patch is recorded under `patches/`, pinned by the
lockfile, and `bin/ci/check-patched-dependencies.mjs` proves both that the
vulnerable allocation is absent and that a normal ZIP round trip still works.

The audit command ignores only `GHSA-xcpc-8h2w-3j85`, after that source check
passes. It does not use `--ignore-unfixable`, so every unrelated advisory still
fails CI. Remove the patch and exception once WordPress tooling resolves a
published fixed release.

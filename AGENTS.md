# Engineering rules for Aggressive Ads

These rules apply to all work in this plugin, including AI-assisted changes.
Read the relevant existing contract before implementing a feature:
[architecture](docs/architecture.md), [domain model](docs/domain-model.md),
[security](SECURITY.md), [threat model](docs/threat-model.md),
[testing](docs/testing-strategy.md), and [release](docs/build-and-release.md).
Use the parent site's WordPress Studio instructions for site operations.

## Keep changes maintainable

- Solve a demonstrated problem with the smallest cohesive change. Reuse the
  existing layers and WordPress APIs. Add dependencies or abstractions only
  when a real caller and a concrete constraint justify them.
- Do not ship placeholder screens, fabricated metrics, unused configuration,
  speculative extension points, duplicate helpers, or catch-all fallbacks that
  report success after a failure.
- Comments explain an invariant, tradeoff, or non-obvious constraint. Remove
  obsolete names and claims when behavior changes. Avoid narration of the code,
  promotional language, and unsupported claims of safety or scale.
- Verify changing platform/API guidance against official sources. Follow the
  repository's supported runtime matrix; evaluate new releases before adopting
  them. Keep lockfiles reproducible and document dependency exceptions and their
  removal conditions. Production runtimes need maintained WordPress, PHP, and
  database patch releases; a compatibility test is not a hosting security audit.

## Treat security as part of implementation

- Derive tenant scope from authenticated membership or object capabilities.
  Never trust a request's organization id as authorization. Preserve site scope
  in queries, cache keys, locks, and background work.
- Check capabilities for protected reads and writes. Nonces protect against
  CSRF; they do not grant access. Test denied requests and attachment of guards.
- Validate input, prepare SQL values, allowlist dynamic identifiers, and escape
  for the actual output context. CSV, HTML, URLs, headers, and JSON need their
  own controls. Treat stored values and external responses as untrusted.
- Do not expose tokens, personal data, tenant details, or SQL errors in logs or
  public errors. Follow SECURITY.md for vulnerability disclosure.
- Keep audits and required security checks blocking. Never weaken a check,
  suppress an advisory broadly, or lower a threshold merely to obtain green CI.

## Require operational evidence

- Bound database work by an indexed range and the appropriate item, byte, or
  time budget. A date limit alone does not bound an export's row count. Measure
  representative load before making throughput or capacity claims.
- Migrations and jobs must support retries and recovery. Check failed reads
  before committing progress; distinguish an empty result from an unavailable
  source. Retention must preserve data required by unfinished projections.
- For changes to shared state, assess concurrent requests, partial writes,
  cache invalidation, and multisite isolation. Add a race test when correctness
  depends on serialization. Keep blocking locks off the delivery hot path.
- Show missing, partial, and stale data accurately in both UI and exports.
  Define recovery and rollback behavior in the existing runbook.

## Prove the change

- Use regression tests that exercise real writers and readers. Cover failure,
  refusal, and recovery paths for security-sensitive or destructive changes.
  Confirm a regression test fails without its fix; fixture data must not supply
  the behavior the test claims to verify.
- Run the checks appropriate to the change and the required pre-push gate.
  Treat the full CI pipeline as required before merge. Do not claim that a
  skipped, unavailable, or unrun check passed.
- State scope and remaining limits. Passing tests are evidence for specific
  behavior; they do not certify the whole plugin as enterprise-ready or free of
  vulnerabilities. Deployment readiness also depends on the documented runtime,
  cron, cache, backup, and recovery requirements.

Primary references: [WordPress security](https://developer.wordpress.org/apis/security/),
[WordPress nonces](https://developer.wordpress.org/apis/security/nonces/),
[OWASP ASVS](https://asvs.owasp.org/), and
[OWASP CSV injection guidance](https://owasp.org/www-community/attacks/CSV_Injection).
Use ASVS as review guidance; no conformance level is claimed without a mapped
assessment and evidence.

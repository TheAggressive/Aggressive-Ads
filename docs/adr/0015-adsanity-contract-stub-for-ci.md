# ADR-0015 — AdSanity contract stub for CI, plus a drift test

**Status:** Accepted — 2026-08-08

## Context

AdSanity 2.0.1 is a licensed, paid plugin. CI cannot download it — there is no public artifact, and putting a license key in a GitHub Actions secret to fetch a paid plugin on every run is a distribution question we should not be answering in a workflow file.

Meanwhile the publisher's integration tests need the `ads` post type, the `ad-group` taxonomy, `ADSANITY_EOL`, and the `adsanity_ad_sizes` filter to exist.

## Decision

CI runs against `tests/fixtures/mu-plugins/adsanity-contract-stub.php`, which registers the `ads` CPT with **AdSanity's exact registration arguments**, the hierarchical `ad-group` taxonomy, the `ADSANITY_EOL` constant, and the `adsanity_ad_sizes` filter. It self-disables when the real plugin is present.

`tests/php/Contract/AdsanityContractTest.php` **skips unless real AdSanity is active** and asserts, field for field, that the stub still matches it. It runs locally and nightly, not on pull requests.

## Consequences

- The integration and publisher tests run on every PR without a license.
- **CI does not test the real integration.** This is stated plainly rather than papered over. A separate gate proves the thing CI tests has not drifted from reality; it does not close the gap, it makes the gap detectable.
- An AdSanity update changing a registration argument or a meta key turns the nightly contract test red, naming the field. Without it, the stub would quietly diverge and CI would keep certifying an integration against a plugin that no longer exists in that shape.
- The stub is a maintenance object. When AdSanity changes, the stub is updated in the same PR as the publisher, and the contract test is what says so.
- Any new AdSanity fact the publisher relies on has to be added to both the stub and the contract test. That friction is intentional — it is the moment where "we depend on this" gets written down.
- The real integration is exercised locally, nightly, and in Phase 11 staging validation against a real AdSanity install with real ad groups.

## Alternatives rejected

**Vendor AdSanity into the repository.** Redistributing a paid plugin. Not our call to make.

**A license key in CI secrets, fetching on each run.** Puts a paid license in a build environment, makes CI depend on a vendor's download endpoint being up, and means every fork and PR from outside touches licensed code.

**Mock the AdSanity calls entirely.** The publisher's correctness *is* its interaction with real post types, real taxonomy terms, and real meta storage. Mocking those tests the mock — and the two facts that matter most about AdSanity (read-time scheduling, and `save_post` being a no-op for programmatic writes) are precisely the ones a mock would paper over.

**Skip the AdSanity tests in CI.** `failOnSkipped` is `true` for a reason ([ADR-0013](0013-phpunit-9-with-wp-test-suite.md)). A silently skipped suite reports green while testing nothing.

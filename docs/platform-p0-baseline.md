# Platform P0 baseline and regression safety

Platform P0 was closed on 23 August 2026 before beginning the line-item domain.
The baseline was established from the implementation and executable tests, not
from roadmap claims. No required behavior lacked regression coverage, so P0 did
not add duplicate tests merely to increase the test count.

## Required regression matrix

| Required behavior | Production path | Executable evidence |
|---|---|---|
| Campaign lifecycle | `Campaign_State_Machine`, `Campaign_Clock`, transition guards/effects | `CampaignStateMachineTest`, `CampaignClockTest`, exhaustive `TransitionTableTest` |
| Creative approval | review workflow, promotion and private storage | `AdminReviewTest`, `CreativeManagerTest`, `CreativePromoterTest` |
| Creative replacement | `Creative_Change_Manager`, repository locks and promotion rollback | `CreativeChangeManagerTest`, `CreativeRepositoryLockTest`, `CampaignChangeTest` |
| Tenant isolation | `Ownership`, organization access repository and multisite scoping | `OwnershipTest`, `AuthorizationSurfaceTest`, `SiteScopedTenancyTest` |
| Native fill | fill REST controller, decision engine, assignment repository and native publisher | `FillRoutesTest`, `FillSelectionTest`, `NativePublisherTest`, `DeliveryScaleTest` |
| Click redirect | signed click hop, destination resolution and replay handling | `FillRoutesTest::test_click_hop_redirects_to_the_house_url`, impression/click replay test |
| Impression/event recording | append-first event repository, replay key and rollup projection | `FillRoutesTest`, `TrackingDurabilityTest`, reporting reconciliation tests |
| Signed tokens | blog-bound HMAC token mint/parse and current-live validation | `FillTokenTest`, `FillRoutesTest`, multisite cross-site token refusal |
| Cached fills | placement-scoped payload/vector cache, rebuild lock and transition bust | `FillCacheLockTest`, `DeliveryScaleTest`, `NativePublisherTest`, multisite cache isolation |
| Placement block rendering | dynamic block registration and reserved-size shell | `PlacementSlotTest`, browser sizing and placement-mapping specs |
| Organization permissions | capability mapping, portal actions and membership workflows | `OrganizationMembershipTest`, `PortalOrganizationActionsTest`, account/organization browser spec |

These tests assert real wiring as well as isolated behavior. Security cases run
through WordPress capability and REST registration, database cases use MySQL,
and browser cases exercise a real WordPress site. The matrix does not treat a
mock configured by the test as proof that production hooks execute.

## Recorded execution

The following baseline passed on 23 August 2026:

- `pnpm run qa:fast`: Composer validation, architecture guards, i18n drift,
  PHPCS, PHPStan level 8, ESLint, strict TypeScript, Stylelint, Prettier,
  ShellCheck, all builds, 423 PHP unit tests with 2,158 assertions, 68 Node tool
  tests and 26 JavaScript tests;
- `pnpm test:php:native`: 819 single-site WordPress tests with 5,208 assertions
  and 7 multisite tests with 31 assertions, with complete JUnit reports;
- `pnpm test:e2e:studio`: 13 Playwright tests across Chromium, WebKit and a
  320-CSS-pixel reflow project, including the campaign wizard, review,
  organization, placement, authentication and axe-backed accessibility flows;
- `pnpm ci:security`: the required dependency patch was present and Composer
  and pnpm reported no unignored vulnerability advisories; and
- `pnpm lint:workflows`: Actionlint and Zizmor passed with no findings; and
- `pnpm ci:package`: two verified 301-file release archives were byte-identical
  in the reproducibility check.

The most recent compatible-driver coverage reports contain 9,152 of 13,100
executable `inc/` statements (69.86%), above the 69.75% floor. This workstation
currently has PHP 8.5 and MySQL 8.0, while CI pins PHP 8.4, MySQL 8.4 and PCOV.
The current behavioral suites were rerun here; the digest-pinned container
coverage run remains an ordinary required CI gate rather than being represented
as a locally reproduced environment.

## Baseline observations

There were no failing quality gates. Two accepted advisories remain visible:

- the file-length guard warns on files above 800 lines and still fails at
  1,000; twelve files currently warn and none fail; and
- webpack reports the Organizations admin entry at approximately 660 KiB, while
  the repository's explicit admin-bundle contract still passes.

The historical cold-container reviewer-queue timing issue remains documented in
[open-work.md](open-work.md). Its test passed this baseline in 7.7 seconds with
zero retries; one passing occurrence is not evidence that the historical flake
has been eliminated.

## Exit decision

P0 is complete: the current platform behavior has a green, documented baseline
and each named regression area is protected. P1 may now introduce the line-item
domain. Any P1 change must keep these gates green and add migration-specific
coverage for restartability, partial failure and uninterrupted delivery of
existing campaigns.

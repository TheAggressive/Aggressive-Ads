# Platform phase definition template

Copy this file when a platform phase is ready to move from roadmap intent into
implementation planning. Replace every bracketed instruction, remove sections
that genuinely do not apply, and explain why an expected section was removed.

The purpose of a phase definition is to make completion falsifiable. It must
name the behavior, migration and evidence required to change a phase to `[x]`;
a list of proposed classes or tables is not a completion contract.

## Status

- Phase: **P[NUMBER] — [NAME]**
- Roadmap state: `[ ]`
- Owner: [team or role, if assigned]
- Last audited: [date, once implementation begins]
- Authoritative environments: [versions or CI lanes that decide disagreements]

This document [defines future work / records in-progress work / records exit
evidence]. It does not claim completion unless the exit decision says so.

## Outcome

[Describe the observable capability delivered by this phase and who benefits.
State the product or operational problem it solves.]

## Scope boundary

This phase owns:

- [behavior or model];
- [behavior or model]; and
- [behavior or model].

This phase does not own:

- [later-phase responsibility and phase number];
- [adjacent responsibility]; or
- [explicit non-goal].

[State compatibility behavior that must remain in place until a later cutover.]

## Entry criteria

- [Required earlier phase is complete with a link to its evidence.]
- [Required schema, interface or invariant is stable.]
- [Required credentials or environment are available, if applicable.]
- The complete P0 regression baseline is green before implementation starts.

## Canonical model and ownership

[Name every new or changed entity. For each mutable fact, identify one
authoritative writer. Describe denormalized projections and what synchronizes
them. State which records are immutable and why.]

## Invariants

The implementation must enforce:

- [tenant and parent-child integrity];
- [lifecycle or state-transition rule];
- [security or privacy rule];
- [idempotency or concurrency rule];
- [retention or historical-integrity rule]; and
- [failure behavior].

If database foreign keys are omitted for WordPress compatibility, name the
application validation and deletion tests that provide equivalent protection.

## Migration and compatibility contract

[If no durable data changes, say so explicitly. Otherwise define:]

- source and destination representation;
- identity, attribution and history that must be preserved;
- batching, cursor, idempotency and duplicate-race behavior;
- lazy compatibility reads or dual-read/write behavior;
- behavior while migration is partial or failed;
- missing-schedule repair and operational visibility;
- multisite installation, upgrade and deletion behavior;
- rollback expectations; and
- the condition that retires compatibility code.

No user may be required to recreate valid existing data merely because the
storage model changed.

## Workflows and API

[List supported business operations rather than controllers. For each public
operation require parent-id verification, organization ownership, capability,
edit window, raw-input validation, optimistic concurrency where applicable,
rate limiting, safe response shape and audit evidence.]

Missing and foreign-tenant objects must remain non-enumerating. Staff-only and
persistence-only fields must not leak through shared serializers.

## Security, privacy and abuse cases

- [Authorization and tenant-crossing threats.]
- [Identifier, consent and retention implications.]
- [Replay, forgery, injection or unsafe-input threats.]
- [Resource-exhaustion and rate-limit threats.]
- [Secret and log-redaction requirements.]

Every claimed mitigation must map to executable evidence or an explicitly
documented operational control.

## Failure, recovery and rollback

[Describe durable-first ordering, transaction or compensation behavior,
retryability, dead-letter or failed-record handling, cache invalidation,
diagnostics, manual repair and safe rollback.]

A dependency outage must have one stated behavior: fail closed, fail open to a
safe fallback, queue for retry, or return an explicit error. It may not be left
to incidental exceptions.

## Performance and scale contract

- Expected cardinality: [records per tenant/site and growth assumptions].
- Hot-path query budget: [cold and warm maximums].
- Write budget: [queries, locks or external calls].
- Latency budget: [percentile and environment].
- Cache behavior: [key scope, invalidation, stampede protection and fallback].
- Large fixture: [minimum realistic test shape].

Indexes must follow actual query shapes. No fill, event write or ordinary screen
may perform work proportional to all records when it needs one bounded scope.

## Observability and operations

[Name health signals, structured identifiers, metrics, logs, alerts, Site
Health fields, migration state and runbook actions. State what must never be
logged.]

## Accessibility and internationalization

[Name affected interfaces, keyboard and focus behavior, labels, live regions,
reflow, colour independence, axe coverage and translation requirements. If the
phase has no UI, say so and require accessible handling for any diagnostics it
adds.]

## Required executable evidence

At minimum, consider:

- pure domain and transition-table tests;
- exact schema and index tests against real MySQL;
- production composition-root and hook wiring;
- fresh install, real-version upgrade, interrupted migration and rollback;
- authorization, tenant isolation, malformed input and replay;
- concurrency, idempotency and partial dependency failure;
- cold/warm query budgets and large fixtures;
- single-site, multisite, new-site and site-deletion behavior;
- REST or public protocol contract tests;
- browser, keyboard, reflow and axe coverage;
- packaging and compatibility-path coverage; and
- the complete P0 quality and dependency-security baseline.

A test name is not evidence by itself. A test promising audit, authorization,
migration, durability or fallback must assert the resulting row, denial,
production wiring, durable record or fallback.

## Documentation deliverables

Update every document affected by what actually ships, including as relevant:

- `domain-model.md`;
- `data-schema.md`;
- `rest-api.md`;
- `threat-model.md`;
- `administration.md`;
- `runbook.md`;
- `testing-strategy.md`; and
- `platform-implementation-progress.md`.

## Exit criteria

The phase may move to `[x]` only when:

1. [Observable outcome is functional end to end.]
2. [Migration and compatibility promises are proven.]
3. [Security, privacy and tenancy invariants are proven.]
4. [Failure and recovery behavior is proven.]
5. [Performance and scale budgets are met.]
6. [Operations and accessibility requirements are met.]
7. [Required tests and the P0 baseline are green authoritatively.]
8. [Documentation describes the shipped implementation.]

## Exit evidence and decision

[Complete only at closeout. Record commands, counts, environment caveats,
accepted advisories and links to executable evidence. State the decision and
why no required behavior remains unproven.]

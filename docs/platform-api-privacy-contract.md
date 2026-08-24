# Platform API, provider and privacy group contract

This contract governs P21 through P28, the roadmap section named Platform. It
defines how organizations delegate authority, how external systems call or
extend the plugin, and how privacy, supply-chain and traffic-quality controls
constrain every such integration. Each phase still requires a detailed
definition from
[platform-phase-definition-template.md](platform-phase-definition-template.md)
before implementation begins.

Nothing in this document marks P21–P28 as started or complete.

## Group outcome

Organizations can assign least-privilege roles, automate through scoped service
accounts and signed webhooks, connect external demand or media providers without
making them mandatory, publish verifiable supply-chain information, honor
consent and retention choices, and distinguish suspicious traffic without
silently changing billing or delivery guarantees.

Core remains functional with every optional provider disabled or unavailable.

## Entry and ordering

- P21 deliberately replaces the one-organization-per-user invariant. Its data
  migration and call-site audit must finish before public service accounts or
  webhooks rely on organization membership.
- P22 establishes versioning, authentication, scopes, rate limits and audit for
  external callers.
- P23 reuses P22 identities and P10/P19 business events for outbound webhooks.
- P24 extends the existing provider interface into a registry with declared
  capabilities and failure behavior.
- P25 integrates standards through P24; it does not build a DSP or SSP.
- P26 uses provider and publisher data but never overwrites publisher-controlled
  `ads.txt` without authorization.
- P27 gates identifier-dependent behavior in P9 and P12 regardless of its later
  roadmap number.
- P28 consumes measurement and consent facts; it classifies traffic but does not
  become an unreviewable payment or delivery authority.

## Shared trust model

The group distinguishes:

- **Organization role assignment** — user, organization, role, status and
  grant/revocation evidence.
- **Service account** — non-human principal owned by one organization with
  explicit scopes and lifecycle.
- **Credential** — revocable secret or key identifier, hashed/encrypted as
  appropriate, with creation, last-use, expiry and rotation metadata.
- **Webhook subscription and delivery** — scoped event selection, endpoint,
  signing-secret version, attempt log and terminal state.
- **Provider registration** — implementation id, capabilities, configuration,
  health and priority policy.
- **Consent state** — normalized purpose decisions and provenance from a
  CMP/GPP/TCF/GPC resolver, including unknown/unavailable states.
- **Supply-chain declaration** — observed or proposed ads.txt/sellers data with
  validation results and publisher ownership.
- **Traffic-quality assessment** — valid, suspicious or invalid classification
  with versioned reasons, confidence and source facts.

Human roles, service-account scopes, provider capabilities and consent purposes
are separate allowlists. Possessing one never implies another.

## Phase boundaries

### P21 — Organization-scoped RBAC

Owns membership and role assignment for users belonging to several
organizations. It layers organization authorization over WordPress capabilities
and removes every `$org_ids[0]` assumption deliberately.

### P22 — Public API and service accounts

Owns versioned resources, scopes, credential lifecycle, authentication, rate
limits, idempotency where needed and complete audit. Browser nonces are not
service credentials.

### P23 — Webhooks

Owns outbound event subscriptions, canonical payloads, signatures, event ids,
retries, delivery logs, disablement and signing-secret rotation. Delivery is
asynchronous and cannot roll back the source business transition.

### P24 — Provider system

Owns registry, capability discovery, configuration validation, health,
selection boundaries and graceful fallback for native, house, GAM,
Prebid/OpenRTB and VAST-compatible providers.

### P25 — Programmatic foundations

Owns standards integration and policy for external demand. It builds neither a
DSP nor SSP, and external demand never silently outranks a guarantee.

### P26 — Supply chain and ads.txt

Owns validation, warnings and authorized assistance. Publisher-controlled files
and declarations remain publisher-owned; automatic replacement requires an
explicit reversible workflow.

### P27 — Privacy and consent

Owns normalized consent/purpose resolution, identifier gates, retention policy,
export/erasure integration and behavior when no CMP or signal is available. It
is provider- and CMP-agnostic.

### P28 — Traffic quality

Owns versioned classification and reason metadata, review/override and reporting
of raw versus valid traffic. It does not present one heuristic as fraud proof or
silently rewrite immutable events.

## Authorization invariants

- Every organization-scoped operation receives an explicit organization
  context; code may not select the first organization as authority.
- WordPress super-admin behavior is explicit and audited rather than an
  accidental bypass.
- Role grants and revocations require dedicated capabilities, cannot grant more
  authority than the actor holds, and take effect consistently across caches.
- Service-account credentials are shown once, stored only as a verification
  value, individually revocable and never accepted through browser session
  paths.
- API scopes narrow WordPress and organization authorization; they never expand
  it.
- Enumeration-resistant responses apply to users, organizations, credentials,
  webhooks and provider objects.
- Every grant, revocation, credential lifecycle change and privileged override
  writes durable audit evidence.

## API and webhook contract

Public API versions have documented compatibility and retirement rules. Input
is schema-validated before business workflows; output serializers are scoped by
principal and exclude internal fields. Mutation endpoints define idempotency,
optimistic concurrency and pagination ordering.

Webhook payloads carry stable event id, event type, occurrence time, schema
version and organization scope. Signatures bind the exact transmitted bytes and
timestamp. Receivers get a documented replay window and rotation overlap.
Attempts use exponential backoff with jitter, bounded retention and a terminal
state visible to operators. Endpoints are protected against SSRF through scheme,
address and redirect policy.

## Provider and programmatic contract

Providers declare capabilities rather than relying on `instanceof` or product
names. Configuration and secrets are isolated per site, validated before
activation and redacted from screens, logs and exports. Provider code cannot
write core Campaign state outside published workflows.

Selection policy explicitly orders guarantees, house and external demand.
Timeout, invalid response, empty demand and circuit-open states have distinct
results. External markup is untrusted and uses the P18 handler protections.
OpenRTB/VAST identifiers and prices are normalized at the boundary without
making vendor fields the core domain model.

## Privacy and data-governance contract

P27 publishes a data inventory mapping every identifier and personal-data field
to purpose, lawful/consent condition, storage, recipients, retention, export and
erasure behavior. Consent is evaluated before collection or provider disclosure,
not repaired after the fact.

Unknown consent is a first-class state with a documented safe behavior. GPC and
applicable GPP/TCF signals cannot be weakened by a provider default. Consent
changes invalidate relevant caches and stop future use; deletion workflows cover
primary records, expiring stores, queued webhooks and provider requests to the
extent technically and legally supported.

No fingerprinting or secret alternate identifier is permitted. Hashed values
remain governed by their ability to single out or link a person.

## Supply-chain and traffic-quality contract

P26 distinguishes observed publisher content, validated recommendations and
authorized writes. Every proposed change is diffable, reversible and does not
discard unrelated publisher entries. Network/multisite ownership and path
selection must be explicit.

P28 assessments append classification and reasons without mutating raw events.
Rules/models are versioned, testable and monitor false-positive rates. Manual
override records actor and reason. Billing, refunds, campaign pause or provider
blocking requires a separate authorized workflow; classification alone cannot
perform those actions.

## Failure and availability contract

Credential stores and local authorization fail closed. Optional providers and
webhook destinations fail without taking core delivery or business transitions
down. Consent resolver failure follows the documented safest permitted mode.
Traffic-quality processing may lag while raw events remain durable and visibly
unclassified.

All background work is retryable and idempotent. Dead or terminal work is
visible with a safe replay operation. Rollback preserves newer role grants,
credentials, subscription history, consent records and assessments even when an
older release cannot use them.

## Performance and observability contract

Authorization adds a bounded number of indexed reads and supports cache
invalidation on grant/revoke. API pagination uses stable indexed cursors.
Webhook and traffic-quality processing never runs synchronously on fill.
Provider calls have strict connect/total timeouts, circuit health and bounded
fallback.

Operations can see credential failures and suspicious use, authorization
denials, webhook lag/terminal attempts, provider latency/error/circuit state,
ads.txt drift, consent resolver availability, retention/export/erasure progress
and traffic-quality classification lag. Logs redact credentials, signing
secrets, provider secrets, full webhook bodies and unnecessary personal data.

## Required group evidence

Across P21–P28, detailed phase tests must collectively prove:

- multi-organization membership, role matrix, cache invalidation and removal of
  every first-organization assumption;
- least-privilege grants, anti-escalation and multisite/super-admin behavior;
- credential hashing, one-time display, scope, expiry, rotation and revocation;
- API versioning, pagination, idempotency, concurrency and rate limits;
- webhook exact-byte signatures, replay window, rotation, SSRF protection,
  retries and source-transition independence;
- provider capability registry, all-disabled operation, timeout, circuit and
  guarantee-preserving fallback;
- hostile and malformed programmatic/VAST/OpenRTB payloads;
- ads.txt validation, diff, authorization, rollback and preservation of
  unrelated publisher content;
- CMP absent/unknown, GPC, GPP/TCF, consent changes, retention, export and
  erasure across every identifier store;
- traffic classification versions, reasons, false-positive fixtures, override
  and raw-versus-valid reports;
- tenant isolation, audit and secret/log redaction throughout;
- large-cardinality authorization/API/background-work budgets; and
- the complete P0 baseline after every phase.

## Group exit criteria

The API, Provider and Privacy group is complete only when P21–P28 are
individually `[x]` and:

1. Human and machine authority is organization-scoped, least-privilege,
   revocable and fully audited.
2. APIs and webhooks are versioned, replay-safe, rate-limited and operationally
   recoverable.
3. Core remains whole with all providers disabled or failing, and guarantees
   retain explicit precedence.
4. Supply-chain changes remain publisher-controlled and reversible.
5. Every identifier and disclosure obeys one documented consent, retention,
   export and erasure contract.
6. Traffic quality is explainable and separable from irreversible business
   action.
7. The authoritative quality baseline is green and documentation describes the
   shipped trust boundaries.

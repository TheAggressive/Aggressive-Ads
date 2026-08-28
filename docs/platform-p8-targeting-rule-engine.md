# Platform P8 targeting rule engine

P8 implements a declarative, schema-validated targeting rule engine for line items
and creative assignments. Rules are structured data evaluated purely in-memory
against normalized request facts (WordPress dimensions, device/request context,
geo, and user state), without executing arbitrary code or performing runtime
database lookups during the decision loop.

## Status

- Phase: **P8 — Targeting rule engine**
- Roadmap state: `[x]`
- Last audited: 2026-08-28
- Authoritative environments: the Docker CI lanes. `pnpm ci:verify` decides
  disagreements, not a local native run.

## Outcome

1. **Declarative Rule Schema**: Versioned AST supporting nested boolean logic (`AND`, `OR`, `NOT`) and typed comparison operators (`eq`, `neq`, `in`, `not_in`, `contains`, `exists`).
2. **Dimension Support**:
   - `wp`: Post type, categories, tags, post IDs, author.
   - `device`: Mobile, tablet, desktop.
   - `geo`: Country code, region (evaluated from normalized context facts).
   - `user`: Authenticated status, user roles.
   - `url`: Path prefixes, query parameters.
3. **Pure & Fast Evaluation**: Operates in $O(1)$ memory without database lookups during candidate filtering.
4. **Resilient Isolation**: Corrupted or malformed rule trees fail closed (excluding the candidate with `TARGETING_STAGE_ERROR` or `TARGETING_EXCLUDED`) without disrupting other competing candidates or the serving path.

## Scope boundary

**This phase owns:**

- **Targeting Rule Evaluator (`Targeting_Rules`)**: Pure domain evaluator for predicate trees;
- **Targeting Pipeline Stage (`Targeting_Stage`)**: Evaluating candidates within `Decision_Pipeline`;
- **Targeting Context in `Decision_Context`**: Holding normalized dimension facts; and
- **Targeting Exclusion Reasons**: `TARGETING_EXCLUDED` and `TARGETING_STAGE_ERROR`.

**This phase does not own:**

- **Frequency Capping Windows (P9)**; and
- **External GeoIP Provider Adapters** (Geo dimensions are passed as normalized strings/facts).

## Canonical model and invariants

### Targeting Schema

Targeting criteria are stored under `delivery_settings['targeting']` or `targeting_rules`:

```json
{
  "operator": "AND",
  "rules": [
    {
      "dimension": "device",
      "operator": "in",
      "value": ["mobile", "tablet"]
    },
    {
      "operator": "OR",
      "rules": [
        {
          "dimension": "wp.post_type",
          "operator": "eq",
          "value": "post"
        },
        {
          "dimension": "wp.category",
          "operator": "in",
          "value": ["news", "sports"]
        }
      ]
    }
  ]
}
```

### Invariants

1. **No Executable Code**: Stored targeting rules are pure data parsed via strict schemas; `eval()`, regex compilation from untrusted user input, and dynamic function invocation are strictly forbidden.
2. **Deterministic Evaluation**: Given the same candidate rule tree and `Decision_Context` facts, evaluation is 100% deterministic and idempotent.
3. **Safe Missing Facts**: If a rule targets a dimension not provided in the request context (e.g. geo country when geo is disabled), negative/inclusion rules fail cleanly without throwing PHP errors.
4. **Fault Isolation**: An exception or malformed JSON in candidate A's targeting rule immediately marks candidate A as excluded with `TARGETING_STAGE_ERROR` and allows candidate B to continue through the pipeline unaffected.

## Exit criteria and evidence

1. `Targeting_Rules` and `Targeting_Stage` implement pure domain logic and integrate into `Decision_Pipeline::standard()`.
2. Unit test suite `TargetingEvaluationTest` proves nested AND/OR evaluation, equality, membership, substring matching, missing facts, device filtering, and error isolation.
3. Static analysis (PHPStan Level 8) and coding standards (PHPCS) pass with 0 errors.

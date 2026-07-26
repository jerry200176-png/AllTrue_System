# CI Governance (G1)

**Commands:** `npm run ci:preflight` · `npm run sync:generated` · `npm run test:gov`  
**Policy code:** `scripts/ci/gov-codes.mjs` · `scripts/ci/branch-policy.mjs`  
**Distinct from** product/ops taxonomy: [`docs/knowledge/FAILURE_TAXONOMY.md`](../knowledge/FAILURE_TAXONOMY.md).

## Error codes

`GOV-BRANCH-001` branch · `GOV-PROV-001/002/003` provenance/markers · `GOV-GENERATED-001` drift · `GOV-SIZE-001` legacy ≤700 · `GOV-WORKFLOW-001` YAML · `GOV-BASE-001` stacked base · `GOV-SECRET-001` secrets.

## Branch prefixes

Accepted: `feat|fix|hotfix|refactor|test|docs|chore|ci|build|perf|exp|sec|audit|revert|release|design|cursor` + `td-batchN-*` `dependabot/*` `cubelv-cli-*`.  
Alias: `security`→`sec`. Rejected: `agent|ops|tmp|wip`.

## Size

G1 keeps Presubmit **≤700** hard gate (no self-exemption). Stacked: `PR_BASE_SHA`. Risk-based reviewability = G2.

## 30d baseline (→2026-07-26)

Runs ~11.2k · PR raw/pass-fail success ~76.5%/90.5% · first-pass green **66.7%** (n=60) · flake **~0.18%** · TTG median/p75 **4.2/13.2 min** · pushes/PR median **2** · Presubmit fails: size~42% branch~31% arch~19% (n=120).

## Benchmarks

- GitHub concurrency: cancel superseded **PR** runs; never cancel deploy/repair.  
- Google Small CLs / Chromium: self-contained change; don’t punish tests equally.  
- Microsoft flaky-test mgmt: detect same-SHA flakes; no silent quarantine.

## Non-goals

No `|| true` on required checks · no retry of deterministic gates · no Agent self-exception · no lowering auth/billing/migration bars.

# CI Governance (G1+G2)

**Commands:** `npm run ci:preflight` · `sync:generated` · `test:gov` · `ci:report` (`scripts/ci-actions-report.mjs`)

| Gate | Behavior |
|------|----------|
| Branch | `branch-policy.mjs` — accept `sec`/`design`/`cursor`/…; reject `agent`/`ops` |
| Reviewability | Risk-based + **base-aware** (`GITHUB_BASE_SHA`/`PR_BASE_SHA`); not raw 700 |
| Presubmit | Collect independent checks → **aggregator fail-closed** (no fake green) |
| Founder exception | `.github/founder-exceptions/PR-n-sha.json` on **`origin/main` only** |
| Efficiency | Cancel superseded PR runs; never cancel deploy/repair/credential |

**Thresholds:** warn score 400 · hard 900 · production_code 700 · high-risk lines 250 · docs/tests-only higher.  
Generated/binary excluded from burden; tests/docs discounted.

**30d baseline:** first-pass **66.7%** · flake **~0.18%** · TTG p50/p75 **4.2/13.2m**.  
Benchmarks: GitHub concurrency · Google Small CLs · Chromium test discount · Microsoft flake detection (no silent quarantine).

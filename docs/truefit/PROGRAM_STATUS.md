# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) |
| `origin/main` SHA (at reconcile base) | `c4a4c39a6ce2` |
| Slice 0–5 API + UI on main | **YES** through Mastery UI (#3005) |
| TF-S6-00a source_* auto-link | **THIS PR** (backend) |
| TF-S6-00b same-session continuum UI | **NEXT** |
| Operational acceptance | **NOT ACCEPTED** — staging #868 blocked; flags OFF |
| Production flags | **OFF** |

## Product thesis

AllTrue manages operations. TrueFit manages how students learn.

### Privacy (until Founder GO)

No real-student PII → external LLM. Fixture / teacher-entered only. No flag/DNS activation.

## Build Book

| Slice | Status |
|-------|--------|
| 0–5 | Coded+merged; not ops-accepted |
| 6 Continuum | 00a source-link (this PR); 00b UI next |
| Assessment Vendor Adapter | Not started |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence`

When upserting diagnosis / remediation / mastery without an explicit `source_*` id, the service now resolves the latest same-session prior artifact for that teacher (still nullable if none exists).

## Blockers

1. Staging (#868) — Platform-owned  
2. Founder gates: flags, DNS, real LLM, PII → LLM

## Next selected bounded task

1. **TF-S6-00b:** frontend same-session continuum CTAs + prior-stage seeding.  
2. Do not activate flags/DNS/staging ownership.

Never call work “done” merely because code exists.

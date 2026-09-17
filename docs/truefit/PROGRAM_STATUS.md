# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) |
| `origin/main` SHA (at reconcile base) | `f0907cbca33d` |
| Slice 0–5 API + UI on main | **YES** through Mastery UI (#3005) |
| TF-S6-00a source_* auto-link | **MERGED** (#3010) |
| TF-S6-00b same-session continuum UI | **THIS PR** (frontend) |
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
| 6 Continuum | 00a source-link merged (#3010); 00b continuum UI (this PR) |
| Assessment Vendor Adapter | Not started |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence`

When upserting diagnosis / remediation / mastery without an explicit `source_*` id, the service now resolves the latest same-session prior artifact for that teacher (still nullable if none exists). Frontend continuum CTAs + prior-stage form seeding land in TF-S6-00b (flags remain OFF).

## Blockers

1. Staging (#868) — Platform-owned  
2. Founder gates: flags, DNS, real LLM, PII → LLM

## Next selected bounded task

1. After 00b lands: Supervisor ops acceptance for full S6-00 (MERGED≠ACCEPTED).  
2. Do not activate flags/DNS/staging ownership.

Never call work “done” merely because code exists.

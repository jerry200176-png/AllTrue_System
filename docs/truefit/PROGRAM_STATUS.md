# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) |
| `origin/main` SHA (at reconcile base) | `f69b14ea9` |
| Slice 0–5 API + UI on main | **YES** through Mastery UI (#3005) |
| TF-S6-00a source_* auto-link | **MERGED** (#3010) |
| TF-S6-00b same-session continuum UI | **MERGED** (#3012) |
| TF-S6-01a session-progress aggregate API | Landed on 01a branch / awaiting merge |
| TF-S6-01b workspace progress strip UI | **THIS PR** |
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
| 6 Continuum | 00a+00b merged; S6-01b workspace progress strip UI (this PR; depends on 01a) |
| Assessment Vendor Adapter | Not started |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence` · `session-progress` (POST aggregate presence)

`POST /api/v1/truefit/session-progress` returns saved/empty booleans only for teacher-accessible session refs. Contract: **`omit_inaccessible`** — unknown/inaccessible refs are omitted (no per-id 403 leak). No writes; no new tables; flags remain OFF.

## Blockers

1. Staging (#868) — Platform-owned  
2. Founder gates: flags, DNS, real LLM, PII → LLM

## Next selected bounded task

1. After S6-01a+01b land: Supervisor ops acceptance (MERGED≠ACCEPTED).  
2. Do not activate flags/DNS/staging ownership. Do not start S6-02 until Plan GO.

Never call work “done” merely because code exists.

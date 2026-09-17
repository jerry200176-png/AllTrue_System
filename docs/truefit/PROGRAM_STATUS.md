# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) |
| `origin/main` SHA (at reconcile) | `0b786397ff138be72b961e3780b45ddd0a548279` |
| Slice 0–5 API + UI on main | **YES** through Mastery UI (#3005) |
| TF-S6-00a source_* auto-link | **MERGED** (#3010) |
| TF-S6-00b same-session continuum UI | **MERGED** (#3012); Supervisor **ACCEPTED** |
| TF-S6-01 continuum hardening | **MERGED** (workspace progress strip + CTA edges; Option A) |
| S6-00 Worker evidence | `docs/truefit/S6_00_ACCEPTANCE_EVIDENCE.md` |
| Operational acceptance | **NOT ACCEPTED** — staging #868 blocked; flags OFF |
| Production flags | **OFF** |
| Next Plan | **TF-S6-01** workspace progress — Plan only (`TF_S6_01_WORKSPACE_PROGRESS_PLAN.md`) |

---

## Product thesis

AllTrue manages operations. TrueFit manages how students learn.

### Privacy (until Founder GO)

No real-student PII → external LLM. Fixture / teacher-entered only. No flag/DNS activation.

---

## Current coded journey (S6-00)

```text
Teacher workspace (#/truefit)
  → Prep / Teacher Brief
  → Observation
  → Diagnosis          (seed if empty; source_observation_id)
  → Remediation        (seed if empty; source_diagnosis_id)
  → Mastery evidence   (seed if empty; source_remediation_id)
  → back to workspace
```

Backend auto-links `source_*` when omitted (#3010). Frontend continuum CTAs + seeding (#3012).

### Still open after S6-00 code land

1. Supervisor ops acceptance (MERGED ≠ ACCEPTED).  
2. Workspace progress strip — **TF-S6-01 Plan**.  
3. Next-lesson prep carry-forward — TF-S6-02 (not started).  
4. Staging #868 / Founder flag gates.

---

## Build Book

| Slice | Status |
|-------|--------|
| 0–5 | Coded+merged; not ops-accepted |
| 6 Continuum | **00 ACCEPTED**; **01 MERGED** (workspace progress + CTA edge polish, Option A) |
| Assessment Vendor Adapter | Not started |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence`

S6-01 reads those existing GETs from the workspace (client fan-out; no aggregate API). Continuum CTA / seed helpers fail closed on empty, partial, and already-saved edges. Flags remain OFF.

## Feature flags

| Flag | Default |
|------|---------|
| `TRUEFIT_V1` | `false` |
| `VITE_TRUEFIT_V1` | unset/false |

## Blockers

1. Staging (#868) — Platform-owned  
2. Founder gates: flags, DNS, real LLM, PII → LLM

## Next selected bounded task

1. Supervisor reconciles `S6_00_ACCEPTANCE_EVIDENCE.md` (flags stay OFF).
2. Do not begin TF-S6-02 next-lesson carry-forward without Plan Review.
3. Do not activate flags/DNS/staging ownership.

Never call work “done” merely because code exists.

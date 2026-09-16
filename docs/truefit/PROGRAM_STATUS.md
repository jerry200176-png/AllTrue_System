# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) |
| `origin/main` SHA (at reconcile base) | `f247c63c9495f84fa61fa6ae9c6ba2359e242658` |
| Slice 0–5 API + UI on main | **YES** (Brief → Observation → Diagnosis → Remediation → Mastery) |
| Same-session continuum | **IN PROGRESS** — TF-S6-00 loop handoff + `source_*` auto-link |
| Operational acceptance | **NOT ACCEPTED** — staging #868 blocked; flags OFF |
| Production flags | **OFF** |
| Active product priority | **TF-S6-00** same-session continuum (then next-lesson prep carry-forward) |

---

## Product thesis

AllTrue manages operations. TrueFit manages how students learn.

### Privacy (until Founder GO)

No real-student PII → external LLM. Fixture / teacher-entered only. No flag/DNS activation.

---

## Current user journey (coded)

```text
Teacher workspace (#/truefit)
  → Prep / Teacher Brief
  → Observation
  → Diagnosis          (seeded from observation; source_observation_id)
  → Remediation        (seeded from diagnosis; source_diagnosis_id)
  → Mastery evidence   (seeded from remediation; source_remediation_id)
  → back to workspace
```

Deep-links: `#/truefit/{prep|observe|diagnose|remediate|mastery}/…` with optional `?d=YYYY-MM-DD`.

### Gaps still open

1. **Cross-session carry-forward:** mastery / remediation does not yet seed *next* lesson prep (needs session identity across days; scheduling read only).
2. **Workspace progress badges:** today-sessions list does not yet show which stages are saved.
3. **Ops acceptance / flags:** `TRUEFIT_V1` + `VITE_TRUEFIT_V1` remain OFF; staging #868 Platform-owned.
4. **Slice 6 Assessment Vendor Adapter:** not started.

---

## Build Book

| Slice | Status |
|-------|--------|
| 0 Workspace shell + pure-read today sessions | Coded+merged; not ops-accepted |
| 1 Teacher Brief | Coded+merged (#2976–#2979, #2984) |
| 2 Observation | Coded+merged (#2985–#2989) |
| 3 Diagnosis | Coded+merged (#2990–#2995) |
| 4 Remediation | Coded+merged (#2996–#3000) |
| 5 Mastery | Coded+merged (#3001–#3005) |
| 6 Continuum / next-lesson | Loop handoff + source auto-link (this task); next-lesson prep TBD |
| 7 Assessment Vendor Adapter | Not started |

---

## Delivery log (recent)

| Outcome | PR | Merge SHA |
|---------|----|-----------|
| Observation UI | [#2989](https://github.com/jerry200176-png/AllTrue_System/pull/2989) | `9c04719eb` |
| Diagnosis UI | [#2995](https://github.com/jerry200176-png/AllTrue_System/pull/2995) | `440a13cde` |
| Remediation UI | [#3000](https://github.com/jerry200176-png/AllTrue_System/pull/3000) | `3b5ceb1bd` |
| Mastery API | [#3002](https://github.com/jerry200176-png/AllTrue_System/pull/3002) / [#3003](https://github.com/jerry200176-png/AllTrue_System/pull/3003) | `4b4d58143` / `bddbeeb08` |
| Mastery UI | [#3005](https://github.com/jerry200176-png/AllTrue_System/pull/3005) | `f247c63c9` |
| Same-session continuum | this PR | — |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence`

---

## Feature flags

| Flag | Default | Effect |
|------|---------|--------|
| `TRUEFIT_V1` (backend `.env` / `perfflags.truefit_v1`) | `false` | Hides all `/api/v1/truefit/*` (404) |
| `VITE_TRUEFIT_V1` (frontend build) | unset/false | Hides nav + standalone shell |

Both must be true for a teacher to use the workspace. Do **not** enable in production without Founder GO.

---

## Blockers

1. Staging (#868) — Platform-owned  
2. Founder gates: flags, DNS, real LLM, PII → LLM

## Next selected bounded task

1. **TF-S6-01:** workspace session progress strip (which stages saved) using existing GETs — no new tables.  
2. **TF-S6-02:** next-lesson prep carry-forward from prior mastery/remediation (document scheduling read dependency if needed).  
3. Do not activate flags/DNS/staging ownership.

Never call work “done” merely because code exists.

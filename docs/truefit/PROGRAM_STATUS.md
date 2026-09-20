# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
**Code authority:** if this file disagrees with `origin/main`, trust code + merged PRs.
**Status grammar:** never collapse CODE_WRITTEN / TESTS_PASSED / MERGED / DEPLOYED / RUNTIME_VERIFIED / OPERATIONALLY_ACCEPTED into “done”.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-17 (Asia/Taipei) — control-plane reconciliation |
| `origin/main` SHA (at reconcile) | `e91b911f383325432ccbd58d931c2f7481e7c5a7` |
| Slice 0–5 API + UI on main | **MERGED** through Mastery UI (#3005) |
| TF-S6-00a source_* auto-link | **MERGED** (#3010) |
| TF-S6-00b same-session continuum UI | **MERGED** (#3012) |
| TF-S6-01 workspace progress + CTA edges | **MERGED** (#3024 @ `fbed5a4d8`) — Option A client fan-out |
| S6-00 Worker evidence pack | `docs/truefit/S6_00_ACCEPTANCE_EVIDENCE.md` (**MERGED** docs #3017; ops acceptance **not** granted by that merge) |
| Production flags | **OFF** (`TRUEFIT_V1` / `VITE_TRUEFIT_V1`) |
| Pi production tip | **does not include** S6-01 — live prod SHA remains Founder-activated tip (see deployment.json); TrueFit dark on tip only if SHA contains it **and** flags stay OFF |
| Next Plan | **TF-S6-02** next-lesson prep carry-forward — **not started** (Plan required before impl) |

### Lifecycle snapshot (do not collapse)

| Slice | CODE_WRITTEN | TESTS_PASSED | MERGED | DEPLOYED (Pi tip) | RUNTIME_VERIFIED | OPERATIONALLY_ACCEPTED |
|-------|--------------|--------------|--------|-------------------|------------------|------------------------|
| 0–5 | YES | CI at merge | YES | only if tip SHA includes | NO (flags OFF / staging gaps) | NO |
| S6-00a/b | YES | CI at merge | YES (#3010/#3012) | only if tip SHA includes | NO | NO (evidence pack ≠ acceptance) |
| S6-01 | YES | CI at merge | YES (#3024) | only if tip SHA includes | NO | NO |
| S6-02 | NO | — | NO | NO | NO | NO |

---

## Product thesis

AllTrue manages operations. TrueFit manages how students learn.

Product intent and same-server/separate-site boundary are documented in [`PRODUCT_ARCHITECTURE_BRIEF.md`](PRODUCT_ARCHITECTURE_BRIEF.md). The detailed paper-evidence, AI, 30-day raw retention and no-relogin proposal is [`PAPER_EVIDENCE_AI_SSO_PROPOSAL.md`](PAPER_EVIDENCE_AI_SSO_PROPOSAL.md). Neither document changes delivery status or grants implementation/activation authority.

### Privacy (until Founder GO)

No real-student PII → external LLM. Fixture / teacher-entered only. No flag/DNS activation.

---

## Current coded journey (on `main`, flags OFF)

```text
Teacher workspace (#/truefit)
  → Prep / Teacher Brief
  → Observation
  → Diagnosis          (seed if empty; source_observation_id)
  → Remediation        (seed if empty; source_diagnosis_id)
  → Mastery evidence   (seed if empty; source_remediation_id)
  → back to workspace (S6-01 progress strip via GET fan-out)
```

Backend auto-links `source_*` when omitted (#3010). Frontend continuum CTAs + seeding (#3012). Workspace progress strip + CTA edge helpers (#3024).

### Still open after S6-01 code land

1. Supervisor / Founder **operational acceptance** for S6-00/S6-01 (MERGED ≠ ACCEPTED).  
2. **TF-S6-02** next-lesson prep carry-forward — Plan only; no impl.  
3. Staging / flag / DNS Founder gates.  
4. Do **not** treat `TF_S6_01_*_PLAN.md` as “implementation not started” — those plans were fulfilled by #3024; keep plans as historical design records only.

---

## Build Book

| Slice | Status |
|-------|--------|
| 0–5 | CODE+TESTS+MERGED; not ops-accepted |
| 6 Continuum | **00a/00b MERGED**; **01 MERGED** (#3024); not ops-accepted; flags OFF |
| Assessment Vendor Adapter | Not started |
| Paper Evidence / OCR / AI / SSO | Decision proposal documented; not implemented; pilot/provider selection + T3 auth/privacy Plan and Founder GO required |
| S6-02 | Not started |

### APIs behind `TRUEFIT_V1`

`material-units` · `lesson-preps` · `observations` · `diagnoses` · `remediations` · `mastery-evidence`

S6-01 reads those existing GETs from the workspace (client fan-out; no aggregate API). Continuum CTA / seed helpers fail closed on empty, partial, and already-saved edges. Flags remain OFF.

## Feature flags

| Flag | Default |
|------|---------|
| `TRUEFIT_V1` | `false` |
| `VITE_TRUEFIT_V1` | unset/false |

## Blockers

1. Staging / platform verification gaps  
2. Founder gates: flags, DNS, real LLM, PII → LLM  
3. Operational acceptance not granted

## Next selected bounded task

1. Keep flags OFF. Do not start TF-S6-02 without Plan Review + GO.  
2. Do not activate flags/DNS/staging ownership from this status file.  
3. Prefer a separate ops-acceptance Goal with independent runtime evidence — not another S6-01 “plan” rewrite.

Never call work “done” merely because code exists.

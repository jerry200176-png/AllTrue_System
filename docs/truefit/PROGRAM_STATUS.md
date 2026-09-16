# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
Slice-specific notes (`SLICE_0_STATUS.md`, etc.) are subordinate evidence;
reconcile them here after every cycle.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-16 (Asia/Taipei) |
| `origin/main` SHA | `554a585ad08033f8b931145657f3b707cb7a3349` |
| Slice 0 code on main | **YES** (stacked PRs A–E merged) |
| Slice 0 operational acceptance | **NOT ACCEPTED** — staging smoke blocked |
| Production flags | **OFF** (`TRUEFIT_V1` / `VITE_TRUEFIT_V1` default false) |
| Active product priority | Slice 1 — AI Teacher Brief (structured data) |

---

## Product thesis

AllTrue manages operations. TrueFit manages how students learn.

Core loop:

```text
teacher → today's student/session → choose material/unit
→ AI Teacher Brief → teach using original material
→ assessment evidence → AI diagnosis proposal → teacher confirmation
→ personalized remediation → delayed retrieval → mastery evidence
```

### Canonical boundaries

| Reuse AllTrue (read / identity) | TrueFit owns (new) |
|---------------------------------|--------------------|
| Auth, Student, Teacher/User, Campus | LessonPrep, LearningObjective, TeacherBrief |
| schedule / ClassSession, Subject | TeacherObservation, MisconceptionDiagnosis |
| enrollment operational data | Intervention, Remediation, MasteryEvidence |

Do **not** duplicate Student/User/Campus/Schedule.
Do **not** modify billing, attendance, or LearningRecord unless Founder-approved.

### Non-goals (program-wide)

MCP platform · generic RAG · student/parent chatbot · new identity ·
question-bank rebuild · microservices rewrite · production activation
without Founder gate.

### Privacy (until Founder GO)

- No real-student PII to external LLM
- Synthetic / minimized context only
- No production LLM credential activation

---

## Build Book commitments (roadmap)

| Slice | Outcome | Status |
|-------|---------|--------|
| **0** | Context / workspace — today sessions, pure-read, flag-gated shell | **Code on main + CI GREEN**; **runtime acceptance PENDING** (staging missing) |
| **1** | AI Prepare / Teacher Brief — material/unit → structured brief | **Not started** (next priority) |
| **2** | Teacher Observation | Not started |
| **3** | Error Diagnosis | Not started |
| **4** | Remediation | Not started |
| **5** | Delayed Retrieval / Mastery | Not started |
| **6** | Assessment Vendor Adapter | Later |

### Teacher Brief contract (Slice 1 must cover)

Structured data (not Markdown-only canonical state):

- learning objectives
- prior knowledge
- hook
- analogy / representation when useful
- prediction questions
- expected misconceptions
- hint ladders
- teaching moves tied to original material
- exit-ticket plan

---

## Slice 0 — reconciled facts

### Landed on main (dependency order)

| PR | Role | Merge SHA |
|----|------|-----------|
| [#2949](https://github.com/jerry200176-png/AllTrue_System/pull/2949) | A — pure index read extraction | `d983c2ee0` |
| [#2955](https://github.com/jerry200176-png/AllTrue_System/pull/2955) | B — projection / schedule-exception read | `7084f6d97` |
| [#2960](https://github.com/jerry200176-png/AllTrue_System/pull/2960) | C — `GET /api/v1/truefit/today-sessions` | `0593edcce` |
| [#2963](https://github.com/jerry200176-png/AllTrue_System/pull/2963) | D — teacher workspace shell | `8ff434bcc` |
| [#2965](https://github.com/jerry200176-png/AllTrue_System/pull/2965) | E — docs + nav + shell contract tests | `0107ff1c2` |

Oversized candidate [#2939](https://github.com/jerry200176-png/AllTrue_System/pull/2939) was **closed** (superseded by A–E). UI Smoke RED on that candidate was classified **flaky against production**, not a TrueFit regression.

### Runtime surface (code)

- Entry: `/#/truefit`, `?truefit=1`, host detect `truefit.*` (DNS **not** activated)
- API: `GET /api/v1/truefit/today-sessions` — teacher-only, campus-scoped, `meta.read_mode=pure_read`, `completeness=materialized_plus_projected`
- Flags: `TRUEFIT_V1` (backend) + `VITE_TRUEFIT_V1` (frontend build); both required
- Prep CTA → placeholder only (`TrueFitPrepPlaceholderPage`) — **not** Slice 1
- Pilot auth: same-origin hash route (subdomain SSO needs Founder-approved auth redesign — see `AUTH_SUBDOMAIN_FINDINGS.md`)

### Tests on main

- `TrueFitApiTest` — 8 tests
- `TrueFitShellContract.test.js` — 6 tests

### Staging acceptance

| Item | State |
|------|--------|
| Founder GO for staging smoke | Granted (2026-09-16) |
| Staging host / DNS | **Missing** — `staging.daan.lifenet.com.tw` unreachable; no `STAGING_*` secrets; issue [#868](https://github.com/jerry200176-png/AllTrue_System/issues/868) still `status:blocked` |
| Smoke scenarios 1–8 | **Not run** |
| Recommendation | **BLOCKED** on Platform/Staging workstream — not a TrueFit product defect |

Platform owns staging infrastructure. TrueFit Lead consumes staging when available and must **not** become staging owner.

### Production

- Flags remain dark-launch **OFF**
- No DNS / `truefit.<domain>` activation
- Recent `Deploy to Pi` failures on unrelated release evidence are **out of TrueFit scope**

---

## Outstanding docs / issues

| Item | State |
|------|--------|
| Open TrueFit-labeled GitHub issues | **None** found |
| Open TrueFit PRs | **None** |
| Canonical status | **This file** |
| `SLICE_0_STATUS.md` | Evidence sheet; must match this reconciliation |
| `docs/INDEX.md` TrueFit pointer | Added with this cycle |
| Build Book file | Commitments live in **this** document (no separate Build Book artifact existed on main) |

---

## Blockers vs non-blockers

### Blockers (runtime acceptance only)

1. Staging infrastructure (#868 / #875) — blocks Slice 0 **operational** acceptance and any staging flag smoke
2. Founder gate — production TrueFit flags, DNS subdomain, real LLM credentials, real-student PII to LLM

### Non-blockers (safe to continue)

1. Slice 1 design + implementation on feature branch: structured TeacherBrief schema, synthetic material/unit catalog, **deterministic / fixture AI provider** (no external LLM, no prod secrets)
2. Docs / contract tests / API + UI behind existing dark-launch flags
3. Local / CI verification without staging

---

## Next recommended ticket

**TF-S1-01 — Teacher Brief structured contract + fixture generator**

See Agent-executable Goal in the cycle report / issue body for this ticket.
Highest-value unblocked vertical slice after Slice 0 code land.

Do **not** ship a generic manual textarea as the product outcome.

---

## Cycle checklist (every TrueFit cycle)

1. Read this file
2. Verify `origin/main` SHA and TrueFit paths
3. Pick highest-value **unblocked** vertical slice
4. One bounded ticket / PR (≤ presubmit size gate)
5. Implement + targeted tests
6. Report: code · tests · PR · CI · merge · deploy · runtime verification
7. Update **this** file
8. Recommend next bounded ticket

Never call work “done” merely because code exists.

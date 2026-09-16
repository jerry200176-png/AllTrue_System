# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
Slice-specific notes (`SLICE_0_STATUS.md`, etc.) are subordinate evidence;
reconcile them here after every cycle.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-16 (Asia/Taipei) |
| `origin/main` SHA (at reconcile) | `f2ebf010dac02ef6ffa72843107187b668a912c9` |
| Slice 0 code on main | **YES** |
| Slice 0 operational acceptance | **NOT ACCEPTED** — staging smoke blocked (#868) |
| Slice 1 Teacher Brief on main | **YES** — contract + fixture + API + UI (#2976, #2978, #2979) |
| Slice 1 operational acceptance | **NOT ACCEPTED** — flags OFF; no staging smoke |
| Production flags | **OFF** (`TRUEFIT_V1` / `VITE_TRUEFIT_V1` default false) |
| Active product priority | TF-S1-02 prep deep-link / session_date hydration |

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
- Slice 1 uses **`fixture` provider only**

---

## Build Book commitments (roadmap)

| Slice | Outcome | Status |
|-------|---------|--------|
| **0** | Context / workspace | **Code on main + CI GREEN**; runtime acceptance **PENDING** (staging missing) |
| **1** | AI Prepare / Teacher Brief | **Coded+merged on main** (#2976/#2978/#2979); not operationally accepted |
| **2** | Teacher Observation | Not started |
| **3** | Error Diagnosis | Not started |
| **4** | Remediation | Not started |
| **5** | Delayed Retrieval / Mastery | Not started |
| **6** | Assessment Vendor Adapter | Later |

Teacher Brief structured contract: see `TEACHER_BRIEF_CONTRACT.md`.

---

## Delivery log (this cycle)

| Outcome | PR | Merge SHA | Deployed | Runtime verified |
|---------|----|-----------|----------|------------------|
| Canonical PROGRAM_STATUS | [#2974](https://github.com/jerry200176-png/AllTrue_System/pull/2974) | `124f1180a` | N/A (docs) | N/A |
| Teacher Brief contract + fixture | [#2976](https://github.com/jerry200176-png/AllTrue_System/pull/2976) | `937419f1d` | N/A (dark launch) | Pending flags/staging |
| Lesson-prep API + persistence | [#2978](https://github.com/jerry200176-png/AllTrue_System/pull/2978) | `9d16608ec` | Migration ships with next prod deploy path; flag still OFF | Not accepted |
| Material select + brief UI | [#2979](https://github.com/jerry200176-png/AllTrue_System/pull/2979) | `ed2457fc4` | N/A (dark launch) | Not accepted |
| PROGRAM_STATUS Slice 1 reconcile | this PR | — | N/A (docs) | N/A |

### Slice 1 APIs (behind `TRUEFIT_V1`)

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/material-units` |
| GET | `/api/v1/truefit/lesson-preps` |
| POST | `/api/v1/truefit/lesson-preps/generate` |

Persistence: `truefit_lesson_preps` (additive Simple Add migration).

UI entry: prep hash route → material `AtSelect` → structured Teacher Brief blocks (no textarea notebook).

---

## Slice 0 — summary

Stacked PRs #2949 → #2955 → #2960 → #2963 → #2965 on main. Staging smoke **BLOCKED** (#868). Production flags **OFF**. Pilot path remains `/#/truefit`.

---

## Blockers vs non-blockers

### Blockers (runtime acceptance)

1. Staging infrastructure (#868 / #875) — Platform-owned
2. Founder gates: prod flags, DNS subdomain, real LLM credentials, real-student PII → LLM

### Non-blockers (continue)

- TF-S1-02 prep deep-link / `session_date` hydration on refresh
- Docs / PROGRAM_STATUS refresh
- Local CI for TrueFit suites
- Slice 2 design only after Slice 1 hydration + acceptance criteria clear

---

## Next selected bounded task

1. **TF-S1-02:** Encode `session_date` in prep deep-links; hydrate projected + materialized prep sessions on refresh without inventing UTC "today"; enrich labels from today-sessions when available.
2. **Do not** start external LLM wiring, flag activation, DNS, or staging ownership.

Staging remains Platform-owned; TrueFit runtime acceptance stays PENDING until staging exists.

---

## Cycle checklist

1. Read this file
2. Verify `origin/main` SHA and TrueFit paths
3. Pick highest-value **unblocked** vertical slice
4. One bounded ticket / PR (≤ presubmit size gate)
5. Implement + targeted tests
6. Report: code · tests · PR · CI · merge · deploy · runtime verification
7. Update **this** file
8. Recommend next bounded ticket

Never call work “done” merely because code exists.

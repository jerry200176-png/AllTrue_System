# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
Slice-specific notes (`SLICE_0_STATUS.md`, etc.) are subordinate evidence;
reconcile them here after every cycle.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-16 (Asia/Taipei) |
| `origin/main` SHA (at reconcile) | `9d16608ec0abb377e3806bf34176c212bdc573ef` |
| Slice 0 code on main | **YES** |
| Slice 0 operational acceptance | **NOT ACCEPTED** — staging smoke blocked (#868) |
| Slice 1 backend on main | **YES** — contract + fixture + lesson-prep API (#2976, #2978) |
| Slice 1 frontend on main | **IN FLIGHT** — [#2979](https://github.com/jerry200176-png/AllTrue_System/pull/2979) |
| Production flags | **OFF** (`TRUEFIT_V1` / `VITE_TRUEFIT_V1` default false) |
| Active product priority | Finish Slice 1 UI merge → local/CI verification; staging still Platform-owned |

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
| **1** | AI Prepare / Teacher Brief | **Backend merged**; **UI PR open** (#2979); not operationally accepted |
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
| Material select + brief UI | [#2979](https://github.com/jerry200176-png/AllTrue_System/pull/2979) | — | — | — |

### Slice 1 APIs (behind `TRUEFIT_V1`)

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/material-units` |
| GET | `/api/v1/truefit/lesson-preps` |
| POST | `/api/v1/truefit/lesson-preps/generate` |

Persistence: `truefit_lesson_preps` (additive Simple Add migration).

---

## Slice 0 — summary

Stacked PRs #2949 → #2955 → #2960 → #2963 → #2965 on main. Staging smoke **BLOCKED** (#868). Production flags **OFF**. Pilot path remains `/#/truefit`.

---

## Blockers vs non-blockers

### Blockers (runtime acceptance)

1. Staging infrastructure (#868 / #875)
2. Founder gates: prod flags, DNS subdomain, real LLM credentials, real-student PII → LLM

### Non-blockers (continue)

- Slice 1 UI merge + shell contract tests
- Docs / PROGRAM_STATUS refresh
- Local CI for TrueFit suites
- Slice 2 design only after Slice 1 coded+merged on main

---

## Next selected bounded task

1. **Land #2979** (Teacher Brief UI) — CI → squash-merge.
2. **TF-S1-02 (if needed):** deep-link prep hydration for projected sessions / session_date accuracy on refresh.
3. **Do not** start external LLM wiring or staging ownership.

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

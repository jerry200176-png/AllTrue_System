# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
Slice-specific notes (`SLICE_0_STATUS.md`, etc.) are subordinate evidence;
reconcile them here after every cycle.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-16 (Asia/Taipei) |
| `origin/main` SHA (at reconcile) | `bef4fb0225adb5e2244c8cfb8dae48e46d5f21f3` |
| Slice 0 code on main | **YES** |
| Slice 0 operational acceptance | **NOT ACCEPTED** — staging smoke blocked (#868) |
| Slice 1 Teacher Brief on main | **YES** — contract + fixture + API + UI + prep hydration |
| Slice 1 operational acceptance | **NOT ACCEPTED** — flags OFF; no staging smoke |
| Slice 2 Observation API on main | **YES** — contract + table + GET/POST (#2985/#2986/#2987) |
| Slice 2 Observation UI | **IN FLIGHT** — this PR |
| Production flags | **OFF** (`TRUEFIT_V1` / `VITE_TRUEFIT_V1` default false) |
| Active product priority | Land TF-S2-02 UI → TF-S2-03 optional fixture polish |

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
- Slice 2 v0.1 is **teacher-entered structured form** (LLM draft blocked)

---

## Build Book commitments (roadmap)

| Slice | Outcome | Status |
|-------|---------|--------|
| **0** | Context / workspace | **Code on main + CI GREEN**; runtime acceptance **PENDING** (staging missing) |
| **1** | AI Prepare / Teacher Brief | **Coded+merged** (#2976/#2978/#2979/#2984); not operationally accepted |
| **2** | Teacher Observation | **API on main**; **UI in flight** (this PR) |
| **3** | Error Diagnosis | Not started |
| **4** | Remediation | Not started |
| **5** | Delayed Retrieval / Mastery | Not started |
| **6** | Assessment Vendor Adapter | Later |

Teacher Brief: `TEACHER_BRIEF_CONTRACT.md`.  
Teacher Observation: `TEACHER_OBSERVATION_CONTRACT.md`.

---

## Delivery log (this cycle)

| Outcome | PR | Merge SHA | Deployed | Runtime verified |
|---------|----|-----------|----------|------------------|
| Canonical PROGRAM_STATUS | [#2974](https://github.com/jerry200176-png/AllTrue_System/pull/2974) | `124f1180a` | N/A (docs) | N/A |
| Teacher Brief contract + fixture | [#2976](https://github.com/jerry200176-png/AllTrue_System/pull/2976) | `937419f1d` | N/A (dark launch) | Pending flags/staging |
| Lesson-prep API + persistence | [#2978](https://github.com/jerry200176-png/AllTrue_System/pull/2978) | `9d16608ec` | Flag still OFF | Not accepted |
| Material select + brief UI | [#2979](https://github.com/jerry200176-png/AllTrue_System/pull/2979) | `ed2457fc4` | N/A (dark launch) | Not accepted |
| PROGRAM_STATUS Slice 1 reconcile | [#2982](https://github.com/jerry200176-png/AllTrue_System/pull/2982) | `51e81cdd2` | N/A (docs) | N/A |
| Prep deep-link session_date hydration | [#2984](https://github.com/jerry200176-png/AllTrue_System/pull/2984) | `4586ae1b9` | N/A (dark launch) | Not accepted |
| Teacher Observation contract | [#2985](https://github.com/jerry200176-png/AllTrue_System/pull/2985) | `ca91a3a14` | N/A (docs) | N/A |
| Observation table + validator | [#2986](https://github.com/jerry200176-png/AllTrue_System/pull/2986) | `405cb952f` | Flag still OFF | Not accepted |
| Observation GET/POST API | [#2987](https://github.com/jerry200176-png/AllTrue_System/pull/2987) | `bef4fb022` | Flag still OFF | Not accepted |
| Observation UI | this PR | — | N/A (dark launch) | Not accepted |

### Slice 1–2 APIs (behind `TRUEFIT_V1`)

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/material-units` |
| GET | `/api/v1/truefit/lesson-preps` |
| POST | `/api/v1/truefit/lesson-preps/generate` |
| GET | `/api/v1/truefit/observations` |
| POST | `/api/v1/truefit/observations` |

---

## Blockers vs non-blockers

### Blockers (runtime acceptance)

1. Staging infrastructure (#868 / #875) — Platform-owned
2. Founder gates: prod flags, DNS subdomain, real LLM credentials, real-student PII → LLM

### Non-blockers (continue)

- TF-S2-02 observation UI
- TF-S2-03 optional fixture sample / shell polish
- Docs / PROGRAM_STATUS refresh

---

## Next selected bounded task

1. **Land this PR** (TF-S2-02 observation UI + observe deep-links).
2. **TF-S2-03 (optional):** fixture sample observation + shell contract polish.
3. **Do not** start Slice 3 diagnosis, external LLM, flag activation, DNS, or staging ownership.

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

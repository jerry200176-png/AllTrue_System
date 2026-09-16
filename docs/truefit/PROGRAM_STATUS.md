# TrueFit v0.1 — Canonical program status

**Authority:** this file is the single program status document for TrueFit.
Slice-specific notes (`SLICE_0_STATUS.md`, etc.) are subordinate evidence;
reconcile them here after every cycle.

| Field | Value |
|-------|--------|
| Reconciled at | 2026-09-16 (Asia/Taipei) |
| `origin/main` SHA (at reconcile) | `9c04719eb4805e9b155c5d636d58fc0d7700e503` |
| Slice 0–1 code on main | **YES** |
| Slice 0–1 operational acceptance | **NOT ACCEPTED** — staging smoke blocked (#868); flags OFF |
| Slice 2 Observation on main | **YES** — contract + API + UI (#2985–#2989) |
| Slice 2 operational acceptance | **NOT ACCEPTED** — flags OFF; no staging smoke |
| Slice 3 Diagnosis API | **IN FLIGHT** — TF-S3-01 persistence + GET/POST (this PR) |
| Production flags | **OFF** |
| Active product priority | Land TF-S3-01 API → TF-S3-02 diagnosis UI |

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

### Privacy (until Founder GO)

- No real-student PII to external LLM
- Synthetic / minimized context only
- No production LLM credential activation
- Slice 1 fixture-only; Slice 2 teacher-entered; Slice 3 LLM auto-diagnosis blocked

---

## Build Book commitments (roadmap)

| Slice | Outcome | Status |
|-------|---------|--------|
| **0** | Context / workspace | Code on main; runtime **PENDING** (#868) |
| **1** | AI Prepare / Teacher Brief | Coded+merged; not operationally accepted |
| **2** | Teacher Observation | Coded+merged (#2985–#2989); not operationally accepted |
| **3** | Error Diagnosis | Contract drafting (`ERROR_DIAGNOSIS_CONTRACT.md`) |
| **4** | Remediation | Not started |
| **5** | Delayed Retrieval / Mastery | Not started |
| **6** | Assessment Vendor Adapter | Later |

Contracts: `TEACHER_BRIEF_CONTRACT.md` · `TEACHER_OBSERVATION_CONTRACT.md` · `ERROR_DIAGNOSIS_CONTRACT.md`.

---

## Delivery log (recent)

| Outcome | PR | Merge SHA | Deployed | Runtime verified |
|---------|----|-----------|----------|------------------|
| Prep hydration | [#2984](https://github.com/jerry200176-png/AllTrue_System/pull/2984) | `4586ae1b9` | N/A | Not accepted |
| Observation contract | [#2985](https://github.com/jerry200176-png/AllTrue_System/pull/2985) | `ca91a3a14` | N/A | N/A |
| Observation table + validator | [#2986](https://github.com/jerry200176-png/AllTrue_System/pull/2986) | `405cb952f` | Flag OFF | Not accepted |
| Observation API | [#2987](https://github.com/jerry200176-png/AllTrue_System/pull/2987) | `bef4fb022` | Flag OFF | Not accepted |
| Observation UI | [#2989](https://github.com/jerry200176-png/AllTrue_System/pull/2989) | `9c04719eb` | Flag OFF | Not accepted |
| Diagnosis contract + status | this PR | — | N/A | N/A |

---

## Blockers vs non-blockers

### Blockers (runtime acceptance)

1. Staging infrastructure (#868) — Platform-owned
2. Founder gates: prod flags, DNS, real LLM, real-student PII → LLM

### Non-blockers (continue)

- TF-S3-00 diagnosis contract (docs)
- TF-S3-01 diagnosis persistence + validator
- Local CI for TrueFit suites

---

## Next selected bounded task

1. **Land this PR** (TF-S3-00 diagnosis contract + PROGRAM_STATUS).
2. **TF-S3-01:** Additive diagnosis persistence + PHP validator + feature tests (no external LLM).
3. **Do not** activate flags, DNS, staging ownership, or billing/LearningRecord writes.

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

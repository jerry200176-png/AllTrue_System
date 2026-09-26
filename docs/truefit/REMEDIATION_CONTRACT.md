# TrueFit Remediation contract (Slice 4)

Canonical Remediation / Intervention plan is **structured JSON**, not free-text-only notes.

This document locks the **product contract** before persistence/API/UI land.

## Product intent

After a teacher-confirmed diagnosis (Slice 3), TrueFit proposes a **bounded remediation plan**
tied to the original material and the diagnosed misconception — then the teacher confirms
before any student-facing assignment exists.

```text
Diagnosis (accepted) → Remediation plan → teacher confirm
→ Delayed retrieval / mastery (Slice 5)
```

## Required keys

| Key | Type | Notes |
|-----|------|-------|
| `schema_version` | `string` | Fixed `truefit.remediation.v1` |
| `session_ref` | `object` | Same identity as Observation/Diagnosis |
| `source_diagnosis_id` | `number\|null` | Link to `truefit_diagnoses.id` when present |
| `planned_at` | `string` | ISO-8601 |
| `target_misconception_label` | `string` | Must align with accepted diagnosis |
| `practice_moves` | `string[]` | Non-empty teaching/practice actions |
| `material_anchors` | `string[]` | Pointers into original material (synthetic keys ok) |
| `success_criteria` | `string[]` | Non-empty |
| `follow_up_window` | `string` | e.g. `same_session` \| `next_session` \| `within_7_days` |
| `teacher_decision` | `string` | `pending` \| `accepted` \| `edited` \| `rejected` |
| `teacher_notes` | `string` | Short |

## Provider / privacy

| Mode | Status |
|------|--------|
| Teacher-confirmed remediation | **In scope** |
| Fixture samples | Allowed for tests |
| External LLM plan generation | **Blocked** until Founder GO |
| Real-student PII → LLM | **Forbidden** |

## Non-goals

- Auto-creating LearningRecord homework rows
- Parent/student app push
- Billing / session deduction changes

## APIs (behind `TRUEFIT_V1`)

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/remediations` |
| POST | `/api/v1/truefit/remediations` |

## Implementation order

| Ticket | Outcome |
|--------|---------|
| **TF-S4-00** | Contract + PROGRAM_STATUS |
| **TF-S4-01** | Additive persistence + validator + tests (**in progress**) |
| **TF-S4-02** | Teacher UI confirm flow |

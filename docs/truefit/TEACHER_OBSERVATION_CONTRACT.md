# TrueFit Teacher Observation contract (Slice 2)

Canonical Teacher Observation state is **structured JSON**, not free-text-only notes.

This document locks the **product contract** before persistence/API/UI land.
No schema, routes, or flags are activated by this file alone.

## Product intent

After (or during) a prepared lesson, the teacher records **what they observed**
about the learner relative to the Teacher Brief — so Slice 3 diagnosis has
grounded evidence instead of inventing student state.

```text
Teacher Brief (Slice 1) → teach with original material
→ Teacher Observation (Slice 2) → diagnosis proposal (Slice 3)
```

## Required keys

| Key | Type | Notes |
|-----|------|-------|
| `schema_version` | `string` | Fixed `truefit.observation.v1` |
| `session_ref` | `object` | See session identity below |
| `observed_at` | `string` | ISO-8601 timestamp (local capture ok; store UTC equivalent later) |
| `objectives_touched` | `string[]` | Subset / paraphrase of brief learning objectives; may be empty if none applied |
| `student_moves` | `string[]` | What the student did/said (minimized; no raw PII dumps) |
| `struggle_signals` | `object[]` | Non-empty when struggle observed; else `[]` |
| `strength_signals` | `string[]` | Optional positives; may be empty |
| `misconception_hypotheses` | `object[]` | Teacher hypotheses only — not confirmed diagnoses |
| `evidence_notes` | `string` | Short free text; not the canonical store of structure |
| `confidence` | `string` | One of `low` \| `medium` \| `high` |

### `struggle_signals[]` object

| Field | Type |
|-------|------|
| `label` | `string` |
| `signal` | `string` |
| `linked_objective` | `string` (may be empty) |

### `misconception_hypotheses[]` object

| Field | Type |
|-------|------|
| `label` | `string` |
| `what_student_seemed_to_believe` | `string` |
| `what_to_check_next` | `string` |

## Session identity (reuse AllTrue / TrueFit Slice 0–1)

Exactly one of:

1. **Materialized:** `{ "class_session_id": number }`
2. **Projected:** `{ "student_class_id": number, "session_date": "YYYY-MM-DD", "start_time": "HH:MM" }`

Do **not** invent a parallel student/course identity.
Do **not** write LearningRecord, attendance, or billing rows from Slice 2.

## Provider / privacy policy (v0.1)

| Mode | Status |
|------|--------|
| Teacher-entered structured form | **In scope** for Slice 2 implementation |
| Fixture / synthetic sample observation | Allowed for tests and demos |
| External LLM draft-from-transcript | **Blocked** until Founder GO + privacy review |
| Real-student PII → external LLM | **Forbidden** |

## Non-goals (Slice 2)

- Auto-grading or mastery scoring
- Parent/student-visible feed
- Replacing LearningRecord narrative
- Cross-campus analytics warehouse
- Real-time classroom audio capture

## Planned APIs (implementation tickets; not live yet)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/truefit/observations` | Lookup by session_ref |
| POST | `/api/v1/truefit/observations` | Upsert teacher observation |
| GET | `/api/v1/truefit/observations/schema` | Optional contract echo for UI |

All will require `TRUEFIT_V1=true` and teacher role when implemented.

## Implementation order (bounded tickets)

| Ticket | Outcome |
|--------|---------|
| **TF-S2-00** | This contract + PROGRAM_STATUS pointer (**this PR**) |
| **TF-S2-01** | Additive persistence + PHP contract validator + feature tests |
| **TF-S2-02** | Teacher UI: structured observation form linked from prep/workspace |
| **TF-S2-03** | Optional fixture sample + shell contract tests |

## Acceptance (Slice 2 coded)

- Structured observation survives refresh for a given session_ref
- No textarea-only canonical state
- No external LLM calls
- Flags remain dark-launch until Founder GO
- Staging smoke still Platform-owned (#868)

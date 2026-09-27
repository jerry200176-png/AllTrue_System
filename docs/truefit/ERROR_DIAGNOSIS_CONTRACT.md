# TrueFit Error Diagnosis contract (Slice 3)

Canonical Misconception Diagnosis state is **structured JSON**, not free-text-only notes.

This document locks the **product contract** before persistence/API/UI land.
No schema, routes, or flags are activated by this file alone.

## Product intent

Teacher Observation (Slice 2) supplies grounded classroom evidence.
Slice 3 turns that evidence (plus Teacher Brief context) into a **diagnosis proposal**
the teacher must confirm before remediation (Slice 4).

```text
Teacher Brief → Observation → Diagnosis proposal → teacher confirm/edit
→ Remediation (Slice 4)
```

## Required keys

| Key | Type | Notes |
|-----|------|-------|
| `schema_version` | `string` | Fixed `truefit.diagnosis.v1` |
| `session_ref` | `object` | Same identity rules as Observation |
| `source_observation_id` | `number\|null` | Link to `truefit_observations.id` when present |
| `proposed_at` | `string` | ISO-8601 |
| `primary_misconception` | `object` | Required |
| `supporting_signals` | `string[]` | From observation / brief; may be empty |
| `ruled_out` | `string[]` | Explicit negatives; may be empty |
| `recommended_checks` | `string[]` | Non-empty |
| `confidence` | `string` | `low` \| `medium` \| `high` |
| `teacher_decision` | `string` | `pending` \| `accepted` \| `edited` \| `rejected` |
| `teacher_notes` | `string` | Short; not SSOT alone |

### `primary_misconception` object

| Field | Type |
|-------|------|
| `label` | `string` |
| `statement` | `string` |
| `why_it_fits_observation` | `string` |
| `linked_brief_objective` | `string` (may be empty) |

## Provider / privacy policy (v0.1)

| Mode | Status |
|------|--------|
| Teacher-authored / teacher-confirmed diagnosis | **In scope** |
| Fixture / synthetic diagnosis samples | Allowed for tests |
| External LLM auto-diagnosis | **Blocked** until Founder GO + privacy review |
| Real-student PII → external LLM | **Forbidden** |

## Non-goals (Slice 3)

- Auto-writing LearningRecord narratives
- Parent-visible diagnosis feed
- Billing / attendance side effects
- Full misconception ontology platform

## APIs (behind `TRUEFIT_V1`)

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/v1/truefit/diagnoses` | Lookup by session_ref |
| POST | `/api/v1/truefit/diagnoses` | Upsert proposal / teacher decision |

Validator: `App\Services\TrueFit\TrueFitErrorDiagnosisContract`.
Persistence: `truefit_diagnoses`.

## Implementation order

| Ticket | Outcome |
|--------|---------|
| **TF-S3-00** | Contract + PROGRAM_STATUS pointer |
| **TF-S3-01** | Additive persistence + PHP validator + feature tests (**in progress**) |
| **TF-S3-02** | Teacher UI: review/confirm diagnosis from observation |

## Acceptance (Slice 3 coded)

- Diagnosis proposal survives refresh for a session_ref
- Teacher decision is explicit (`pending`/`accepted`/`edited`/`rejected`)
- No external LLM calls
- Flags remain dark-launch until Founder GO

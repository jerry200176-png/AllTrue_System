# TrueFit Delayed Retrieval / Mastery contract (Slice 5)

Canonical Mastery Evidence is **structured JSON**, not free-text-only notes.

This document locks the **product contract** before persistence/API/UI land.

## Product intent

After remediation (Slice 4), schedule a **delayed retrieval check** and record
whether the misconception has been repaired — producing mastery evidence the
teacher can trust without inventing student state.

```text
Remediation (accepted) → Delayed retrieval prompt → Mastery evidence
→ (later) Assessment vendor adapter (Slice 6)
```

## Required keys

| Key | Type | Notes |
|-----|------|-------|
| `schema_version` | `string` | Fixed `truefit.mastery.v1` |
| `session_ref` | `object` | Same identity rules as prior slices |
| `source_remediation_id` | `number\|null` | Link to `truefit_remediations.id` when present |
| `checked_at` | `string` | ISO-8601 |
| `target_misconception_label` | `string` | Aligns with remediation target |
| `retrieval_prompt` | `string` | What was asked / checked |
| `student_response_summary` | `string` | Minimized; no raw PII dumps |
| `outcome` | `string` | `mastered` \| `partial` \| `not_yet` \| `not_checked` |
| `evidence_notes` | `string` | Short |
| `next_review_window` | `string` | `none` \| `within_7_days` \| `within_30_days` |
| `teacher_decision` | `string` | `pending` \| `accepted` \| `edited` \| `rejected` |

## Provider / privacy

| Mode | Status |
|------|--------|
| Teacher-entered mastery check | **In scope** |
| Fixture samples | Allowed for tests |
| External LLM auto-scoring | **Blocked** until Founder GO |
| Real-student PII → LLM | **Forbidden** |

## Non-goals

- Auto gradebook writeback
- Parent portal mastery feed
- Billing / attendance side effects

## APIs (behind `TRUEFIT_V1`)

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/mastery-evidence` |
| POST | `/api/v1/truefit/mastery-evidence` |

## Implementation order

| Ticket | Outcome |
|--------|---------|
| **TF-S5-00** | Contract + PROGRAM_STATUS |
| **TF-S5-01a** | Additive table + model + validator + unit tests |
| **TF-S5-01b** | Service + routes + feature API tests |
| **TF-S5-02** | Teacher UI mastery check form (**this PR**) |

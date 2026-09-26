# TrueFit Teacher Brief contract (Slice 1)

Canonical Teacher Brief state is **structured JSON**, not Markdown.

## Required keys

| Key | Type |
|-----|------|
| `learning_objectives` | `string[]` (non-empty) |
| `prior_knowledge` | `string` |
| `hook` | `string` |
| `analogy_or_representation` | `string` (may be empty only if intentionally unused; fixture always fills) |
| `prediction_questions` | `string[]` (non-empty) |
| `expected_misconceptions` | `object[]` (non-empty) |
| `hint_ladders` | `object[]` (non-empty) |
| `teaching_moves_tied_to_material` | `string[]` (non-empty) |
| `exit_ticket_plan` | `object` (non-empty) |

Validator: `App\Services\TrueFit\TrueFitTeacherBriefContract`.

## Provider policy (v0.1)

| Provider | Status |
|----------|--------|
| `fixture` | **Active** — deterministic, no external LLM, no student PII |
| External LLM | **Blocked** until Founder GO + privacy review |

## APIs

| Method | Path |
|--------|------|
| GET | `/api/v1/truefit/material-units` |
| GET | `/api/v1/truefit/lesson-preps` |
| POST | `/api/v1/truefit/lesson-preps/generate` |

All require `TRUEFIT_V1=true` and teacher role.

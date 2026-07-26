# Parent Binding — Reason Code Contract (PB-00)

| Field | Value |
|-------|-------|
| Status | Active (PB-00) |
| Source | ADR Accepted 2026-07-26 · architecture reason table |
| Audience | Backend / ops — **not** parent-facing copy |

Machine `reason_code` ≠ display `message`. Frontend and ops must **never** parse Chinese messages for state.

## Outcomes

| outcome | Meaning |
|---------|---------|
| `success` | New bind or portal session created |
| `failure` | Attempt rejected |
| `noop` | No new bind (e.g. already bound) — **not** counted as success |

## Reason codes (PB-00 legacy name / id + phone)

| Code | Typical use |
|------|-------------|
| `STUDENT_NOT_FOUND` | No name/id candidate in scope |
| `CONTACT_PHONE_MISSING` | Candidate(s) exist; authoritative contact empty (`parent_phone` → `Phone`) |
| `PHONE_MISMATCH` | Candidate has contact; input does not match |
| `AMBIGUOUS_MATCH` | Portal name+phone matches multiple students (409) |
| `CAMPUS_MISMATCH` | Defensive; id student outside webhook campus |
| `ALREADY_BOUND` | LINE already verified for this student+user → **noop** |
| `INVALID_INPUT` | Empty phone / validation 422 |
| `AUTHORIZATION_DENIED` | Reserved (ADR); not emitted by PB-00 legacy paths |
| `INTERNAL_ERROR` | Unexpected classifier/ops path; never stores exception text/PII |

Future pairing codes (`CODE_*`) are ADR-listed but **out of PB-00 scope**.

## Storage

Append-only table `parent_binding_attempts` (observation only — not auth truth).

Never stored: raw phone, student name, LINE user id, access/reply token, request body.

Flag: `PARENT_BINDING_OBSERVABILITY` → `config('parent_binding.observability_enabled')`.

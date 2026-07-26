# PB-04 — Relationship model（ParentIdentity + GuardianStudentRelationship）

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-00 |
| Blocks | PB-05, PB-06, PB-07 |
| ADR | https://github.com/jerry200176-png/AllTrue_System/pull/1434 |
| Status | backlog / blocked on PB-00 |

## Scope

- Migrations for `parent_identities`、`guardian_student_relationships`（indexes、uniqueness）。
- Services：create/revoke relationship； list guardians； session authorization reads relationship when flag on.
- State machine：`pending` / `active` / `read_only` / `suspended` / `revoked`（Founder）。
- Student status policy：
  - `paused` → keep normal active access
  - `graduated` / `inactive` → `read_only` for **365 days**
  - after 365 days → `suspended`；staff may extend（audited）
- **Revoke → immediately invalidate** ParentSessions for that parent+student.
- Campus-scoped relationships； UI contract：always show student + campus.
- Keep `student_line_bindings` as projection target（dual-write in PB-07）.

## Non-scope

- Pairing consume UI； OTP； OpenFGA； dropping StudentLineBinding； auto-merge by phone.

## Acceptance criteria

1. Active／read_only relationship required for portal access when flag enabled（dual-read fallback documented）.
2. Unique active/pending/read_only pair parent↔student enforced under concurrency.
3. Revoke → parent cannot dashboard/switch that student； sessions gone **immediately**.
4. Graduated/inactive transitions schedule read_only→suspended per policy.
5. Campus_id on relationship； staff list scoped； URL campus cannot bypass authZ.
6. No billing/leave files touched.

## Tests

- Feature：create/revoke/concurrent； cross-campus denial； multi-guardian； multi-child； session invalidation on revoke； read_only/suspended transitions.
- Migration tests on CI.

## Rollback

- Flag off → auth falls back to verified StudentLineBinding； new tables retained.

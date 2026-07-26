# PB-04 — Relationship model（ParentIdentity + GuardianStudentRelationship）

| Field | Value |
|-------|-------|
| Phase | 2 |
| Risk class | T3 |
| Dependencies | PB-00 |
| Blocks | PB-05, PB-06, PB-07 |

## Scope

- Migrations for `parent_identities`、`guardian_student_relationships`（indexes、uniqueness strategy）.
- Services：create/revoke relationship； list guardians； session authorization reads relationship when flag on.
- Keep `student_line_bindings` as projection target（dual-write hooks may land in PB-07）.
- State machine：pending/active/suspended/revoked.
- Revoke expires relevant `ParentSession` rows.

## Non-scope

- Pairing consume UI； public parent self-serve without credential； OpenFGA service； deleting StudentLineBinding table.

## Acceptance criteria

1. Active relationship required for portal access when flag enabled（with safe fallback during dual-read）.
2. Unique active pair parent↔student enforced under concurrency.
3. Revoke → parent cannot switch/dashboard that student； sessions gone.
4. Campus_id on relationship； staff list scoped.
5. No billing/leave files touched.

## Tests

- Feature：create/revoke/concurrent； cross-campus denial； multi-guardian； multi-child.
- Migration tests on SQLite/MySQL CI.

## Rollback

- Flag off → auth falls back to verified StudentLineBinding； new tables retained.

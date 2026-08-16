# Test director 285 cleanup (read-only first)

Allowlisted account: `user_id=285`, `LoginName=w3-director-test-20260813`, role `director`, `campus_id=9`.

1. Dispatch `Director 285 cleanup diagnose (read-only)`.
2. Artifact must show `IDENTITY_OK` and list association counts.
3. If any unexpected product-data ownership count is non-zero, stop.
4. Delete only through `DELETE /api/v1/directors/285` as the logged-in `super_admin`.
5. Verify the account cannot log in. Do not touch any other account.

The diagnose script is `SELECT` only. It does not delete the user.

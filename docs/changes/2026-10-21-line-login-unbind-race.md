## 2026-10-21 — fix(parent): LINE login and unbind serialize on the binding row (#3988)
<!-- release-notes: silent_ship=silent-2026-10-21-line-login-unbind-race -->
<!-- silent-reason: security hardening of an existing login path; no visible behavior change for linked users -->

An unbind that ran between the LINE login's binding lookup and its session
insert could not expire the new session, which stayed valid for 30 days while
the multi-guardian flags are off. LINE login now looks up access and creates
the session while holding a shared lock on the subject's
`student_line_bindings` rows, and every unbind path locks its binding row
first (`BindingService::revokeGuardianAccess()`). The two serialize at any
isolation level: an in-flight unbind makes login wait and then find no
binding; a login in progress makes the unbind wait and then expire the
session. Login still re-reads access after the insert (401, audit
`binding_revoked_during_login`). Two-connection tests cover REPEATABLE READ and
READ COMMITTED. Not covered: revoking a guardian link directly (no binding row)
under the multi-guardian dual-read flag.

## 2026-10-21 — fix(parent): LINE login re-checks access after creating the session (#3988)
<!-- release-notes: silent_ship=silent-2026-10-21-line-login-unbind-race -->
<!-- silent-reason: security hardening of an existing login path; no visible behavior change for linked users -->

An unbind that committed between the LINE login's binding lookup and its
session insert could not expire the new session, which stayed valid for 30
days while the multi-guardian flags are off. Login now re-reads access after
the insert and expires that one session (401, audit `binding_revoked_during_login`)
when the student is no longer linked. A deterministic regression test unbinds
from the session `creating` event. Residual: an unbind still in flight while
the re-check runs relies on InnoDB locking on `parent_sessions`; it is not
modelled by the test.

## 2026-10-22 — Unbind re-reads the binding inside the revoking transaction
<!-- release-notes: silent_ship=silent-2026-10-22-unbind-recheck-in-transaction -->
<!-- silent-reason: race hardening; unbinding behaves the same for staff and parents -->

`BindingService::deleteBinding()` read the binding row before its transaction and revoked guardian access inside it. If the same student and LINE pair was deleted and bound again in between, the stale request could revoke the new guardian link and sign its sessions out, then return 404 with the revocation committed. The row is now read by id under `lockForUpdate()` as the first step of the revoking transaction (lock order unchanged: `student_line_bindings` → `student_guardians` → `parent_sessions`, #3988); a missing row returns before any write. Regressions in `BindingUnbindRevokesGuardianAccessTest`. Rollback is a normal revert. Refs #3954.

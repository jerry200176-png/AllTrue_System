## 2026-10-18 — fix(billing): Serialize invoice voids with course money writers
<!-- release-notes: silent_ship=silent-2026-10-18-invoice-void-serialization -->
<!-- silent-reason: Internal transaction ordering fix; no UI, notification, accounting policy or new correction capability. -->

Normal and exception invoice voids now acquire the same sorted course locks as
direct invoice payments before locking and rechecking the invoice. Header and
item course references are included, so an in-flight course correction retains
ownership until its transaction completes. A waiting void rechecks the resulting
invoice state and cannot overwrite a superseded obligation.

Regression uses independent processes and a guarded synthetic MySQL database:
header-only, item-only and multiple-course obligations, for both void paths.
Existing payment/void rules, history and authorization remain in force.

Rollback: revert this code change; no migration or account repair is included.
Production deployment and affected-path verification remain separate gates.

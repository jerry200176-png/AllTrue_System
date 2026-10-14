## 2026-10-14 — Codex review quota throttle simulation
<!-- release-notes: silent_ship=silent-2026-10-14-codex-review-throttle -->
<!-- silent-reason: Internal simulation and tests only; no review requests, paid API calls or application changes -->
- Add an exact-head, two-round review controller with durable journal/readback fixtures and a read-only manual simulation workflow. Prepare eight-repo configuration, an asynchronous remote ledger adapter with blob-SHA conflict checks, and shared rate-limit backoff. Live transport/storage provisioning remain disabled pending the documented Founder/canary decisions.

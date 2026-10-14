## 2026-10-14 — Codex review quota throttle simulation
<!-- release-notes: silent_ship=silent-2026-10-14-codex-review-throttle -->
<!-- silent-reason: Internal simulation and tests only; no review requests, paid API calls or application changes -->
- Add the disabled shared eight-repo controller/runner, authenticated nonce-bound comment transport, external-review budget reconciliation, durable cooldown and remote CAS adapter. Local fixtures pass; real state/CAS/canary validation and activation remain gated by the single documented decision packet.

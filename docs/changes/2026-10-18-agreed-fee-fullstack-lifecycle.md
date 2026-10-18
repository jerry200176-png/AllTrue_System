## 2026-10-18 — test(billing): Guard isolated browser-test database lifecycle
<!-- release-notes: silent_ship=silent-2026-10-18-fee-fullstack-lifecycle -->
<!-- silent-reason: Test-support bootstrap/migration only; no application routes, UI, workflow or production feature configuration. -->

Extract the reviewed #4253 database guard and same-process Migrator entry.
Reuse the existing synthetic-schema allowlist and minimal TCP configuration;
require APP_ENV=testing and reject alternate DSNs, config-cache redirects,
sockets, PDO options, split connections and loaded-target drift before providers.

Subprocess provider canaries verify refusals happen before provider registration.
A real migration/replay verifies the same process runs exactly one migration
against the wrapper-owned ephemeral loopback schema. Runtime key, flag and
origins remain process-local test settings. No runnable browser workflow,
router/seed, production access, account mutation or feature activation is added.

Rollback: revert these test helpers. Browser and real-case acceptance remain pending.

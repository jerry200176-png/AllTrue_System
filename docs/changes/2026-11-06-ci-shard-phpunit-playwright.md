## 2026-11-06 — ci: shard PHPUnit (3) and UI-foundation Playwright (2) (#4945 follow-up)
<!-- release-notes: silent_ship=silent-2026-11-06-ci-shard -->
<!-- silent-reason: CI 工作流程調整，使用者看不到任何變化。 -->
- Required checks `PHPUnit Feature & Unit Tests` and `Vite Frontend Build` are now aggregator jobs over sharded jobs; coverage merged with phpunit/phpcov; gates unchanged.

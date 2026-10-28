## 2026-10-19 — ci(deploy): supplemental release-train when tip advances past window deploy
<!-- release-notes: silent_ship=silent-2026-10-19-release-train-tip-ahead-kick -->
<!-- silent-reason: Deploy orchestration only; Founder production-activation unchanged; no product UX. -->

Allow Start release train to dispatch a supplemental train in the same clock window when main tip has advanced past every window-serving train head (compare API). Pending-train debounce and Founder production-activation are unchanged.

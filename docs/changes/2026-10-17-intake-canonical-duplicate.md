## 2026-10-17 — fix(intake): preserve trusted canonical issue consolidation
<!-- release-notes: silent_ship=silent-2026-10-17-intake-canonical-duplicate -->
<!-- silent-reason: GitHub intake bookkeeping only; no product UI or in-app lifecycle write changes. -->
- Honor validated owner/bot consolidation metadata before reopening an intake issue, preserving normal canonical reopening and race deduplication. Untrusted/cross-repository/ambiguous targets fail closed.

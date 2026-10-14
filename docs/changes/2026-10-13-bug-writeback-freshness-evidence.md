## 2026-10-13 — ops: opaque freshness evidence for bug detail reads
<!-- release-notes: silent_ship=silent-2026-10-13-bug-writeback-freshness-evidence -->
<!-- silent-reason: Existing permitted detail reads gain opaque metadata; no staff UI or write path changes. -->
- Bug detail responses include a timestamp-bound HMAC over the report and ordered related records for later stale-request checks. This preparation adds no endpoint, migration or report write.

## 2026-11-08 — fix(bug-reports): Enforce attachment admission under the report row lock
<!-- release-notes: silent_ship=silent-2026-11-08-bug-attachment-cap -->
<!-- silent-reason: Internal concurrency/integrity correction preserves the existing five-file limit, empty-report retry and response fields; no staff workflow or copy change. -->
- Stage each request's uploads in its own UUID directory, then use a short Laravel transaction and authoritative report row lock to recheck the aggregate attachment count before accepting metadata.
- Preserve empty-report retry semantics; a stale worker admits no additional batch. Rollback or lost admission cleans only that request's exact paths; partial storage failures retain successful evidence and report existing attachment errors.
- Add real-model MySQL two-worker regression with actual FileLock expiry and row-lock waiting, plus storage/metadata failure, retry and accepted-file preservation cases. This fixes the per-report cap, not hard report-creation idempotency; no schema, throttle, auth, notification, API-field or lock-TTL change.
- A killed process can still leave unreferenced staging files; this change does not claim crash-proof filesystem cleanup or introduce a cleanup job. Synthetic evidence is not a production incident or deployment claim.

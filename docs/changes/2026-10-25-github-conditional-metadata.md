## 2026-10-25 — prepare GitHub conditional metadata transport (#4385)
<!-- release-notes: silent_ship=silent-2026-10-25-github-conditional-metadata -->
<!-- silent-reason: offline-tested engineering metadata helper; no product behavior change -->

An authenticated GitHub metadata GET can return a weak ETag followed by a 304
with the equivalent strong tag; legacy gh reports that 304 as exit1. Prepare a
GET-only transport and conditional-page helper for this protocol. Other errors,
unknown metadata, cache provenance gaps and budget exhaustion still fail closed.

The helper is not called by activation or deployment. CI tests use sanitized
protocol headers and synthetic payloads. A guarded manual PR CI run also reads
its own /rate_limit once and reports numeric core quota, using unchanged token
permissions; it does not request Actions jobs or production data. No runtime
cache, artifact trust or three-minute readiness is implied. Actual Actions
runner results remain pending; the recorded Engineering gh caller is separate.
#4385 and protected activation remain held.

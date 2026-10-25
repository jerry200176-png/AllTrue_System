## 2026-10-25 — prepare GitHub conditional metadata transport (#4385)
<!-- release-notes: silent_ship=silent-2026-10-25-github-conditional-metadata -->
<!-- silent-reason: offline-tested engineering metadata helper; no product behavior change -->

An authenticated GitHub metadata GET can return a weak ETag followed by a 304
with the equivalent strong tag; legacy gh reports that 304 as exit1. Prepare a
GET-only transport and conditional-page helper for this protocol. Other errors,
unknown metadata, cache provenance gaps and budget exhaustion still fail closed.

The helper is not called by activation or deployment. CI runs synthetic tests
using sanitized protocol headers, without new API calls, permissions or secrets.
No runtime cache, artifact trust, caller quota or three-minute readiness is implied.
Actual Actions runner behavior remains unmeasured; the recorded Engineering gh
caller is separate. #4385 and protected activation remain held.

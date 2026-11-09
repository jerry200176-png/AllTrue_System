## 2026-11-09 — test: block known materializing class-session reads in smoke
<!-- release-notes: silent_ship=silent-2026-11-09-smoke-stateful-read-guard -->
<!-- silent-reason: smoke safety correction only; no staff-facing UI or production API changes -->
- Block direct app-origin GET/HEAD requests to the known class-session list/projection materializers, including query, trailing-slash and encoded path spellings. Backend materialization behavior is unchanged.
- Synthetic classifier and native-browser counters verify this boundary while existing telemetry, ordinary reads and one-shot export behavior remain covered.
- This does not establish zero database effects for all allowed GETs or native read redirects. Prior cancellation/XLSX results prove those assertions only; materializer-dependent acceptance remains UNVERIFIED.

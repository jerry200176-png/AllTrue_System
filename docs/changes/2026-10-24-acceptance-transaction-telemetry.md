## 2026-10-24 — Production acceptance answers transaction telemetry locally
<!-- release-notes: silent_ship=silent-2026-10-24-acceptance-transaction-telemetry -->
<!-- silent-reason: acceptance fixture only; no product behavior change -->

The calendar acceptance opts into a local 204 response for a single well-formed Sentry transaction envelope at the exact official HTTPS numeric-project ingest endpoint. Error, mixed, unknown, malformed, oversized, and all other writes remain blocked and fail the existing read-only assertion. Coverage records only finite envelope classifications and counts; request bodies and event content are never recorded.

## 2026-10-25 — fix(ops): preserve browser diagnostics in encrypted bug details
<!-- release-notes: silent_ship=silent-2026-10-25-bug-dump-browser-diagnostics -->
<!-- silent-reason: Internal read-only diagnostic transport; no staff-facing behavior change. -->
- Retain stored failure references, build identity and interaction breadcrumbs in the encrypted bug detail artifact. Public summaries and logs keep their metadata-only projection. Refs #4496.

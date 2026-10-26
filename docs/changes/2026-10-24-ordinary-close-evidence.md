## 2026-10-24 — feat(billing): typed ordinary-close evidence primitive
<!-- release-notes: silent_ship=silent-2026-10-24-ordinary-close-evidence -->
<!-- silent-reason: internal writer and tests only; existing close behavior and staff-facing surfaces are unchanged -->

- Adds a strict, HMAC-bound writer/reader for complete ordinary-close evidence in the existing security audit JSON envelope. The generic security audit metadata sanitizer is unchanged.
- This internal primitive is not called by existing close paths; product behavior is unchanged.

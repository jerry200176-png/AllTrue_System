## 2026-10-27 — Make director campus-save acceptance deterministic

Hold the synthetic post-save list reload so the existing success and loading live regions coexist at both mobile and desktop widths. Assert the exact success message within its matching status, then release the reload and confirm the loading status disappears. Existing single-write, target, payload, dismissal and keyboard checks remain intact; no runtime or permission behavior changes.

Include this page and its spec in the existing UI-foundation path filter so their changes execute the already-configured browser suite in hosted CI.

<!-- release-notes: silent_ship=silent-2026-10-27-director-save-status-acceptance -->
<!-- silent-reason: Acceptance-test fixture and locator only; no staff-visible runtime change. -->

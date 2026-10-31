## 2026-10-31 — feat(ui): form fields nudge once when an error appears (#4697 item 3)
<!-- release-notes: silent_ship=silent-2026-10-31-atfield-error-nudge -->
<!-- silent-reason: small motion polish on existing error states; wording and behaviour unchanged -->
- `AtField` gives one gentle 240 ms horizontal nudge when it enters the error state, and the error message fades in, so a wrong value (for example a per-lesson price typed into 總費用) is noticed. CSS only, using the `--ds-motion-*` tokens. Off under `prefers-reduced-motion`. Idea from the Founder-shared Motion Library (錯誤震動提示); our own code.

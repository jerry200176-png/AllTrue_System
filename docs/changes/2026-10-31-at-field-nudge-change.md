## 2026-10-31 — feat(ui): AtField error nudge replays when the error text changes (#4697 item 3)
<!-- release-notes: silent_ship=silent-2026-10-31-at-field-nudge-change -->
<!-- silent-reason: motion polish on existing field errors; wording and validation unchanged -->
- #4698 nudged a field only when its error first appeared. `AtField` now also replays the gentle shake and the message slide-in when the error text changes (for example 「太大」 to 「太小」), and never on an unrelated re-render. Off under `prefers-reduced-motion`. Our own CSS on `--ds-motion-*`.

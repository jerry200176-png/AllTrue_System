## 2026-10-31 — feat(ui): copy buttons confirm with a check and 「已複製」 (#4697 item 2)
<!-- release-notes: silent_ship=silent-2026-10-31-copy-feedback -->
<!-- silent-reason: small motion polish on existing copy buttons; wording and behaviour unchanged -->
- New shared `AtCopyButton`: after copying, the icon becomes a check and the label 「已複製」 for 1.5 s (announced via `aria-live="polite"`). Used by the LINE and Telegram webhook URL copy buttons. Off under `prefers-reduced-motion`. Our own code.

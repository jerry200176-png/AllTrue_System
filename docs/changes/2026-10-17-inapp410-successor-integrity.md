## 2026-10-17 — fix(scheduling): tighten renewal-shift integrity checks (#4210)
<!-- release-notes: silent_ship=silent-2026-10-17-successor-integrity -->
<!-- silent-reason: Renewal-shift apply remains default-off; this prepares safeguards and synthetic regression coverage without activation. -->
- Bind both contract boundaries to preview tokens, preserve exact lesson duration, and select the next valid contract slot by date and time.
- Refuse historical attendance/evaluation records, occurrence anchors, settlement locks, invalid intervals and cross-day tails without writes; keep effective-teacher conflict messages private.
- Reuse ordinary leave markers and eligibility, revalidate the current successor set under locks, and retry the complete atomic transaction on transient deadlocks.

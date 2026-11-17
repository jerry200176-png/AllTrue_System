## 2026-11-17 — fix(inapp): daily auto-close takes the oldest 20 instead of refusing a pile
<!-- release-notes: silent_ship=silent-2026-11-17-inapp-auto-close-oldest-20 -->
<!-- silent-reason: 只改每日自動結案的批次規則，產品畫面不變。 -->
- `bugs:close-stale-resolved --auto` refused the whole run when more than 20 resolved reports were eligible, so about 44 waited with nobody reviewing them. It now closes the oldest 20 by resolve time, and the rest wait for the next daily run (Founder 2026-11-17). The other conditions are unchanged: 14 days, resolved queue only, recheck under lock, and the same public reply. The POP reporter-timeout policy is unchanged.

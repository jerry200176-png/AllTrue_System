## 2026-11-04 — fix(ui): 舊資料標已收的課顯示「補登收款」、收款紀錄按鈕對齊、核取方塊不再撐滿（in-app #490/#491/#492）
<!-- release-notes: staff_update=staff-2026-11-04-ledger-ui-legacy-backfill -->
- #490: a legacy-paid row (`legacy_paid_flag_only`) shows 補登收款 for directors and opens the ledger with `initialAction='legacy-backfill'`, which calls `openEntry(course,'legacy-backfill')` when `needsLegacyChoice` holds; other roles keep 在這裡看帳務.
- #491: global `input[type=checkbox|radio]` keeps `width:auto` so labels beside them no longer wrap one character per line.
- #492: inside `.ledger-receipt`, 查看收據, 展開上課日 and 備註紀錄 share margin-top 0 and 13px text.

## 2026-11-05 — feat(billing): 登記收款改右側面板＋儲存並看下一筆未繳（v3 PR3，#4659）
<!-- release-notes: staff_update=staff-2026-11-05-payment-sheet -->
- Frontend only. 登記收款 from a 帳單 row or its quick view opens the existing right-side payment sheet (`sheet-entry`, #4905) for that row's contract. New 儲存並看下一筆未繳 opens the next owing row in the current list order and filter, or closes with a toast. Also: 核對 opens the student drawer again; quick view hides 登記收款 on 不收了 / 要開帳單 rows, says 還沒開帳單, shows busy state and a 上課日 expand marker. No API, money-rule or DB change.

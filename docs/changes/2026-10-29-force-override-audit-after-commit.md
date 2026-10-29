## 2026-10-29 — fix(attendance): 強制建立課程的審計紀錄改為建立成功後才寫（#4001）
<!-- release-notes: silent_ship=silent-2026-10-29-force-override-audit-after-commit -->
<!-- silent-reason: 只改伺服器端審計日誌的寫入時機（建立成功後才記錄 enrollment_force_override），畫面、回應與資料都沒有改變 -->
- 強制建立（建立試聽／下一期續報／獨立課程）後來被其他檢查拒絕時，不再留下「已強制建立」的審計紀錄；只有真正建立成功才記錄。回滾＝還原這個 PR。

## 2026-09-29 — fix(ux): one source per dashboard count, loading placeholders, invoiced-only outstanding label
<!-- release-notes: silent_ship=silent-2026-09-29-counts-loading-ux -->
- 主任總覽的待審評量、未讀通知、繳費提醒、今日課務進度改與側欄／各頁同一口徑；載入中的列表標頭顯示「—」與骨架而非 0／空狀態；帳務中心摘要改稱「已開帳單未結清」並另列未開帳單筆數。純前端顯示調整，無教職員新操作。

## 2026-09-29 — fix(billing): prepare missing monthly invoice review
<!-- release-notes: silent_ship=silent-2026-09-29-monthly-billing-review -->
- 準備帳務中心「月結待核對」與堂次費率試算，明示缺少帳單服務期間和合約越界；系統登錄收款與實際入帳分開核對。尚未部署，未拆分正式個案或建立九月帳單。

## 2026-09-29 — fix(billing): prepare correction into an existing monthly contract
<!-- release-notes: silent_ship=silent-2026-09-29-existing-monthly-correction -->
- 延伸尚未啟用的受控更正：沿用既有新期合約與未繳帳单，保留取消／待上紀錄，已沖銷收款不重複沖銷。正式執行仍須最新核對清單及既有安全核准，尚未修復正式個案。


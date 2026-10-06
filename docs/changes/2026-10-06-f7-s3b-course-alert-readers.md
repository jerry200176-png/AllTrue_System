## 2026-10-06 — refactor(billing): F7 S3b course list + tuition alert amounts from the one resolver
<!-- release-notes: silent_ship=silent-2026-10-06-f7-s3b -->
<!-- silent-reason: 畫面欄位不變；部分繳現在照實顯示，與帳務中心一致 -->
- 課程列表的繳費狀態與學費提醒的未繳金額改由同一個帳務 resolver 計算（部分繳顯示為部分繳、免費課顯示為免費）。

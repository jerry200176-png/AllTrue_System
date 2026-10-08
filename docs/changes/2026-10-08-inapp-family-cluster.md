## 2026-10-08 — feat(ops): 自動分類 in-app 回報類別並提醒「同類型一起修」（Founder 2A，只提醒、不自動關單）
<!-- release-notes: silent_ship=silent-2026-10-08-inapp-family-cluster -->
<!-- silent-reason: 只在內部 GitHub issue 加類別標籤與提醒留言，教職員看到的畫面與版本更新內容完全不變。 -->
- `backend/config/bug_families.php`（唯一設定檔）用 page_key＋關鍵字把回報分到 billing／attendance／calendar／learning-records／parent-portal／ui；`bugs:auto-intake --candidates` 只多輸出類別名稱，回報文字不離開正式站。
- `bug-auto-intake.yml` 對每張新 issue 加 `area:<類別>` 標籤，並在同類別最新的開啟中 issue 留一則連結提醒（有標記、可重跑、只信 owner／機器人建立的 issue）；失敗只警告，不擋收單。

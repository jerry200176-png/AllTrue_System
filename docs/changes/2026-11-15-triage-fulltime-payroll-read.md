## 2026-11-15 — feat(triage): AI 唯讀金鑰可讀正職薪資要件報表（只有編號與數字）
<!-- release-notes: silent_ship=silent-2026-11-15-triage-fulltime-payroll-read -->
<!-- silent-reason: 只給 AI 唯讀金鑰用的後端 API，教職員畫面沒有任何差異。 -->
- AI 分診唯讀金鑰多一支只讀 API：`GET /api/v1/triage/fulltime-payroll?month=YYYY-MM&branch_id=N`，回傳「正職薪資要件」頁同一份計算結果（一個分校、一個月）。姓名與所有人工輸入的文字都會被拿掉，只留編號、數字、日期與狀態代碼。
- 目的：讓 AI 能直接拿真實數字核對薪資計算（例如每週 16 段為什麼沒達標），不用請人把頁面念出來，也不用連資料庫。
- 只能讀；每次讀取都有稽核紀錄；金鑰必須是超級管理員建立的且未過期。每分鐘最多 20 次。要立刻停用：撤銷金鑰（個人資料 → 安全性）；或設 `TRIAGE_FULLTIME_PAYROLL_ENABLED=false`，下一次部署後回 404。

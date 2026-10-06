## 2026-10-06 — fix(auth): wait for authorized campuses before director pages load (#3506)
<!-- release-notes: silent_ship=silent-2026-10-06-director-campus-readiness -->
<!-- silent-reason: 登入時分校權限載入的安全修正，尚待受保護合併與正式部署驗證；不提前公告已修復 -->
- 主任登入後，分校資料頁會先確認帳號授權的分校才載入；授權清單暫時無法取得時顯示重試入口。後端分校權限未更動，尚待正式部署驗證。
- 測試修正：主任總覽 e2e 模擬資料補上 /campuses 回應（此為測試資料，使用者無感）。

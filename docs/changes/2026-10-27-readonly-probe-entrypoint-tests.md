# 正式診斷程式入口的唯讀與錯誤輸出測試

<!-- release-notes: silent_ship=silent-2026-10-27-readonly-probe-entrypoint-tests -->
<!-- silent-reason: 僅新增 CI 整合測試，正式診斷程式與產品行為不變。 -->

Refs #4448。透過既有 Symfony Process 執行未改寫的共用方案診斷入口，使用測試資料庫驗證 JSON 輸出、真正的 READ ONLY 拒寫、失敗訊息去敏及成功／失敗後的 rollback。沿用現有查詢、欄位白名單與 CI，不新增正式測試旗標或診斷權限。

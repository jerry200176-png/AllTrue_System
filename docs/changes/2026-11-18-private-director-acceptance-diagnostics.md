# 主任路徑驗收的私人錯誤診斷
<!-- release-notes: silent_ship=silent-2026-11-18-private-director-acceptance-diagnostics -->

驗收失敗只輸出固定步驟代碼與登入 HTTP 數字狀態；不輸出原始例外、帳密、URL、DOM 或私人附件。既有操作、斷言、匯出範圍、唯讀阻擋、禁止私人截圖及清理規則維持。合成瀏覽器失敗驗證兩種私人設定仍拒絕洩漏內容；沒有追加正式登入、匯出或重試。

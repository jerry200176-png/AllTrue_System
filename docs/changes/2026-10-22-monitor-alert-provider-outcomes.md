## 2026-10-22 — 監控通知依服務接收結果回報
<!-- release-notes: silent_ship=silent-2026-10-22-monitor-alert-provider-outcomes -->
<!-- silent-reason: 僅內部監控接收結果與測試，不改使用者操作、通知收件者或產品畫面。 -->

- Telegram／LINE 告警只在 HTTP200 且官方成功回應契約成立時顯示「已確認服務接收」；這不代表使用者已讀或端到端送達。
- 拒收、限流、timeout、傳輸或回應錯誤會回傳非零；加上5秒連線／15秒總等待上限，不自動重送 POST，避免重複通知。
- 憑證與內容透過私密暫存檔傳給 curl，清理回應；錯誤紀錄只包含 provider 與狀態，不輸出 token、原始回應或訊息。
- 沿用既有通知管道、收件者及 Telegram 優先順序，不變更正式設定、不執行真實通知。
- 復發情境以 stub transport 驗證並納入既有 CI。Refs #4370；尚待正常審查、受保護合併及獨立部署證據。

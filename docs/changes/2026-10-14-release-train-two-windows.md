## 2026-10-14 — ci(deploy): release train twice a day, started by merges when GitHub drops the cron
<!-- release-notes: silent_ship=silent-2026-10-14-release-train-two-windows -->
<!-- silent-reason: 只改部署發車的時間與觸發方式，教職員畫面與版本更新內容不變。 -->
- 發車班表由台北 08:17–22:17 每小時一班改為每天 12:17、18:17 兩班（Founder 2026-10-14「好，照你建議做 go」：批次核准，一天集中 1–2 班）。GitHub 會吞掉或延遲排程，所以 merge 後的 deploy run 也會在該班還沒發車時補發一班；每班仍要 Founder 核准一次，全部 R0–R2 的改動照舊隨 CI 自動上線。

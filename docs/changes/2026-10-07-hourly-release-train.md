## 2026-10-07 — ci(deploy): release train runs hourly at :17, 08:17-22:17 Taipei
<!-- release-notes: silent_ship=silent-2026-10-07-hourly-release-train -->
<!-- silent-reason: 只改部署發車的排程頻率，教職員畫面與版本更新內容不變。 -->
- 發車班表由每天 07:30／12:30 改為台北 08:17–22:17 每小時一班（避開整點，22:47 再補一次）（Founder 3A 核准）；每班仍只提供 main 最新且 CI 綠燈的 commit，同樣要 Founder 核准一次。下一班即是重試，因此移除原本每班的 15 分鐘重試排程（只保留最後一班的 22:47 重試）；已有一班在等核准時，新一班照舊讓路。

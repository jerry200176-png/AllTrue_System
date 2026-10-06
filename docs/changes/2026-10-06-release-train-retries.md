## 2026-10-06 — ci(deploy): release train retries within each window
<!-- release-notes: silent_ship=silent-2026-10-06-release-train-retries -->
<!-- silent-reason: 只改部署排程的重試，教職員畫面與版本更新內容不變。 -->
- 發車班表在 07:30／12:30 兩個時段內每 15 分鐘重試（GitHub 會掉排程、main 最新版可能還在跑 CI）；已有一班在等核准時，重試會讓路，不會取消正在等核准的那班。

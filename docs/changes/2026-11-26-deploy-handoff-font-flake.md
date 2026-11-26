## 2026-11-26 — 合併後不再因字型抓不到或重複觸發測試而沒上線
<!-- release-notes: silent_ship=silent-2026-11-26-deploy-handoff-font-flake -->
<!-- silent-reason: CI and deploy hand-off reliability only; no product behaviour changes. -->

- 瀏覽器測試不再把 Google 字型抓不到當成失敗（那不是系統的問題）。
- 合併後的補救流程會先等主線測試出現再判斷，不會再多觸發一次測試、把原本那次取消掉（#5659）。

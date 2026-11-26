## 2026-11-26 — 合併後補救流程：卡住的情況救得回來
<!-- release-notes: silent_ship=silent-2026-11-26-deploy-handoff-gaps -->
<!-- silent-reason: CI and deploy hand-off reliability only; no product behaviour changes. -->

- 主線測試已經綠、但之後沒有任何上線動作時，補救流程會直接交棒上線，不再把較早留下的「空的上線紀錄」當成已上線（#5659）。
- 等主線測試的時間從 10 分鐘拉長到 25 分鐘（主線測試實際要 10–21 分鐘）。

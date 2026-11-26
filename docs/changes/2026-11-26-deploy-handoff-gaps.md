## 2026-11-26 — 合併後補救流程：三個漏洞補上
<!-- release-notes: silent_ship=silent-2026-11-26-deploy-handoff-gaps -->
<!-- silent-reason: CI and deploy hand-off reliability only; no product behaviour changes. -->

- 測試被取消或失敗後留下的「空的上線紀錄」不再被當成已上線，手動重跑補救流程就能救回來。
- 等主線測試的時間從 10 分鐘拉長到 25 分鐘（主線測試實際要 10–21 分鐘）。
- 補救流程改成排隊、不互相取消，避免觸發了測試卻沒人接著交棒上線（#5659）。

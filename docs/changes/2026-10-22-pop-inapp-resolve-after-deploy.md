## 2026-10-22 — feat(pop): in-app 回報「上線後結案」改走 Pi 本機 POP 操作（ADR-POP-015 b）
<!-- release-notes: silent_ship=silent-2026-10-22-pop-inapp-resolve-after-deploy -->
<!-- silent-reason: internal operations path only; no staff or parent screen changes, and the standing policy stays off until the Founder enables it -->

- 新增 POP 操作 `inapp-report-resolve-after-deploy`：一筆 `triaged`／`in_progress` 回報在同一個交易裡貼公開回覆並標為已解決，只由 Pi 本機 `pop:execute-approved` 執行，不走 runner SSH。常設政策升 v2 涵蓋此操作；旗標與請求端憑證（ADR-POP-015 第二部分）就緒前只走 super_admin 人工核准。不新增資料表或欄位。
- 證據缺一不可（人工核准也不能略過）：修正 commit 在 Pi 的部署版本裡、`deploy.yml`「Deploy to Production」成功且就是現行版本、健康 200、issue SourceRef 對得上、回覆含 issue 連結與兩句驗收語且無個資或外部連結、15 分鐘審閱證明在列鎖下仍相符。

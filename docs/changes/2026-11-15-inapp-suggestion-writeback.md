## 2026-11-15 — feat(support): 既有 POP 接通受限建議／體驗改善回寫
<!-- release-notes: silent_ship=silent-2026-11-15-inapp-suggestion-writeback -->
<!-- silent-reason: existing feedback UI and reply contract; internal scoped operator workflow and governance, with delivery notices sent on the individual original report -->

- Founder 2026-11-15 明確授權 `suggestion`／`ux_friction` 的收件與部署後通知。沿用四個既有操作、requester purpose、校區限制及 Pi-local executor；不新增金鑰、端點、schema、服務或排程，不授權業務資料、帳務、排課、帳號修復或其他 disposition。
- `inapp-report-triage` 的兩個新 kind 只用既有 `closeAsLogged` 與固定收件文案，記錄不代表功能完成，保留 engineering-required 判定。舊六參數 bug 請求只在新輸入入口補固定 `bug`，再照原 exact-key／hash／idempotency 檢查；顯式 null 與其他 kind 硬拒絕，既有 DB request 不補欄或重算 hash。
- `inapp-report-resolve-after-deploy` 的 logged 分支保留全部部署／版本／關聯／部署後 smoke／health／核讀證據，重用既有上線通知服務。限定同 issue／最新 close cycle、每 cycle 通知一次並保留 closed 狀態；拒絕冒充回報者、未回覆補充、重開後落入 bug resolve 或換版本重送。鎖內重核、交易回滾及公開留言／append-only evidence 的驗證均有回歸。
- Catalog 16／policy 5 使舊版本 pin 失效；待執行的舊請求需要重新核讀建單／核准，不能自動續跑。回滾程式不會撤回已公開留言、狀態或 append-only evidence。正式啟用仍走原生閘門；此變更本身不宣稱任何建議已交付或回報者已驗收。

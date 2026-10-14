## 2026-10-13 — ci(ui-smoke): serialize production smoke logins (#3767)
<!-- release-notes: silent_ship=silent-2026-10-13-ui-smoke-serialize-accounts -->
<!-- silent-reason: 只改 CI 排程（UI smoke 一次只跑一個），沒有任何產品或畫面改變。 -->
- `ui-smoke.yml` 拆成「偵測」與「Playwright」兩個 job；真正用共用正式站 smoke 帳號登入的 job 加上全 repo 共用的 `ui-smoke-production-accounts` concurrency，兩個 run 不會同時用同一組帳號。未動 UI 的 PR 不排隊。未新增任何正式帳號。Refs #3767

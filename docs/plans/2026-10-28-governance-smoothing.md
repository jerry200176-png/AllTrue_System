# 2026-10-28 — 規範順暢化（Founder：「現有的很多架構可以用你覺得最順暢的方式去重構我們目前的規範」）

## 目標
減少「安全上沒幫助、只是讓事情變慢」的步驟。保留真正擋事故的線。

## 不動的線（照 Founder 原本的要求）
- 正式 DB migration、真實 production 資料／帳號修改、憑證：仍要 Founder 明確核准。
- 不 force push、不 `--admin`、不直接 push main、不在 Pi 寫東西、不把個資放進 GitHub。
- 每個 PR 仍要 CI 綠、最終 head 的 Codex 審查、討論串清空。

## 今天實際卡住的地方（證據）與改法

| # | 卡點 | 證據（本 session） | 改法 | 誰動手 |
|---|---|---|---|---|
| 1 | 合併一個一個排，每合一個，其他 PR 全部重跑 ~30 分鐘 CI | 9 個排隊 PR、一天只合得了幾個 | 關掉 main 規則的「必須跟最新 main 同步」（strict）。land-queue 的「4 個一批、先合在一起整批測過才合」早就寫好，只差這個開關；批次測的是合在一起的結果，安全性不變 | Founder 核准；agent 用 API 改 ruleset `main-protection` |
| 2 | 700 行上限把測試也算進去，逼 PR 拆開 | #4200：777 行，其中 ~500 行是測試 | 700 行只算**正式程式**（`backend/app`、`routes`、`database`、`frontend/src` 非測試）；測試、文件另設總上限 1500 | agent（presubmit PR） |
| 3 | 規則散在 10 多份文件，互相重複、常常過期 | CLAUDE.md／AGENTS.md／.cursorrules／PORTFOLIO_AGENT_CONTRACT／FOUNDER_STANDING_DECISIONS／RISK_BASED_MERGE_POLICY… | 寫一頁「一件事怎麼從回報走到上線」當唯一入口；其他文件只留連結，不再重述 | agent（文件 PR，分批） |
| 4 | 修好的 in-app 回報不能自動標「已解決」 | #396 試跑全部通過，但開關 `POP_INAPP_STANDING_POLICY` 是關的 | 打開開關（下一次部署順便帶 `pop_inapp_standing_policy=on`） | Founder 核准 + 按部署 |
| 5 | 把一行搬進函式也要貼 `intentional-revert` 標籤 | #4559 | CHECK 0d 先看「刪掉的那行內容是否還在檔案別處」，還在就不算刪 | agent（presubmit PR） |

## 順序
1 和 4 等 Founder 一句話。2、5 先做（小、立即省時間）。3 最後做，分 2–3 個文件 PR。

## 驗證
- 2、5：presubmit 自己的測試 + 拿 #4200 原本的 diff 重算一次，應該通過。
- 1：下一輪 land-queue log 出現 `batching on`，一批 4 個一起合。
- 4：第一筆 in-app 自動結案留下 policy 核准紀錄。

## 停下來的點
- 任何改到部署閘門（deploy.yml、autonomy_gate.py）的事，照現行 R3 走審查。

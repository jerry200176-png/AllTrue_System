# In-app 回報根治 v2（2026-10-03）

接續 v1（2026-10-02，W1–W5）。v1 修的是「程式會一直出同類 bug」。
v2 修的是「回報一直卡著、關不掉」。

## 1. 現況證據

- Queue dump run `37091903724`（2026-10-03 03:03Z）：open 32（全部 `triaged`）、resolved 108、closed 233。
- v1 全部已合併。正式站還在 `44ab1b3`（10-02 03:43Z）。分開看：
  - 等部署才生效（runtime）：#3433（#295）、#3435（F10 回報帶線索）、#3436（F8 佔位）、#3447（月結開課日）、#3449（#319）。
  - 合併即生效（文件／CI／測試，不用部署）：#3432、#3434（F9 CI）、#3438、#3444、#3451。
- Reporter-timeout dry-run `37092145562`：108 筆 resolved 只有 3 筆（266、277、366）可以結案。
  其餘 105 筆沒有「請重新測試」留言或沒有上線證據 → 機器規則永遠不會讓它們結案。
- 回報者在已結案回報下留言，`BugReportService::addComment` 只寫留言，**不會重開**。

## 2. 32 筆 open 分群

| 卡在哪 | 筆數 | in-app | 下一步 |
|---|---|---|---|
| 已修，等部署後回覆 | 2 | 295（#3433）、319（#3449） | 部署 → Phase-C（附「請重新測試」） |
| 已修相關，已請回報者重試 | 6 | 338、347、363（F8 #3424/#3436）、364（#3428）、300、322（Founder 10-02：維持待回報） | 等回報者 → 套 F11 時鐘 |
| 已問回報者，沒回 | 15 | 369、365、361、360、359、356、357、355、354、349、346、342、334、333、327 | 等回報者 → 套 F11 時鐘 |
| 截圖 agent 讀不到 | 5 | 340、341、343、344、345 | Founder／主任看圖，一句話補線索 |
| 帳務單一權威（F7）| 2 | 302、303（369、349、354、346 也相關） | F7 S0b → S1（R3） |
| 產品功能 | 2 | 299（主任兼老師單一帳號）、290（行事曆編課 dogfood） | 各自已有 issue／proposal，排在 F7 後 |

26／32 不是卡在程式，是卡在「等人」。

## 3. 新復發家族 F11：回報流程沒有出口

**根因**：狀態機裡「等回報者」和「已修待確認」兩個狀態沒有時鐘、沒有出口。
回覆也不會把單子拉回來。所以單子只進不出，看板越積越多，真正新的問題被淹沒。

**大公司做法**
- Zendesk：`Pending`（等客戶）＋自動化：N 天沒回 → 自動 `Solved`；`Solved` 4 天後自動 `Closed`。客戶回信會自動把單子拉回 `Open`。
- Jira Service Management：`Waiting for customer` 狀態＋「閒置自動結案」規則；客戶留言自動轉回處理中。
- GitHub `actions/stale`：先標記、給寬限期、再關；任何新留言就取消。
- Sentry User Feedback：回報當下自動附上下文，減少「等補資料」（= v1 W2／F10，已合併未上線）。

**原則**：每個「等某人」的狀態都要有時鐘和出口；對方一回覆就自動回到處理中。

### F11-a 等回報者時鐘（新 code，R3／T3，**PLAN_REQUIRED：Founder GO 才合併**）
- 新的自動結案＋新狀態轉換 → 依 `INAPP_PRODUCT_LOOP_EXECUTION_POLICY_V1.md` 屬 `PLAN_REQUIRED`；本節即 Decision Packet。
- 合併前同一 PR 要更新正式規則：`docs/governance/EVIDENCE_CONTRACT.md` 加入 triaged 等回報者 timeout（目前只允許 resolved timeout）。
- 門檻分開：resolved 沿用 workflow `days`（預設 7）；等回報者固定 `AWAITING_REPORTER_TIMEOUT_DAYS = 14`，不吃 `days`。
- 範圍：`triaged`，最後一則是公開員工提問，回報者之後沒有留言。
- 第 14 天：`closed_by_timeout`，公開留言「超過兩週沒收到回覆，先結案。直接在這裡回覆就會重開。」留言與關單同一個 transaction，重跑不會重複留言。
- 沿用現有 `bugs:close-stale-resolved` 的 dry-run／逐筆 review／apply 流程與 workflow，不另做 scheduler。
- 回報者在 `closed_by_timeout` 單子下留言 → 自動重開，寫 status log：等回報者關掉的回 `triaged`；已修待確認（resolved timeout）關掉的回 `in_progress`（= 修了還壞，依 Evidence Contract）。
- 測試：14 天邊界、回報者有回就排除、內部備註不算提問、留言重開。

### F11-b 舊 resolved 清倉（一次性，Founder 決定）
- 105 筆舊單沒有重測提問。選項：
  - A（推薦）：只挑**有上線證據**（`[resolution_evidence]` 或 append-only production evidence）、resolved 超過 30 天、resolve 後回報者沒留言的單，發一則重測提問，7 天後走現有 timeout。沒有上線證據的單不發「已修好」，另列清單逐筆補證據或維持 resolved。
  - B：維持現狀，只處理新單。

### F11-c 截圖
- 5 筆截圖 agent 讀不到。推薦：Founder 或該校主任看圖，在 in-app 單內部備註（不是 GitHub）寫「哪個頁面／哪個按鈕／看到什麼」。GitHub issue 只寫去識別化描述，不寫學生姓名、日期等可識別資料。不做新的 agent 讀圖通道（避免新增個資外流面）。

## 4. 其他根治線（延續 v1）

| 線 | 現況 | 下一步 | 風險 |
|---|---|---|---|
| F7 帳務單一權威（Stripe 帳本：總數由明細算） | 決策 10-02 已定；S0a 已合併 | S0b fixtures（本輪，R1）→ S0 正式站去識別化差異報表（唯讀 probe）→ S1 resolver 擴充 | S1 起每步 R3，各自 GO |
| #319 老師端慢 | N+1 已修（#3449） | `/class-sessions` 三個整表 aggregate：先唯讀 `EXPLAIN` probe，再決定 index 或子查詢 | index = migration，R3 |

## 5. 執行順序與停點

1. 本 plan 合併（docs only，R0／T0）。
2. F7 S0b（R1，背景）。
3. **停：Founder 部署核准**（一次包含 v1 全部 + #3449）。
4. 部署後：驗 `deployment.json` SHA → 295、319 走 Phase-C（附重測提問）。
5. F11-a（#3452）PR 綠 → **停，Founder GO** → 合併 → 部署 → 第一次 dry-run。
6. F11-b、F11-c 等 Founder 一次回覆。
7. F7 S1：PR 綠後停，問 GO。

## 6. 驗證

- 部署：`deployment.json.backend_sha` = 目標 SHA；health OK。
- F11-a：PHPUnit 邊界測試；把重開邏輯拿掉測試要 fail；上線後 dry-run 名單逐筆人工看過才 apply。
- 重開路徑的正式站證據：第一筆被重開的單，用 `bug-detail-dump` 確認 status log 有 `reopened_by_reporter_reply`；沒觀測到前標 production user-path = NO。
- 結束前重抓 queue dump，分開「本輪處理／新進」。

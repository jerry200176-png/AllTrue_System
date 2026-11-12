# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-12 13:30 UTC（正式站 0868e44c；正式驗收自該版起紅燈，修正 #5239 待合併；下一班 release train 等核准）
Source SHA: 99653994（main；正式 runtime 0868e44c916a5edb0c4b2235abf3afbf1ffdd3a9，2026-11-12T12:49Z 部署）
Owner: coordinator（每個 milestone、PR merge、deploy 後更新；完成的項目直接刪除）

**規則**：每次接續都先核對 GitHub 與正式 manifest；未滿 7 天也可能過期。超過 7 天一律 STALE。新 SHA 勝舊 SHA；
與記憶（MemPalace、個人 auto-memory）衝突時，git / GitHub 為準。只放指標，不複製內文。

## 進行中（live state）

**Takeover order:**
1. Read this table.
2. Run `gh pr list --state open --label queue` and `gh run list --workflow deploy.yml --limit 3`.
3. Read the issue or PR in the "去哪看" column.

The trackers hold the full detail: flow is #4953 and UI is #4989. This file only points to them.

| 項目 | 狀態 | 下一步（誰） | 去哪看 |
|---|---|---|---|
| Release | 正式站 `0868e44c`（11-12 12:49Z，R1 自動上線）；main `99653994`。Release train run 43222126701（目標 99653994）等 `production-activation` 核准。**正式驗收自 0868e44c 起連續失敗**（一個斷言沒跟上 #5234 的短標籤）；修正 #5239 已審、等 CI。依 D8 驗收恢復綠燈前不放行下一班。 | #5239 合併 → 驗收綠 → 才請 Founder 核准該 run | #5239、run 43222126701 |
| in-app 佇列（11-12 13:00 UTC 讀取） | `new` 3：538、539、540（issue #5229／#5230／#5231 已開，in-app 側未動）；`triaged` 7：514、521、522、524、527、530、536；`resolved` 93。 | 驗收綠後：用機器分診把 538–540 轉 `triaged`，538／539 走機器結案（修正 #5234 已在正式站） | triage API |
| in-app 機器流程 | 已可用：新 POP 機器金鑰（id 4）已存入雲端環境。起草＋試算後由 Pi 排程依常設政策核准並執行。520／526／531／532／533／537 已於 11-12 結案並讀回。`inapp-report-triage`（#5233）已在正式站。機器客戶端不能送「進度回覆」（不在預先核准的三種寫入內）。 | 結案需部署後有一次通過的驗收；release train 不會自己觸發驗收，要等 main 下一次 CI | ADR-POP-015、#5117 |
| in-app 530 (#5151) | 唯讀查證完成：拒絕正確（時段被同套裝另一份合約佔住）。真正問題是該合約 11-14 之後的固定課沒有產生，套裝成員計數／堂數池帳本／已上課紀錄不一致。 | 追哪個計數擋住排課產生 → 修 → 回覆 | #5151 |
| in-app 540 (#5231) | 唯讀查證完成：資料重現不出來（合約 4011 伺服器端不是歷史）。屬畫面狀態問題；未證實線索是登入逾時後退回備用清單。 | 前端重現 → 修 | #5231 |
| in-app 522 (#5113) | 收據自助更正：PR 1（#5194）與 PR 2（#5220，連結表）已上線。Founder 已定：同校區可跨學生、需填原因、每週彙整給總部；主任額度先以 0 上線。 | PR 3a（預覽）→ 3b（套用）→ 4（前端）→ 5；額度數字等 Founder | #5113 |
| in-app 538／539 (#5229／#5230) | #5234 已上線：說明面板佔滿整列、摘要格線依卡片數；繳費標籤用短標籤（完整句在無障礙標籤）。 | 驗收綠後機器分診＋結案 | #5234 |
| in-app 536 (#5198) | 唯讀查證完成：確認為 bug（課堂歸屬家族）。合約 4216 開始日晚於其名下一堂已上的課；需修一筆開始日，已列入 #5162。 | 準備修復包（Founder 核准後執行） | #5198、#5162 |
| 預防計畫 (#4991) | 538 筆回報的根因家族分析已貼。第 1 項：14 個 `rootfam:*` 標籤已上線、405 張 issue 已回填（#5228）。第 2 項：每晚金額一致性檢查已合併（#5227，只觀察；開告警前要先讓告警分支把未完成／出錯視為未通過）。 | 第 3–5 項排隊中（拒絕時說出被什麼擋、每個操作要有反向操作、畫面用語統一） | #4991 |
| in-app 521 (#5111／#5116) | 程式與 POP 策略已上線；正式帳務未修（2 筆待連結，第 3 筆需人工決定）。 | Founder 在 POP 執行一次（C2） | #5162 |
| in-app 514／524／527 資料 (#5124) | 唯讀診斷與設計完成；Founder 已定：多的一堂往後推、不做純日期對調、移除殘留排課。合約鏈修復 PR 1（#5222，試算）已上線；PR 2（#5236，執行／驗證／還原）複審抓到「逾時會留下半套變更」已修、等 CI 重審；其餘四項修正在分支 `fix/pop-chain-rebalance-review-p2`、未開 PR。學生 30 的舊修復確認未執行，需 Founder 金額決定。 | #5236 重審合併 → 開第二張 → 兩張都合併後才準備修復包；執行前主任先釋出 48580 | #5124、#5236、#5162 |
| 正式驗收訊號 | 紅燈（見 Release）。已知缺口：改到畫面文字／樣式時 PR 階段不會跑正式站驗收；release train 部署後不會自動觸發驗收。 | #5239；#5201 第 27–29 列與後續 | #5201、#5177、#5178、#5183 |
| 流程／文檔 (#5015、#4953、#5201) | 已合併：Founder 常設決定 2026-11-12（四）「選項由 Agent 照推薦決定，只有重大產品決策才問」（#5223）；分類器保護自己與 agent／技能檔（#5210、#5217）；CI 以探測方式跑全部腳本測試（#5219）；審查閘門在 Codex 沒跑成時沿用標記（#5216）；清除帶人名代號的一次性 workflow（#5221）；偶發的受限編號測試（#5238）。進行中：#5237（非正式站 e2e 不算控制面）。 | 省用量做法見 #5201：先收尾不開新線、機械核對用指令、實作者預設中階模型、推完就交回 | #5201、#5015 |
| 學生個人歷程／學習檔案 | #4970 step 3a 進行中（同上一版）。 | 同上一版 | #4928、#4970 |
| UI 改版 (#4989) | 已上線：#5181、#5186、#5189、#5195、#5207、#5211、#5214、#5218、#5232。舊元件清單 74。 | 下一頁排隊中（先收尾現有 PR）；有行內錯誤的欄位不放原生 `required`／`min`；改文字／樣式要搜正式站驗收測試 | #4989 |
| 資料修復總表 | C12 五張凍結 issue 已做程式面重驗（都還不能關）；其餘各項待重掃。 | 見 #5162 留言 | #5162 |
| PR 清潔工 | 排程任務「AllTrue PR janitor」每日 01:30／13:30 UTC。 | — | scheduled task |

**等 Founder（2026-11-12 13:30 UTC，一次列完）：**
1. 核准 release train run 43222126701——等正式驗收恢復綠燈後。
2. in-app 521：在 POP 執行一次帳務修復（C2）。
3. #5113 收據自助更正：主任可處理的金額上限與天數（程式先以 0 上線）。
4. 學生 30（#5162）：8 月那 5 堂課與已付帳單 1601 掛在哪份合約。
5. 重大產品決策（建議都是「是」）：主任 7 天內自行復原非金額操作；依校區切換課堂身分模型；在 Pi 存彙總使用計數；開放固定句型的自動進度回覆。
6. #5015 的 D1（native merge queue）、D2（ruleset 加 `review/final-head`）。

**Founder-only：**
- production Approve（release train 的 run 頁面）
- POP 的 dry-run 確認與 approve
- production 資料
- migration
- 憑證
- DNS：stable staging CNAME 改天再做

**Staging：**
- 主機 `alltrue.daan.lifenet.com.tw`（rootless Docker），只放合成資料。
- quick tunnel 網址存在主機 `~/alltrue-stage/state/public-url`。
- 密碼和 cookie 只能 pipe，絕不印出來。

**11/7 學到的：**
- 子 agent 會改錯 PR 的 body：#5058 被 517 的內文覆蓋過，已從編輯歷史還原。改 body 前先核對 PR 編號。
- `Release-Impact: none` 會擋 CHECK 4B，只能填 `user-visible`、`internal` 或 `no-user-facing-note`。
- review-gate 會被「Codex 在 head 上有未解 finding」擋住，就算有 R3 marker 也一樣：看 PR 上 Codex sticky comment 的 `has_unresolved_findings`，修掉或回覆並 resolve thread 後重跑 `@codex review`。
- autoland 的 PR 卡住時先看 `review/final-head` check 的標題，不要只看 land-queue。
- 本機磁碟：過期副本直接刪，真要留的放 Google Drive；C: 的 WSL vhdx 要 Founder 跑桌面的 `shrink-wsl.ps1` 才會縮。
- Claude 歷史額度經驗不代表 Codex 模型／額度；以目前 client 可用能力為準。交接只給結論、證據與未解項，不貼原始日誌。

本輪六筆上線回覆（501／519／520／521／523／526）由 Phase A 42607912735／follow-up 42608099856 完成並逐筆讀回（留言 1429–1434）；未代按 reporter verification。13:25 UTC combined queue 到空頁：new 0、triaged 9、in_progress 0、resolved 97；97 筆回報者確認佇列獨立，並非全部已驗收。528 另由 Phase A 42611554089 留公開留言 1435，無重複收件回覆。

## Deploy / POP 常見坑
- **Release train 停手**：已有 run 等 approval 時，先讀該 run 的目標 SHA 與現行 SOP；不要重複 dispatch、盲目 force-cancel 或代按 Founder approval。`.github/workflows/deploy.yml`
- **POP in-app 每日上限**：每個 operation 每 Asia/Taipei 日 20 份 report（以 report 計，不是 request）；超過時 dry-run 會過但不會執行，00:00 Taipei 後用新的 request_id 後綴重送。`docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`
- **issueOwnsReport / SourceRef**：`github_issue_url` 必須是 open 且非 duplicate 的 issue，且 owner 或 github-actions[bot] 的內文／留言含 `SourceRef … alltrue:bug_report:<id>`；auto-intake 鏡像被關成 duplicate 會失敗 → 改用 canonical issue。`backend/app/Operations/Inapp/InappReportResolveEvidence.php`
- **fix 要連回 issue**：fix PR 標題或內文需有 `#<issue>` 或 `in-app #<bug>`（可編輯已 merge 的 PR body 補上）。
- **smoke-after-deploy**：smoke run 必須在 deploy job 的 `completed_at` 之後才算數。
- 完整流程：`docs/changes/2026-10-22-pop-inapp-resolve-after-deploy.md`、`docs/sop/DELIVERY_LIFECYCLE.md`、`docs/CHAT_BUG_SYSTEM.md`。

## 找東西
問題 → 文件的對照在 `docs/INDEX.md` 的「Takeover recall」。

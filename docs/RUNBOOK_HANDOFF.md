# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-13 14:00 UTC（正式站後端 9518645e；main f714326c；下一班 release train 待觸發／核准）
Source SHA: f714326c（main；正式 runtime 9518645ecbb9208fd1688249cd2e34c2def3899d，2026-11-13T07:54Z 部署）
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
| Release | 正式站 `9518645e`（11-13 07:54Z）。main `f714326c` 多出：#5258、#5264（跑版修正）、#5259（甘特圖）。這些要等下一班 release train。 | 觸發／核准下一班；上線後做 541–543 的結案回覆 | Actions → deploy.yml |
| in-app 佇列（11-13 14:00 UTC） | `new` 1：544（建議類，機器分診不支援，見 #4991）。`triaged` 11：514、521、522、524、527、530、536、540、541、542、543。 | 各列見下 | 分診 API |
| in-app 541／542／543 (#5260／#5261／#5262) | 版面跑版；修正 #5264 已合併，含必過的版面檢查 `frontend/e2e/ui-foundation-layout-guard.spec.js`。543 是 #5253 改版造成的回歸。 | 上線後機器結案 | #5264 |
| in-app 544 (#5263) | 字級「稍大」與全站設計模板。Founder 已定方向（見下一列）。 | #5265 合併 → 上線 → 回覆 | #5263、#4989 |
| **UI 改版 (#4989)：做法已改** | Founder 2026-11-13：全站（主任、老師、家長）只用一套版型，以今日工作台為準。固定模板 `docs/design/PAGE_TEMPLATE.md`＋共用元件 `frontend/src/components/ui/page/`（PR #5265，CI 中）。帳務中心用 A（表格＋勾選欄）；手機每列改點整列；字級加「稍大」。每張 UI PR：照模板、載入設計 skills、附 1536／375 × 三種字級的真瀏覽器前後截圖、加入版面檢查。已改版：#5253 當月學收、#5257 正職薪資要件（都還沒套新模板）。已移除：學習檢測（#5249）、題庫（#5254）。 | #5265 合併後照 #4989 的順序逐頁套模板：當月學收 → 帳務中心 → 課程查找 → …→ 老師 → 家長。原型程式與對照圖在協調 session 的暫存區，不在 repo；重做時照模板文件即可 | #4989 的 2026-11-13 決定留言 |
| 學生學習計畫甘特圖 (#4970) | step 1、2（#5250 教材範本庫）、3a、4a（#5255 API）、4b（#5259 甘特圖）已合併。 | step 5（評量表勾「這堂上完的單元」）、step 6（成績趨勢、KPI）、step 3 其餘分頁；API 只回下一次段考，圖上想標全部段考要先擴 API | #4970 |
| in-app 522 (#5113) | PR 3a（唯讀預覽，#5266）：兩個金額 P1 已修並經 R3 複審確認；最後一個紅燈是測試 fixture，已修，CI 中。功能開關與主任額度預設關閉。 | #5266 綠 → 貼標記 → autoland；接著 3b（實際執行：交易、連結列、稽核；PR 內文「left for 3b」兩點必做）、4（前端）、5 | #5113、#5266 |
| in-app 536 (#5198) | Founder 定案 A：改派到開始日較晚的合約時允許、標成例外並顯示，不動開始日、不修資料。 | 實作：改派路徑設例外旗標＋一次性回填＋畫面標示 → 回覆結案 | #5198 |
| in-app 540 (#5231) | 根因未確認（前端四條路徑已排除）。#5258 已合併：資料不足不再當歷史；課程查找的回報自動附分類依據。 | 等下一筆同類回報的附帶資料；不結案 | #5231 |
| in-app 530 (#5151) | 修正 #5243 已上線。合約 2909 當時 0 堂未來課。排程任務 11-14 10:00（台北）自動檢查夜間是否產生課堂，是的話機器結案。 | 見排程任務 | #5151 |
| in-app 521 (#5111／#5116) | 兩筆舊沖銷已在 POP 執行並驗證（11-13）。剩付款 940／帳單 960 有歧義。 | 準備人工審過的更正包 | #5162 |
| in-app 514／524／527 (#5124) | #5246 已上線。11-13 重新試算：未動到合約的缺口已是警告；剩 `future_reservations_exceed_remaining`（合約 4806：8 購／2 上／7 預約）。 | 主任在系統釋出合約 4806 的一堂未來課（或續約）→ 重新試算 → Founder 在 POP 核准 | #5124 |
| in-app 機器流程 | 分診、上線後結案可用；固定句型進度回覆 `inapp-report-progress-reply` 已合併（#5247）。缺口：分診只支援 bug。 | 讓建議類也能走機器分診 | #4991 |
| 預防計畫 (#4991) | 第 1、2 項完成；本輪新增必過的版面檢查。 | 第 3–5 項 | #4991 |
| 正式驗收訊號 | 綠燈。`Visual regression (advisory)` 幾乎每張 PR 都紅，沒人看。 | 修基準或移除，見 #5201 | #5201 |
| 流程／文檔 (#5015、#4953、#5201) | 11-13 檢討已貼 #5201，規則補進 `docs/agents/IMPLEMENTER_BRIEF.md`。 | 見 #5201 未完成列 | #5201 |
| 資料修復總表 | 見 #5162。合約 812／課堂 7211 的帳本缺口仍在。 | — | #5162 |
| PR 清潔工 | 排程任務每日 01:30／13:30 UTC。 | — | scheduled task |

**等 Founder（2026-11-13 14:00 UTC）：**
1. 核准下一班 release train（上線 #5258、#5264、#5259 等）。
2. #5124：請主任釋出合約 4806 的一堂未來課後，在 POP 核准重新試算的結果。
3. #5113：把主任額度的實際數字填進環境設定（未填前為 0）。
4. `docs/governance/FOUNDER_STANDING_DECISIONS.md` 補上 2026-11-13「固定句型自動進度回覆」一條（agent 寫入會被權限系統擋）。
5. #5015 的 D1、D2。

**Founder 已定（2026-11-13，紀錄在各 issue）：** 全站單一設計模板（#4989）；536 用 A（#5198）；共用堂數池先到先用（#5151）；學生 30 不修、主任 7 天內可自行復原非金額操作（#5162）；自動進度回覆、彙總使用計數、依校區切換課堂身分模型（#4991）；沒人用的功能移除不改版。

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

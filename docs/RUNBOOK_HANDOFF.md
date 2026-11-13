# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-13 20:20 UTC（正式站 f714326c；release train 43495858027 等 Founder 核准，目標 2e35a335）
Source SHA: main（以 GitHub 為準）
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
| Release | 正式站 `f714326c`。11-13 19:21Z 的 train（`51404976`）**自動回滾**：功能 smoke 全過，版本號比對把同一 commit 的 9 碼與 8 碼判成不同。#5281 已修（前綴比對）並合併。新 train 43495858027（`2e35a335`：#5266、#5273、#5275、#5278、#5279、#5281）等核准。#5280、#5282 在下一班。 | Founder 核准 → 核對 manifest＋驗收 → 536 結案、評量表單元勾選的瀏覽器檢查 | Actions → 43495858027 |
| in-app 佇列（11-13 20:00 UTC） | `new`：544（建議類，機器分診不支援，#4991）。`triaged`：514、521、522、524、527、530、536、540。541–543 已機器結案。 | 各列見下 | 分診 API |
| in-app 536 (#5198) | 修正 #5268（合約期間外的課標示）已合併，等上線。 | 上線後機器結案 | #5198 |
| in-app 544 (#5263) | 「稍大」字級與全站模板已合併（#5265 起）。 | 上線後在 GitHub 收尾（建議類） | #5263 |
| **UI 改版 (#4989)** | 模板 `docs/design/PAGE_TEMPLATE.md`＋`components/ui/page/`。課程查找 slice 1–3 已合併（#5274、#5279、#5280）；`cn()` 現在保留 `tw:text-tpl-*`。slice 4（歷史卡＋#5280 延後的三點）實作中。 | slice 4 → 複審（複審要載設計 skill、看前後截圖）→ slice 5… | #4989 最新留言 |
| 學習計畫 (#4970) | step 5（評量表勾單元，#5273）已合併未上線。 | 上線後瀏覽器檢查；step 6；step 3 其餘分頁 | #4970 |
| 讀書計畫 (#5269) | PR 1 資料表＋API（#5278）已合併；PR 2 老師週檢視（#5282）在合併佇列。 | 複製上週、套用同班、進度圓環、家長唯讀；延後項見 #5269 留言 | #5269 |
| in-app 522 (#5113) | 3a 唯讀預覽（#5266）已合併，功能開關與主任額度預設關閉。 | 3b（見 #5113 must-do），4、5 | #5113 |
| in-app 540 (#5231) | 根因未確認；診斷資料已加。 | 等下一筆同類回報 | #5231 |
| in-app 530 (#5151) | 排程任務 11-14 10:00（台北）自動檢查。 | 見排程任務 | #5151 |
| in-app 521 (#5111／#5116) | 付款 940／帳單 960 仍有歧義。 | 準備人工審過的更正包 | #5162 |
| in-app 514／524／527 (#5124) | 剩合約 4806 未來預約超過剩餘堂數。 | 主任釋出一堂 → 重新試算 → Founder 在 POP 核准 | #5124 |
| DB 時鐘 (#5276) | 夜間檢查已改用 App 日期（#5275）。摘要與排課生成仍用資料庫時鐘。 | 需正式 DB 時區查詢結果 | #5276 |
| 流程／文檔 | 今日教訓已寫進 `docs/agents/IMPLEMENTER_BRIEF.md`（#5277）。還沒寫入：改共用元件要拍所有使用頁的前後截圖；介面 PR 的複審要載設計 skill。 | 下一個文件 PR 補上 | #5201 |
| 資料修復總表 | 合約 812／課堂 7211 的帳本缺口仍在。 | — | #5162 |

**等 Founder（2026-11-13 20:20 UTC）：**
1. 核准 release train 43495858027。
2. #5124：主任釋出合約 4806 的一堂未來課後，在 POP 核准重新試算。
3. #5113：把主任額度的實際數字填進環境設定。
4. #5276：在正式 DB 跑 `SELECT @@global.time_zone, @@session.time_zone, NOW();`，把結果貼到 issue。
5. `FOUNDER_STANDING_DECISIONS.md` 補 2026-11-13「固定句型自動進度回覆」（agent 寫入會被擋）。

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

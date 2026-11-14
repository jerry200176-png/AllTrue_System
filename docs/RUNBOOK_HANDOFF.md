# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-14 02:40 UTC（正式站 2e35a335；release train 43542291420 等 Founder 核准，目標 8dd27266 之後的 main）
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
| Release | 正式站 `2e35a335`（11-14 00:56Z，train 43495858027；驗收 43542308274 綠）。上一班 11-13 的回滾原因（版本號 9 碼對 8 碼）已由 #5281 修好。下一班 train 43542291420 等核准：課程查找 slice 3–7a、讀書計畫週檢視／複製／進度條／家長檢視、撞名檢查 #5287 等。 | Founder 核准 → 核對 manifest＋驗收 | Actions → 43542291420 |
| in-app 佇列（11-14 02:00 UTC） | 536、530 已機器結案（#5198、#5151 已關）。`triaged`：514、521、522、524、527、540。`new`：544（建議類，機器流程不支援，需 App 內回覆）。 | 各列見下 | 分診 API |
| **UI 改版 (#4989)** | 課程查找 slice 1–7a 已合併；7b（#5293，金額／堂數對話框）在佇列；slice 8（清 CSS、移出 ui-legacy-pages、更多選單 768 溢出、IME Esc）實作中。複審規則：介面 PR 的最終複審要載設計 skill、看前後截圖；搬對話框要逐一檢查「從對話框再開的視窗」在上層。 | slice 8 → 下一頁（學生管理等，依 #4989 順序） | #4989 最新留言 |
| 讀書計畫 (#5269) | PR1–5 全部合併：#5278 資料表＋API、#5282 老師週檢視、#5285 複製上週／套用同班、#5289 進度條、#5291 家長唯讀。家長看不到老師備註（Founder 11-14 照建議）。 | 後續：時間軸 API 送 attended＋課堂狀態（#5269 留言） | #5269 |
| 學習計畫 (#4970) | step 5（#5273）已上線（2e35a335）。 | 瀏覽器實測評量表單元勾選；step 6 | #4970 |
| in-app 522 (#5113) | 3a 唯讀預覽（#5266）已上線，開關預設關。 | 3b、4、5 | #5113 |
| in-app 540 (#5231) | 根因未確認；診斷資料已上線。 | 等下一筆同類回報 | #5231 |
| in-app 521 | 付款 940／帳單 960 仍有歧義。 | 準備人工審過的更正包 | #5162 |
| in-app 514／524／527 (#5124) | 剩合約 4806 未來預約超過剩餘堂數。 | 主任釋出一堂 → 重新試算 → Founder 在 POP 核准 | #5124 |
| Dependabot #5288 | Laravel 12.71、psr7、serializable-closure；複審過、CI 綠；land queue 不收機器人 PR。 | Founder 手動合併 | #5288 |
| DB 時鐘 (#5276) | 夜間檢查已改用 App 日期；摘要與排課生成仍用資料庫時鐘。 | 需正式 DB 時區查詢結果 | #5276 |
| 流程 | #5287：CI 在跑後端測試前擋「測試函式跟框架撞名」（nikic/php-parser）。今日教訓見 #5201。agent 工作目錄會佔滿磁碟：定期清掉已推上 GitHub 且乾淨的 worktree。 | — | #5201 |
| 資料修復總表 | 合約 812／課堂 7211 的帳本缺口仍在。 | — | #5162 |

**等 Founder（2026-11-14 02:40 UTC）：**
1. 核准 release train 43542291420。
2. 合併 Dependabot #5288（可選）。
3. #5124：主任釋出合約 4806 的一堂未來課後，在 POP 核准重新試算。
4. #5113：把主任額度實際數字填進環境設定。
5. #5276：正式 DB 跑 `SELECT @@global.time_zone, @@session.time_zone, NOW();`。
6. `FOUNDER_STANDING_DECISIONS.md` 補 2026-11-13「固定句型自動進度回覆」（agent 寫入會被擋）。

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

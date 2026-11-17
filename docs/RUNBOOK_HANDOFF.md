# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-16 19:45 UTC（正式站 582c0a2b3；release train 43927944617 等 Founder 核准，目標 a3f42ea7）
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
| Release | 正式站 `582c0a2b3`（11-16 16:13Z）。train 43927944617 等核准，目標 `a3f42ea7`（6 個 commit，含 in-app 563 修正 #5405）；main 之後的 commit 搭下一班。 | Founder 核准 → 核對 version.json＋驗收 | Actions → 43927944617 |
| in-app 佇列（11-16 19:40Z） | new 4（544、545、546、553）、triaged 16、in_progress 1（530 回報者重開，#5151）。**負責：Codex**（#4070 11-16 交接）。Claude 已上線、等 App 結案：550 #5361、551 #5362、553 #5364。 | Codex 依 SOP 收件／結案 | 分診 API、#4070 |
| 帳務中心重整 | 計畫 11-17 核准（月對帳表；4 項照推薦）。後端 #5414 審查中；前端在 Claude 本機分支，#5414 合併後開 PR。新店舊 Excel 對帳表暫停（Founder 11-17：主任先休息）。 | Claude | #5414、#5407 |
| UI 改版 (#4989) | 依 tracker 順序進行。 | 見 tracker | #4989 最新留言 |
| 學習／讀書計畫 | — | 見 tracker | #4970、#5269 |
| 其餘 in-app 個案 | 514／524／527 (#5124)、521 (#5162)、522 (#5113)、540 (#5231)。 | 各 issue 最新留言 | 各 issue |
| Dependabot | #5417（composer）、#5418（npm）檢查紅；minimatch 安全警告 #80（中）兩個 PR 都沒修到。 | 規則見 MERGE_SOP §Dependabot PRs | #5417、#5418 |
| DB 時鐘 (#5276) | 摘要與排課生成仍用資料庫時鐘。 | 需正式 DB 時區查詢結果 | #5276 |
| 資料保存期限 (#889) | 全庫只有 RFID 待處理刷卡有 30 天清除；其他都沒有期限。 | 等 Founder 定保存表 | #889 |
| GitHub 整理 | 11-17 刪 87 條「PR 已關閉」的分支（可在 PR 頁還原）；剩 45 條沒有 PR 的分支未動（可能是進行中工作）。 | — | — |
| 資料修復總表 | 合約 812／課堂 7211 的帳本缺口仍在。 | — | #5162 |

**等 Founder：** 核准 release train 43927944617；其餘看 `needs-founder` 標籤（`gh issue list --label needs-founder`）。

**Founder 已定：** 2026-11-13：全站單一設計模板（#4989）；536 用 A（#5198）；共用堂數池先到先用（#5151）；學生 30 不修、主任 7 天內可自行復原非金額操作（#5162）；自動進度回覆、彙總使用計數、依校區切換課堂身分模型（#4991）；沒人用的功能移除不改版。2026-11-17：帳務計畫 4 項照推薦；in-app 546 教材匯入先不做（#5329）。

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

## Deploy / POP 常見坑
- **Release train 停手**：已有 run 等 approval 時，先讀該 run 的目標 SHA 與現行 SOP；不要重複 dispatch、盲目 force-cancel 或代按 Founder approval。`.github/workflows/deploy.yml`
- **POP in-app 每日上限**：每個 operation 每 Asia/Taipei 日 20 份 report（以 report 計，不是 request）；超過時 dry-run 會過但不會執行，00:00 Taipei 後用新的 request_id 後綴重送。`docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`
- **issueOwnsReport / SourceRef**：`github_issue_url` 必須是 open 且非 duplicate 的 issue，且 owner 或 github-actions[bot] 的內文／留言含 `SourceRef … alltrue:bug_report:<id>`；auto-intake 鏡像被關成 duplicate 會失敗 → 改用 canonical issue。`backend/app/Operations/Inapp/InappReportResolveEvidence.php`
- **fix 要連回 issue**：fix PR 標題或內文需有 `#<issue>` 或 `in-app #<bug>`（可編輯已 merge 的 PR body 補上）。
- **smoke-after-deploy**：smoke run 必須在 deploy job 的 `completed_at` 之後才算數。
- 完整流程：`docs/changes/2026-10-22-pop-inapp-resolve-after-deploy.md`、`docs/sop/DELIVERY_LIFECYCLE.md`、`docs/CHAT_BUG_SYSTEM.md`。

## 找東西
問題 → 文件的對照在 `docs/INDEX.md` 的「Takeover recall」。

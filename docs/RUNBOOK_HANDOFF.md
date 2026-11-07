# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-07 17:30 Asia/Taipei
Source SHA: 09af4fd11 (origin/main at write time)
Owner: coordinator（每個 milestone、PR merge、deploy 後更新；完成的項目直接刪除）

**規則**：本檔超過 7 天 → 視為 STALE，先對 GitHub（issue / PR / `gh run list`）驗證再用；新 SHA 勝舊 SHA；
與記憶（MemPalace、個人 auto-memory）衝突時，git / GitHub 為準。只放指標，不複製內文。

## 進行中（live state）

**Takeover order:**
1. Read this table.
2. Run `gh pr list --state open --label queue` and `gh run list --workflow deploy.yml --limit 3`.
3. Read the issue or PR in the "去哪看" column.

The trackers hold the full detail: flow is #4953 and UI is #4989. This file only points to them.

| 項目 | 狀態 | 下一步（誰） | 去哪看 |
|---|---|---|---|
| in-app 516 (#5056)：11/7 的調課顯示錯 | 根因已確認：`ensure-projected` 不接受跨日的調課目的地，月曆又顯示一張幽靈 20:00 預排。修正在 PR #5064（R2），已回覆回報者兩次。 | CI 綠燈加 Codex 乾淨後進 `queue`，接著發 release train（Founder Approve），上線後跑 resolve POP | PR #5064；case `inapp_516_projected_slot`（#5058） |
| in-app 514 (#5043 merged)：帳單期間標籤 | 主修正已 merge。影響數為 0（case `inapp_514_coverage_impact`）。 | 舊帳單 re-key 的 POP 在 PR #5046：R3 已通過並 `queue`。merge 加部署之後，POP 要先 dry-run，再由 **Founder 按**。 | PR #5046 |
| in-app 517 (#5057)：學校名稱合併 | PR1 #5060 已 merge（名稱建議加主任合併建議，唯讀）。 | PR2：合併與復原走 `security_audit_events`，180 天內可復原，並改名 `school_exam_periods`。R3。從新的 main 開始。 | issue #5057 |
| in-app 501 (#4918)：課程管理改版 | Part 1 #5063 已 merge（抽屜、月曆）。 | Part 2：`CourseEditForm` 精簡標籤、新 `<Button>` 加 `type="button"`、axe 掃全部分頁、從 `scripts/ui-legacy-pages.json` 移除。R3。 | #4918、#4989 |
| UI 改版 waves | 登入、家長首頁、主任首頁（#5027）已 merge，待下一班 release 上線。Founder 11/7 已核可外觀，之後每波給一次 before/after，不再逐頁核准。 | 依序做 App shell → 老師首頁 → 出缺勤 → 學生管理 → 學習評量。同一時間最多 2 個頁面 PR。必用 `.claude/skills/` 的 ui-ux-pro-max、impeccable 等工具。 | #4989；`docs/design/UI_FOUNDATION_MIGRATION_SEQUENCING.md` |
| #5038 per-PR 預覽 | 已 merge。proxy 只放行 GET/HEAD（其他回 405）；staging gate cookie 11/7 已換過。 | quick-tunnel 網址換了，就要更新 repo variable `PREVIEW_API_ORIGIN`（見 `docs/ops/DAAN_STAGING_V1.md`） | PR #5038 |
| #4995 property tests | R3 FAIL：只檢查邊界，6/8 mutant 活著。 | 套用 reviewer 留言裡的斷言，並加閏日 seed | PR #4995 留言 |
| AI 維運閉環 (#5070) gap 6 | gap 6 AI 用量／Actions 週報：`actions-cost-report` 加 agent commit 數（Claude-Session trailer，只計數）。PR #5079。 | 等 R3 批次 review；合併後在 #5070 勾選 | #5070 |
| #5054 staging pull-deploy | 已 queue | 等 land-queue | PR #5054 |
| Flow W7–W11 | 計畫在 #4953 的 2026-11-07 留言 | 每項一個 PR | #4953 |
| N+1 告警 | 9 張重複的 Sentry 單已併成 #5061 | 照事件量由高到低修，每個修正都要附 query-count 測試 | #5061 |

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
- 同一個 Claude 帳號 5 小時額度會用完。agent 一律回報 3 行以內，用單一 CI wait loop，並發不超過 3 個。

## Deploy / POP 常見坑
- **Release train 停手**：有舊 deploy run 卡在「waiting for approval」→ train 回報「another deploy run is already waiting」。先 force-cancel 舊 run，再重新 dispatch train。`.github/workflows/deploy.yml`
- **POP in-app 每日上限**：每個 operation 每 Asia/Taipei 日 20 份 report（以 report 計，不是 request）；超過時 dry-run 會過但不會執行，00:00 Taipei 後用新的 request_id 後綴重送。`docs/pop/adr/ADR-POP-015-standing-policy-inapp-lifecycle.md`
- **issueOwnsReport / SourceRef**：`github_issue_url` 必須是 open 且非 duplicate 的 issue，且 owner 或 github-actions[bot] 的內文／留言含 `SourceRef … alltrue:bug_report:<id>`；auto-intake 鏡像被關成 duplicate 會失敗 → 改用 canonical issue。`backend/app/Operations/Inapp/InappReportResolveEvidence.php`
- **fix 要連回 issue**：fix PR 標題或內文需有 `#<issue>` 或 `in-app #<bug>`（可編輯已 merge 的 PR body 補上）。
- **smoke-after-deploy**：smoke run 必須在 deploy job 的 `completed_at` 之後才算數。
- 完整流程：`docs/changes/2026-10-22-pop-inapp-resolve-after-deploy.md`、`docs/sop/DELIVERY_LIFECYCLE.md`、`docs/CHAT_BUG_SYSTEM.md`。

## 找東西
問題 → 文件的對照在 `docs/INDEX.md` 的「Takeover recall」。

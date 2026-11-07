# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-08 00:10 Asia/Taipei
Source SHA: 944ecc35e (origin/main at write time; live = 221a8566a)
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
| Release `221a8566a` | **已上線**（run 42385019151，health 200）。含 #5046 #5048 #5068 #5080 #5085 #5087 #5092 #5093 #5102 #5105。 | 下一班：#5090 #5101 #5103 #5106 #5107 #5109 #5110 merge 後 dispatch release-train（Founder Approve） | `gh run list --workflow deploy.yml` |
| in-app 518 (#5097) | #5085（月結誤報）+ #5087（字級 標準／大）已上線；triage 回覆已送。 | smoke run 42387205840 綠了 → POP resolve（spec `inapp-518-resolve-1`） | #5097 |
| in-app 517 (#5057) | PR1 #5060 + PR2 #5068（合併＋復原）已上線；回報已 closed，follow-up 留言已送。 | 後續：#5104（binary collation 比對）、lock 測試 PR | #5057、#5104 |
| in-app 519 (#5098) | #5101 R3 PASS（>500 學生分頁已修），`autoland`；triage 回覆已送。 | 上線後 POP resolve | PR #5101 |
| in-app 521 (#5111) | 根因：收款撤銷兩次。#5106（reversal 綁 `payment_report_id`，舊資料 409）+ #5109 測試已 merge；#5107 收款標課程（R1）。triage 回覆已送。 | #5110 repair POP（只綁 2 筆）R3 PASS → 上線後 dry-run → Founder approve；void 940 / bill 960 另案更正（`docs/incidents/RM-LEGACY-VOID-LINK-20261107.md`） | #5111 |
| in-app 514 (#5035) | re-key POP #5046 已上線。 | dry-run → Founder approve | PR #5046 |
| 帳務中心「比 Excel 好用」 | #5103 Excel 匯出（只看、無匯入；應收＝已收＋未收）delta R3 中。PR-B TanStack 表格已做好在 `feat/billing-grid`，等 #5103 merge。 | PR-B → PR-C（點擊數量測） | PR #5103 |
| 流程改善 W12–W14 | W13 autoland 已驗證；W14 #5105（28 個沒跑的前端測試，現在全跑）已上線。#5082/#5084 mutation testing、#5083 自檢 待 Codex 乾淨。 | W9–W11 由 flow agent | #4953 |
| Overlay 樣板 | census #5093 已上線；樣板 #5094、ratchet #5095 等 Codex 乾淨。 | 之後一頁一 PR，before/after 貼 #4989 | #4989 |
| AI 維運閉環 (#5070) | #5076–#5079 等 R3（#5078/#5079 需 security-data-reviewer marker）。 | 一次 R3 pass | #5070 |

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

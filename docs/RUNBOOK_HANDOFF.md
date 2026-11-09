# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-09 07:23 UTC（本輪 in-app／Codex 改善；其他 tracker 需重查）
Source SHA: 2badb606d604d8d084e057fccdbda7ec538465fb（讀取時 main；不是下一次接續的現況保證）
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
| Release `d1230679349c` | manifest 的 backend/frontend 一致；deploy run 42565887664 成功。#5134 課程介面已上線。#5135 Excel、#5107 收據已 merge，尚未在此正式 SHA。 | 先完成 #5120；重查既有等待中的 release，不重複 dispatch／不代按 protected approval | `gh run list --workflow deploy.yml`、正式 `deployment.json` |
| in-app 518 (#5097) | #5085（月結誤報）+ #5087（字級 標準／大）已上線；triage 回覆已送。 | smoke run 42387205840 綠了 → POP resolve（spec `inapp-518-resolve-1`） | #5097 |
| in-app 517 (#5057) | PR1 #5060 + PR2 #5068（合併＋復原）已上線；回報已 closed，follow-up 留言已送。 | 後續：#5104（binary collation 比對）、lock 測試 PR | #5057、#5104 |
| in-app 501／527 介面 | #5134 已上線；外層合約維持新到舊，歷史／篩選／帳務入口保留。 | #527 堂次歸屬另案，不能用顯示排序宣告資料修好 | #4918、#5130、#5124 |
| in-app 519 | 正式主任 smoke run 42570410596 通過開啟新增表單與取消；沒有新增課程。GitHub 證據已回寫。 | 重新讀案件後用合法 POP 回覆；此環境只有 triage 唯讀 token，不代按回報者確認 | #5098 comment 6733599877 |
| in-app 521 | #5107 收據分組已 merge；#5110 現為 digest／request-owned inverse／時間戳保護，13 個隔離 MySQL 測試通過，未執行正式修復。 | 最終 exact-head review／CI 後排隊；取得新現況與精確核准包。第三筆爭議維持另案 | #5111、#5116、PR #5110 |
| in-app 514 | 既有期別／re-key 工具已上線；尚無本輪完整 POP receipt／目前帳單證據。 | 先核對，不盲目重跑歷史更正 | #5035、PR #5046 |
| in-app 520／526 | #5103 平面匯出已上線；#5135 每學生 XLSX 已 merge，指定主任更新卡已補。 | 上線後實際下載讀回；其餘表格改善仍未完成 | #5099、#5131、PR #5135 |
| in-app 523 | #5120 f06b810a：8 required checks／獨立審查通過，新增並行刷新 finding 待修。 | 先處理 finding 4510218212，再重新驗證最終 head、排隊；不把舊 PASS 沿用為新 head | #5114、PR #5120 |
| in-app 522／524／527 資料 | 422 原因、日期投影、合約堂次仍需最新受控資料。#5137 DBHub view-only 準備已 merge，但未建立正式連線／帳號。 | 原始授課、到班、評量、財務與薪資保留；先逐筆模擬與 inverse，再提出核准 | #5113、#5133、#5124 |
| Codex 接續改善 | 原生按需 skill＋唯讀 checkpoint reconciliation 已本機實際執行；無自動模型路由／正式新權限。 | 最終 review／CI；用既有 handoff，不複製整段歷史 | `docs/engineering/CODEX_TASK_CONTINUITY.md` |
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

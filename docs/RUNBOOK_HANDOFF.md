# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-11 05:30 UTC（PR 收尾、入口文件去重、驗收修復、in-app 530；release train 仍等核准）
Source SHA: beb8789be5a0（main；正式 runtime 4d186abcf211e9b5109301285af093a57c8470d4，2026-11-10T03:56Z 部署）
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
| Release | 正式站 `4d186abc`；main `beb8789b`。Release train run 42818474421（目標 3c58674）等 `production-activation` 核准，之後的合併都排在它後面。 | Founder 核准（或改開一班以 main 為目標的 train）；核准前 532／533／#5158／#5121 的修正都不在正式站 | run 42818474421、`deploy.yml` |
| in-app 佇列（11-11 05:30 UTC 讀取） | `new` 0；`triaged` 11：514、520、521、522、524、526、527、530、531、532、533。 | 見下列各行；`resolved` 是回報者確認佇列，獨立計算 | triage API |
| in-app 526 (#5131) | 已上線並驗證；結案資料包備妥（fix d092cfe、deploy run 42752332278、smoke run 42854847807、request_id `inapp-526-resolve-1`）。雲端環境的 POP 機器金鑰被正式站回 401，草稿送不出去。 | Founder 重發金鑰後重送，或在 POP console 直接結案 | #5131、#5162 留言 |
| in-app 532／533 (#5164／#5165) | 修正 #5167／#5168 已合併、未上線。 | 上線＋smoke 後走 POP resolve | #5164、#5165 |
| in-app 530 (#5151) | 拒絕本身正確（學生同時段已有課）；缺的是說明。#5176 讓拒絕訊息列出佔住時段的課，審查通過、在 land queue。哪份合約擁有那些固定課仍需一次唯讀診斷。 | 合併→上線→follow-up 回覆 | #5151、#5176 |
| in-app 531 (#5160) | 根因已確認：堂數制首張帳單照輸入金額開立、沖銷後帳單仍留著。超收已有防護；「低於合約金額」與「沖銷唯一收款後帳單去留」是帳務規則。 | Founder 決定規則（F4）；可先寫唯讀全校掃描 case | #5160 |
| in-app 522 (#5113) | 「撤銷收據」按鈕在收據與帳單不符時必被後端拒絕（`void_invoice_mismatch`），是死路；另一個可能是缺撤銷理由。 | 用既有 `confirmed_report_void_blocked` case 確認是哪一個，再修 | #5113 |
| in-app 520 (#5099) | 匯出已上線；搜尋／排序／批次入帳在回報前就有，未與本回報對照驗收。 | 確認現有功能是否滿足，否則另開具名 issue 再回覆 | #5099 |
| in-app 521 (#5111／#5116) | 程式與 POP 策略已上線；正式帳務未修（2 筆待連結，第 3 筆需人工決定）。 | Founder 在 POP 執行一次（C2） | #5162 |
| in-app 514／524／527 資料 | 正式資料未修。證據 run 42583118349／42583121077 是加密的，讀取等 #5159 的解密保管決定。 | #5159 GO → 解讀 → manifest → POP | #5162、#5124、#5159 |
| 正式驗收訊號 | 「Calendar/Course Production Acceptance」自 #5147 起每次合併都紅：單日 `GET class-sessions` 會寫入，被唯讀防護擋下；兩個一致性測試其間是空跑。#5180 修復已審查通過。 | 合併後確認第一個 post-merge run 轉綠才關 #5175；後續 #5177（會寫入的 GET）、#5178（另兩個 spec＋審查留下的 P2） | #5175、#5180、#5177、#5178 |
| 流程／文檔 (#5015、#4953) | 已合併：#5172（入口文件去重）、#5174（優先順序單一出處）、#5179（`scripts/ci/pr-digest.mjs`，一張 PR 一行）。#5015 留言有各項完成度與下三張 PR 的順序。 | 依 #5015 留言順序做；L5（land queue 依車道檢查關卡標記）未做 | #5015、#4953 |
| 學生個人歷程／學習檔案 | #4970 step 3a 進行中（同上一版）。 | 同上一版 | #4928、#4970 |
| UI 改版 (#4989) | 零件與 overlay ratchet 已在 main。第一張整頁遷移：版本更新頁 #5181，CI 進行中。 | 過 CI→獨立審查→下一頁（Wave 3 小頁優先，一次最多兩張） | #4989、#5181 |
| 資料修復總表 | C12 五張凍結 issue 已做程式面重驗（都還不能關）；其餘各項待重掃。 | 見 #5162 留言 | #5162 |
| 草稿 #5122 | 落後 main 49 個 commit、有衝突；內容（#5117／#5118 文檔界線）部分已被 #5172 取代。 | 對照 main 後重做成新 PR 或關閉 | #5122、#5117、#5118 |
| PR 清潔工 | 排程任務「AllTrue PR janitor」每日 01:30／13:30 UTC。 | — | scheduled task |

**等 Founder（2026-11-11，一次列完）：**
1. 核准 release train run 42818474421（或改開以 main 為目標的一班）。
2. 重發雲端環境的 POP in-app agent 金鑰（header `X-POP-MACHINE-KEY`，正式站回 401）。
3. in-app 531 的帳務規則（F4）。
4. #5159 解密保管 GO（C1／C3 依賴）。
5. #5015 的 D1（native merge queue）、D2（ruleset 加 `review/final-head`）。
6. #5117：Agent 可否 dispatch 會 SSH 正式機的既有 workflow（Phase A／follow-up／case dump）。
7. 環境變數 `ALLTRUE_BUGOPS_*` 的用途未在 repo 定義；未定義前 Agent 不使用。

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

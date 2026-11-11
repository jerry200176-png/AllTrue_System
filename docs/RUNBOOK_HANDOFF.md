# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-11 18:40 UTC（正式站 1dc27fe4；531／537 修正與 SOP plan 格式已合併未上線；532 結案等核准）
Source SHA: 7885dc9c（main；正式 runtime 1dc27fe483677b5d83e1071de13c822cbe5250cb，2026-11-11T13:48Z 部署）
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
| Release | 正式站 `1dc27fe4`（11-11 13:48Z）；main `7885dc9c`。Release train run 43041792254（目標 d7a3fc24）等 `production-activation` 核准，並擋住下一班（`Start release train` 回報「已有一班在等」）。未上線：#5192、#5193、#5194、#5189、#5195、#5200、#5202–#5204。 | Founder 核准該 run；之後的 04:17／10:17 UTC 班次會補到 main tip | run 43041792254、`deploy.yml` |
| in-app 佇列（11-11 18:30 UTC 讀取） | `new` 0；`triaged` 13：514、520、521、522、524、526、527、530、531、532、533、536、537。534／535 已在分診時回覆並結束。 | 見下列各行；`resolved` 是回報者確認佇列，獨立計算 | triage API |
| in-app 526／532／533 (#5131／#5164／#5165) | 修正都在正式站。532 的 POP resolve 草稿與 dry-run 已通過（request `1fba97fa-876e-4950-b515-ba6b562bf525`，deploy run 43013495588，驗收 run 43068600119）；核准這一步被雲端 session 的權限分類擋下，尚未執行。533／526 尚未起草（526 舊 request 用的是舊 deploy run，要重起）。雲端環境存的 POP 機器金鑰仍是舊值，所以走超級管理員的人工 POP 流程。 | Founder 明確同意後由 Agent 核准，或 Founder 在 POP console 核准；再逐筆讀回 | #5131、#5164、#5165 |
| in-app 530 (#5151) | #5176 已上線：拒絕訊息列出佔住時段的課；已送進度回覆。哪份合約擁有那些固定課仍需一次唯讀診斷。 | 唯讀診斷 → 回覆／POP resolve | #5151、#5176 |
| in-app 531 (#5160) | 規則已由 Founder 定案（11-11）；#5193 已合併、未上線：堂數制首張帳單照合約金額開立；沖銷唯一收款時連同自動開立的帳單一起作廢。 | 上線＋驗收 → POP resolve | #5160、#5193 |
| in-app 522 (#5113) | #5185 已上線並送進度回覆。收據自助更正流程的設計在 #5113；PR 1（#5194，紀錄被拒事件）已合併、未上線。PR 2–5 需要金額／天數門檻與跨學生更正的產品決定。 | Founder 回答門檻 → 依設計做 PR 2–5 | #5113、#5194 |
| in-app 520 (#5099) | 匯出已上線；搜尋／排序／批次入帳在回報前就有，未與本回報對照驗收。 | 確認現有功能是否滿足，否則另開具名 issue 再回覆 | #5099 |
| in-app 536 (#5198) | 已分診並回覆；還缺該課程開課日／已排堂次的唯讀證據。 | 唯讀查證 → 回覆 | #5198 |
| in-app 537 (#5199) | #5200 已合併、未上線：月報表的套裝列依當月帳單顯示。 | 上線＋驗收 → 回覆／POP resolve | #5199、#5200 |
| in-app 521 (#5111／#5116) | 程式與 POP 策略已上線；正式帳務未修（2 筆待連結，第 3 筆需人工決定）。 | Founder 在 POP 執行一次（C2） | #5162 |
| in-app 514／524／527 資料 | 正式資料未修。#5124 的修復包需要目前每堂課歸屬哪份合約的唯讀資料與全校同類掃描；尚未取得讀取同意。 | Founder 同意唯讀讀取 → manifest → POP `course-contract-repair`（寫入前先給 Founder 看） | #5162、#5124、#5159 |
| 正式驗收訊號 | 綠燈：run 43068600119（`7885dc9c` 的工作流程，對正式站）通過。瀏覽器安裝改為先探測、限時、分開存快取（#5203／#5204）。`deploy.yml` 內還有一處 `--with-deps` 未改。 | #5177、#5178、#5183；#5201 的未結列 | #5201、#5177、#5178、#5183 |
| 流程／文檔 (#5015、#4953、#5201) | 已合併：#5172、#5174、#5179（`pr-digest.mjs`）、#5202（`docs/sop/ISSUE_PLAN.md`＋`scripts/ci/issue-plan-check.mjs`，R3 必用完整版）、規則書 K3 v2.2.0（依風險選審查模型）。排程任務每小時觸發 in-app 收件。#5201 檢討表第 5、6、7、8、12、13 列未結。 | 把進行中的 issue 改成 plan 格式；做 #5201 未結列；#5015 下一張（CI 讀 PR 內文宣告、移除休眠控制） | #5201、#5015 |
| 學生個人歷程／學習檔案 | #4970 step 3a 進行中（同上一版）。 | 同上一版 | #4928、#4970 |
| UI 改版 (#4989) | 已上線：#5181、#5186。已合併、未上線：#5189、#5195（舊元件清單 78）。#5190（外殼在窄寬度溢出）未做。 | 一次一頁；`DuplicateSessionReviewPage` 需先拆分；brief 要寫「保留 landmark／語意」 | #4989、#5190 |
| 資料修復總表 | C12 五張凍結 issue 已做程式面重驗（都還不能關）；其餘各項待重掃。 | 見 #5162 留言 | #5162 |
| PR 清潔工 | 排程任務「AllTrue PR janitor」每日 01:30／13:30 UTC。 | — | scheduled task |

**等 Founder（2026-11-11 18:40 UTC，一次列完）：**
1. 核准 release train run 43041792254（`production-activation`）。
2. in-app 結案：同意 Agent 用超級管理員 POP 流程核准 532／533／526，或自行在 POP console 核准。
3. 把雲端環境存的 `X-POP-MACHINE-KEY` 換成新金鑰（id 4）；換好後 Agent 可改回機器流程。
4. #5124：同意 Agent 唯讀讀取該生堂次歸屬以準備修復包。
5. #5113 收據自助更正的金額／天數門檻與是否允許跨學生。
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

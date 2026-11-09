# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-09 13:36 UTC（本輪 in-app／Codex milestone；其他 tracker 保留、需各自重查）
Source SHA: 81819432b76a173249ee10f4bd0cb783ae014d5c（本次已合併 main；產品正式版本仍為下列 e996，不把 main 當部署）
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
| Release `e9964b4470f6` | deploy 42586376118 成功；已讀回 backend/frontend manifest、version 與 health。#5120／#5135／#5107／#5110 已隨此版上線，未執行正式資料修復。 | 後續版本由 coordinator 依既有 release gate 推進；不重複 dispatch／不代按 protected approval | `deployment.json`、deploy 42586376118 |
| in-app 518 (#5097) | #5085（月結誤報）+ #5087（字級 標準／大）已上線；triage 回覆已送。 | smoke run 42387205840 綠了 → POP resolve（spec `inapp-518-resolve-1`） | #5097 |
| in-app 517 (#5057) | PR1 #5060 + PR2 #5068（合併＋復原）已上線；回報已 closed，follow-up 留言已送。 | 後續：#5104（binary collation 比對）、lock 測試 PR | #5057、#5104 |
| in-app 501／527 介面 | #5134 排序介面已上線；501 現為 closed。 | 527 堂次歸屬尚未修復，保留授課歷史／帳務證據；不能用顯示排序宣告資料修好 | #4918、#5130、#5124 |
| in-app 519 | 正式主任新增表單開啟／取消已驗證；現為 closed。未新增課程、未代按回報者確認。 | 六筆上線回覆已逐筆讀回，勿重送；其他新增操作未列本次驗證 | #5098、follow-up 42608099856 |
| in-app 521 | #5107／#5110 工程隨 e996 上線；digest、request-owned atomic inverse 與核准恢復測試已有證據。 | 正式帳務未修；仍需新現況、精確核准與 receipt，第三筆爭議維持另案 | #5111、#5116、PR #5110 |
| in-app 514 | 既有期別／re-key 工具已上線；尚無本輪完整 POP receipt／目前帳單證據。 | 先核對，不盲目重跑歷史更正 | #5035、PR #5046 |
| in-app 520／526 | #5135 每學生 XLSX 隨 e996 上線；#5141 已 merge，#5147 guard head 的正式 UI 匯出／讀回已驗證。 | 區分匯出證據與其餘表格需求；不宣稱帳務資料已修 | #5099、#5131、ui-smoke 42623336868 |
| in-app 523 | #5120 已 merge 並隨 e996 上線；正式受影響 UI 可取消且未送退回 POST，未執行實際退回。 | 上線回覆 1433 已讀回；回報者驗收獨立，勿把取消測試當資料回復證據 | #5114、#5120、ui-smoke 42623336868 |
| in-app 522／524／527 資料 | 422 原因、日期投影、合約堂次仍需最新受控資料。#5137 DBHub view-only 準備已 merge，但未建立正式連線／帳號。 | 原始授課、到班、評量、財務與薪資保留；先逐筆模擬與 inverse，再提出核准 | #5113、#5133、#5124 |
| in-app 528 | #5145 已分診；#5148 Draft 原修正 c170ffb3 已整合 main；僅修同實體堂次快取位置，已有 source／真 Vue RED→GREEN。 | 本次整合已合併 #5147 的 main，待 final-head review／CI；可選 deployed-frontend 合成驗收本機兩次未 mount，已 DEFER 並撤出候選；B 未做、正式調課 committed UNKNOWN | #5145、PR #5148 |
| Acceptance／fixture | #5141 `7147a05d`、#5146 `d7b36967`、#5147 `81819432` 已 merge；guard exact-head CI 42623337018、正式 UI 42623336868、合併批次 CI 42625451946 成功。 | 5148 保持 Draft 至 coordinator 完成整合審查；未主張全 DB zero-write、實際調課或 reporter acceptance | #5141、#5146、#5147、#5148 |
| Codex 接續改善 | #5140／#5142 已 merge：按需 skill、唯讀 reconciliation 與 CI 狀態差異觀測；不新增路由／正式權限。 | 沿用既有 handoff、接續先 live read；觀測不是 approval／gate 或自動動作重播 | #5140、#5142、`docs/engineering/CODEX_TASK_CONTINUITY.md` |
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

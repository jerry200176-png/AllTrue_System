# 交接狀態 Handoff（活的；每個 milestone 由 coordinator 更新）

Updated: 2026-11-10 03:13 UTC（265部署與5155驗收收尾；Founder重申日期/超堂優先、歷程與UI並行）
Source SHA: 9272df282d3e9c500389008c2891fc493b618bf4（main test/docs；正式runtime265165f112523ce1d80410f8a45da1e83daee308）
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
| Release `265165f11252` | deploy42642960843 actualSUCCESS；1200s/37checks watch與backend/frontend/build identity一致。5155 test/docs已merge9272df，mainCI42745205440／control-plane42746083617成功，未另啟用app。 | 第一順位#5124日期/堂次；不得重跑已取消7147/c88 train或再問265approval | #5124、#5145、#5149、#5155 |
| in-app 518 (#5097) | #5085（月結誤報）+ #5087（字級 標準／大）已上線；triage 回覆已送。 | smoke run 42387205840 綠了 → POP resolve（spec `inapp-518-resolve-1`） | #5097 |
| in-app 517 (#5057) | PR1 #5060 + PR2 #5068（合併＋復原）已上線；回報已 closed，follow-up 留言已送。 | 後續：#5104（binary collation 比對）、lock 測試 PR | #5057、#5104 |
| in-app 501／527 介面 | #5134 排序介面已上線；501 現為 closed。 | 527 堂次歸屬尚未修復，保留授課歷史／帳務證據；不能用顯示排序宣告資料修好 | #4918、#5130、#5124 |
| in-app 519 | 正式主任新增表單開啟／取消已驗證；現為 closed。未新增課程、未代按回報者確認。 | 六筆上線回覆已逐筆讀回，勿重送；其他新增操作未列本次驗證 | #5098、follow-up 42608099856 |
| in-app 521 | #5107／#5110 工程隨 e996 上線；digest、request-owned atomic inverse 與核准恢復測試已有證據。 | 正式帳務未修；仍需新現況、精確核准與 receipt，第三筆爭議維持另案 | #5111、#5116、PR #5110 |
| in-app 514 | 既有期別／re-key 工具已上線；尚無本輪完整 POP receipt／目前帳單證據。 | 先核對，不盲目重跑歷史更正 | #5035、PR #5046 |
| in-app 520／526 | #5135 每學生 XLSX 隨 e996 上線；#5141 已 merge，#5147 guard head 的正式 UI 匯出／讀回已驗證。 | 區分匯出證據與其餘表格需求；不宣稱帳務資料已修 | #5099、#5131、ui-smoke 42623336868 |
| in-app 523 | #5120 已 merge 並隨 e996 上線；正式受影響 UI 可取消且未送退回 POST，未執行實際退回。 | 上線回覆 1433 已讀回；回報者驗收獨立，勿把取消測試當資料回復證據 | #5114、#5120、ui-smoke 42623336868 |
| in-app 522／524／527 資料 | 正式日期/堂次/帳務未修。527現已隔離重現leave調課繞過undo令8→9，防再發guard另行工程中；原個案root仍需currentrows。 | 原教學/評量/財務/薪資保留；須最新受控讀取/完整manifest/模擬inverse後精確核准，不執行歷史三筆候選 | #5113、#5133、#5124、#5130 |
| in-app 528 | #5148 cachefix已隨265上線；5155具名正式frontend合成路徑PASS，testharness merge-ref bc5bebd與runtime265分開。 | follow-up1439精確讀回；原調課transaction/currentdata UNKNOWN、sliceB deferred，勿以mock宣告資料已修 | #5145、#5148、#5155、ui42743588674 |
| in-app 529 | #5150失敗提示/停止自動重送已隨265上線；source CI/review及獨立真Vue回歸通過。 | follow-up1440精確讀回；原422原因UNKNOWN；保留刪除/帳務guard，普通PhaseC仍需既有合法requester | #5149、#5150、follow-up42745236453 |
| Acceptance／fixture | #5155已nativequeue merge9272df；8required+independent+CodebotPASS，正式private3case實際PASS/regular64pass55skip。 | 只代表具名範圍；排課用mock資料、實際transaction未驗證；無PNG或guard放寬 | #5155、ui42743588674 |
| 學生個人歷程／學習檔案 | 正確後續#4970（非523評量rollback）；505 resolved只代表第一階段timeline。schooldates/exams已在265；4970step3a在整合真實紀錄/成績/下一校考。 | 以既有API/元件交付可用profile；老師round/subject/campus守門、家長拒絕；模板/計畫/Gantt/完成追蹤仍列後續，未宣告完成 | #4928、#4970、#4989 |
| in-app 530 | PhaseA42743264849成功，triaged；reply1438精確讀回；25-entry history current+24prior已讀，NEEDS_EVIDENCE。 | 完整雙固定日/實際422原因仍需受控currentdata，勿把截圖衝突等同漏排或265regression | #5151 |
| Codex 接續改善 | #5140／#5142 已 merge：按需 skill、唯讀 reconciliation 與 CI 狀態差異觀測；不新增路由／正式權限。 | 沿用既有 handoff、接續先 live read；觀測不是 approval／gate 或自動動作重播 | #5140、#5142、`docs/engineering/CODEX_TASK_CONTINUITY.md` |
| 流程改善 W12–W14 | W13 autoland 已驗證；W14 #5105（28 個沒跑的前端測試，現在全跑）已上線。#5082/#5084 mutation testing、#5083 自檢 待 Codex 乾淨。 | W9–W11 由 flow agent | #4953 |
| Overlay／UI改版 | #4989持續；#5121原sourceCI綠但與main僅Playwright exclusions衝突，ownedintegration保留兩方隔離後重驗，原branch未覆寫。 | 和學生學習檔案最多兩個pagePR；其他ratchet待各自審查，不更新PNG掩蓋4項既有差異 | #4989、#5119、#5121 |
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

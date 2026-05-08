# AllTrue Changelog

> 格式：每條一行，分類 Added / Fixed / Changed / Security / Ops  
> 細節查 PR 說明或 `.cursor/plans/`  
> **舊記錄（2026-04-19 以前）**：[CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)

---

## 2026-05-09 — docs(qa): Golden Scenarios 速驗清單

- Added `docs/QA_GOLDEN_SCENARIOS.md`：依模組可勾選 PR／AI merge 前 smoke；`INDEX` 導航；PR 模板補 Golden 對照勾選

---

## 2026-05-09 — fix(import): 學生名冊 CSV/XLSX 標題列與 0 列匯入

- Fixed `ImportController`：標題列掃描加深至前 30 列、`normalizeHeader` 支援全形空白；若僅有表頭無資料列回 **422** 並寫入可讀 `ErrorLog`；補 `StudentImportTest` 迴歸案例（#205）

---

## 2026-05-09 — docs(ops): Pi 溫度告警與老師簽到診斷 SOP

- Changed `docs/OPERATIONS_RUNBOOK.md` §B2b／B2c：`pi-health`／`teacher-signin-diagnose` 使用方式與紀律（#195、#197）

---

## 2026-05-09 — docs(ai): CLAUDE 移除重複 MASTER WORKFLOW 區塊

- Changed `CLAUDE.md`：長版 Phase／角色規格改指向 `.cursorrules` 為單一權威，避免與 always-loaded 規則重複分叉（#203）

---

## 2026-05-09 — chore(github): PR 模板補 Refs／Closes 規則

- Changed `.github/pull_request_template.md`：多階段／Epic issue 預設用 `Refs`，全案驗收完成才用 `Closes`；並調整 merge 前 CI／CHANGELOG／高風險模組檢查清單

---

## 2026-05-09 — chore(github): Issue 模板、CONTRIBUTING、SECURITY 與 CODEOWNERS 補強

- Added `.github/ISSUE_TEMPLATE/*` + `config.yml`、根目錄 `CONTRIBUTING.md`／`SECURITY.md`；補 `CODEOWNERS` 納入 `backend/routes/api.php` 與 `docs/DANGEROUS_OPERATIONS.md`；`INDEX`／`README`／`AGENTS` 導航更新

---

## 2026-05-09 — ops(ci): presubmit alwaysApply soft warning（>400）

- Changed `presubmit.yml`：alwaysApply 總行數超過 400 先輸出 GitHub warning（不干擾 merge），超過 500 仍 hard fail

---

## 2026-05-09 — fix(calendar): 行事曆改用 occurrence 合約合併

- Fixed 智慧行事曆週檢視以單一 occurrence resolver 合併 `StudentClass`、`ClassSession` 與 `schedules`，避免同一堂課重複掛兩位老師或被 scheduled 例外互相抵消而消失

---

## 2026-05-09 — fix(calendar): 停用舊課程不再重複掛兩位老師

- Fixed 智慧行事曆載入課程時排除 `status=inactive` 或 `Stop=1` 的舊課，避免同學生在舊/新老師欄位同時出現

---

## 2026-05-09 — ops(ci): PHPStan 明確改為 advisory gate

- Ops 將 workflow job 名稱改為 `PHPStan Advisory (php)`，並同步 main branch protection required checks 移除舊 `PHPStan (php)`，避免「看似硬擋實際不擋」語意落差

---

## 2026-05-09 — ops(db): PITR/binlog 評估決策（先 defer）

- Ops/DBA 在 runbook 明確記錄 `#207` 決策：目前先不啟用 production binlog，補齊觸發條件與 pre-enable checklist（限 drill DB 驗證）後再啟動

---

## 2026-05-09 — ops(backups): nightly 移除 Pi 端 git push/tag

- Ops 調整 `scripts/nightly-backup.sh` 僅保留 DB 備份與保留策略，不再從 Pi 嘗試 `git-sync` 或 nightly tag push，與 protected-main 治理一致

---

## 2026-05-09 — ops(actions): 降低排程 Actions 依賴並補 fallback

- Ops 將 `pi-health.yml` 降為每日、`branch-hygiene.yml` 降為每週，並在 runbook 補齊「minutes 耗盡時由 Pi 本機 monitor-alert + UptimeRobot 承接」與恢復後 rerun 清單

---

## 2026-05-09 — ops(backups): 新增一鍵備份稽核腳本

- Ops 新增 `scripts/backup-audit.sh`，集中檢查本機備份、manifest、Google Drive 異地同步、restore drill 結果與只讀 row-count sanity，並以 GREEN/YELLOW/RED 輸出總結

---

## 2026-05-08 — ops(ci): 補齊 WSL2 runner 硬化維運 SOP

- Ops 新增 self-hosted runner 邊界、健康檢查、離線恢復與疑似入侵停用流程，確保 CI-only runner 不承載 production deploy secrets

---

## 2026-05-08 — security(ops): 維運 SSH 改為 host key pinning

- Security 將 `pi-health.yml`、`backup-restore-test.yml`、`slow-query-report.yml` 移除 `StrictHostKeyChecking no`，改用 `PI_HOST_KEY` + `known_hosts` pinning，並在 Presubmit 增加禁止回歸檢查

---

## 2026-05-08 — ops(ci): docs-only PR required checks 對齊

- Ops 調整 `ci.yml` 改為所有 PR 皆觸發並保留 required check context，再用 changed-areas job 決定是否執行 PHPUnit/Vite，修復 docs-only PR 缺少 required checks 無法合併

---

## 2026-05-08 — fix(schedule): 代課堂次顯示在代課老師欄

- Fixed 同一堂代課若殘留原老師與代課老師的重複排程紀錄，行事曆會優先顯示在代課老師欄位

---

## 2026-05-08 — Changed: 課表捲動與學生姓名可讀性

- Changed 智慧行事曆捲動時固定老師/日期標題列，並放大課程卡片學生姓名以改善滿段時的辨識度

---

## 2026-05-08 — fix(schedule): 調課後行事曆避免重複顯示

- Fixed 拖曳移動課表時段後可能留下重複調課目標紀錄，導致行事曆多顯示一堂的問題

---

## 2026-05-08 — fix(attendance): 手機版補點名操作入口可見

- Fixed 手機版出勤頁「待補點名」日期查詢與補登操作容易被底部導覽遮住，改為直向排列並加安全留白

---

## 2026-05-08 — fix(schedule): 行事曆顯示現任課程老師

- Fixed 行事曆堂次老師名稱會被舊學習評量老師覆蓋的問題，課程改老師後圖表改顯示現任老師

---

## 2026-05-08 — fix(schedule): 補請假指定同日正確堂次

- Fixed 出缺勤頁補請假會帶入 `ClassSessionID`，後端依指定堂次處理，避免同一天同課程多堂時誤改第一堂

---

## 2026-05-08 — security(deps): 升級 PhpSpreadsheet 修復 CI audit HIGH 漏洞

- Security 升級 `phpoffice/phpspreadsheet` 至安全版，清除 Composer audit 的 HIGH/CRITICAL 阻擋項目

---

## 2026-05-08 — fix(schedule): 調課時取消自動補建的重複堂次

- Fixed 調課流程若先由 `schedules` 自動補建目標日堂次，移動原 `ClassSession` 時會取消該占位堂，避免目標日出現兩筆有效堂次

---

## 2026-05-08 — fix(attendance): 同時段重複堂次不可重複扣堂

- Fixed 點名頁遇到同課程同日期同開始時間的重複 `ClassSession` 時只顯示一筆，且後端阻止第二筆有效點名再次扣堂

---

## 2026-05-08 — fix(billing): 課程編輯繳費日期同步課程欄顯示

- Fixed 個別課程編輯調整 `paid_at` 後，會同步更新該課程最新有效收款日期，避免課程欄仍顯示舊繳費日期造成「改了但看起來沒生效」的誤解

---

## 2026-05-08 — fix(schedule): 例外堂補建 ClassSession 避免灰色堂次消失

- Fixed `schedules` 新增/更新為可上課狀態時會冪等補建對應 `ClassSession`，並新增 backfill 指令修復歷史漏建，避免課程列表有堂次但行事曆不顯示

---

## 2026-05-08 — fix(learning): 補填提醒跳轉與評量課表對齊

- Fixed 教學工作台補填提醒點入時會同步切換至對應分校並定位堂次，且評量頁可顯示近期需補填的非 active 課程，避免提醒可見但無法填寫

---

## 2026-04-30 — feat(workflow): 請假補課前端入口

- Added 家長端請假申請入口與主任端補課案件卡片，可產生候選時段並確認建立補課

---

## 2026-04-29 — feat(workflow): 補課候選確認 API

- Added 主任確認補課候選時段後，系統會冪等建立補課 schedules 與 ClassSession，並將 workflow 標記為 confirmed

---

## 2026-04-29 — feat(workflow): 補課候選時段產生器 API

- Added 主任可為請假 workflow 產生補課候選時段快照，依老師同時段容量避開滿載時段並保留分校隔離

---

## 2026-04-29 — feat(workflow): 家長請假申請與主任閉環 inbox API

- Added 家長請假申請會建立冪等 exception workflow，並新增主任依分校查詢請假補課 workflow inbox API

---

## 2026-04-29 — feat(workflow): 請假補課閉環核心資料層

- Added 例外事件閉環與補課候選快照的核心資料表、Model 與冪等 service skeleton，作為後續請假補課自動化基礎

---

## 2026-04-29 — feat(course): 多科共用堂數方案入口與加購分流

- Added 多科共用方案進階入口與方案成員加購分流，方案課程續報會增加共用方案總堂數，並補強舊課程綁定 guard

---

## 2026-04-29 — fix(billing): 課程繳費日期與帳單視窗一致

- Fixed 課程管理歷史課程繳費日期優先顯示實際帳單收款日，並修正帳單/對帳記錄視窗寬表格造成右上按鈕偏移

---

## 2026-04-29 — fix(course): 例外堂請假取消狀態標籤優先

- Fixed 課程管理例外堂被請假或取消後，日期 chip 外層標籤優先顯示請假/取消，例外堂改保留在提示資訊
- Ops Security Scan 對所有 PR 產生 required check，避免 frontend/docs PR 因 `PHPStan (php)` 缺席被 branch protection 卡住

---

## 2026-04-29 — docs(sop): 規劃研究加入開源專案參考

- Changed PRD/Bug/Agent SOP 規劃研究順序，除本專案文件與業界做法外，必須補查相關開源專案實作與取捨

---

## 2026-04-29 — fix(learning): 代課老師評量權限精準匹配時段

- Fixed 同一學生課程同日多時段時，代課老師評量列表與儲存權限改以同日期同開始時間判定，避免看到非自己時段後儲存 Forbidden

---

## 2026-04-29 — fix(parent): 家長端月結與繳費提醒語意

- Fixed 家長端月結課程改用課程帳期月份顯示，已繳停課月結課保留已繳費主狀態，且已繳低堂數舊約不再顯示成家長待繳費

---

## 2026-04-29 — ops(ci): WSL2 self-hosted runner 啟用

- Ops 將 CI / Presubmit / PHPStan checks 移至 WSL2 self-hosted runner，保留 `deploy.yml` 只用 GitHub-hosted runner，並更新文件避免 runner / DB secret 舊說法誤導

---

## 2026-04-29 — fix(import): 學生名單匯入標題列容錯

- Fixed 學生 CSV/XLSX 匯入可跳過檔案前方說明列尋找真正標題，並在失敗時顯示匯入錯誤而非誤導為 0 筆

---

## 2026-04-29 — fix(auth): 老師註冊密碼規則同步

- Fixed 老師自行註冊、主任新增老師與主任申請頁的密碼提示/前端檢查同步為 8 碼，並以緊急前端靜態檔部署上線

---

## 2026-04-29 — fix(attendance): 大直今日點名重複資料修復

- Fixed 大直周宏謙今日點名總表因停用舊課程殘留 scheduled 堂次而重複顯示，已備份後取消單筆舊堂次

---

## 2026-04-29 — fix(attendance): 老師登入帳號診斷補充 User fallback

- Changed read-only teacher sign-in diagnostic to report matching `User` / `UserCampus` / disabled `Teacher` rows when a login has no active teacher profile

---

## 2026-04-29 — fix(attendance): 老師打卡診斷支援登入帳號

- Changed read-only teacher sign-in diagnostic workflow to support exact `LoginName` lookup when the displayed teacher name is unknown

---

## 2026-04-29 — fix(attendance): 老師打卡只讀診斷工具

- Added read-only teacher sign-in diagnostic command and manual workflow to inspect missing sign-ins without modifying production data

---

## 2026-04-29 — ops(attendance): 老師打卡補登受控 workflow

- Added manual teacher sign-in recovery workflow with dry-run default, allowlisted command execution, and automatic DB backup before apply

---

## 2026-04-29 — fix(attendance): 老師 RFID 衝突歷史補登工具

- Added dry-run-first recovery command for historical teacher RFID collisions, so swallowed teacher sign-ins can be audited and restored without deleting original attendance data

---

## 2026-04-29 — chore(ci): GitHub Actions 額度節流

- Changed PR/main workflows to avoid waking heavy CI/security runs for docs-only changes and cancel stale Presubmit runs on repeated pushes

---

## 2026-04-29 — fix(mobile): 課程編輯儲存按鈕可點擊

- Fixed 手機版 modal 會被底部導覽列蓋住的層級與 safe-area 間距，避免 iPhone 12 mini 編輯課程時按不到儲存

---

## 2026-04-28 — fix(billing): 未收款錯帳作廢容錯

- Fixed 未收款錯帳即使從沖銷作廢入口送出也會安全落到一般作廢，避免歷史幽靈應收無法處理

---

## 2026-04-28 — fix(billing): 對帳視窗帳單作廢入口

- Fixed 學生帳務對帳視窗依 ledger 權限顯示一般作廢或沖銷作廢，避免未收款錯帳誤走沖銷流程

---

## 2026-04-28 — fix(billing): 錯帳沖銷作廢與 ledger 狀態

- Fixed 課程帳單對已收足額但狀態未繳的歷史錯帳顯示 ledger 例外，並提供保留收款稽核的沖銷作廢入口

---

## 2026-04-28 — fix(dashboard): 代課動態卡片標題對齊

- Fixed 總覽代課動態卡片在子元件內補齊 header/badge 樣式，貼齊今日課表排版並避免裸排版

---

## 2026-04-28 — fix(calendar): 請假卡被調課例外遮蔽

- Fixed 智慧行事曆同課程同日同時存在請假與 scheduled 例外時保留請假卡，避免張正樂 4/29 請假在課表消失

---

## 2026-04-28 — fix(billing-ui): 帳單作廢入口與代課卡片字型

- Added 課程管理月結帳單作廢入口，限制未收款帳單必填原因並保留稽核紀錄，同時修正總覽代課動態卡片排版與 CJK 字型 fallback

---

## 2026-04-28 — ops(backups): 備份健康檢查防假綠

- Ops 備份還原與 Pi health 檢查改以 sixhour 檔名時間選最新備份，避免檔案 mtime 被更新時誤判備份仍新鮮

---

## 2026-04-28 — fix(rfid): 老師分校卡優先辨識

- Fixed 同一張 RFID 同時命中大安老師分校卡與學生卡時，刷卡會優先建立老師打卡，避免老師出缺勤列表看不到本人刷卡紀錄

---

## 2026-04-28 — fix(records-billing): 歷史評量與作廢帳單排除

- Fixed 已上課歷史評量不再因課程後續停用而消失，並讓作廢 Invoice 排除於家長應收、課程帳單與主任催繳統計

---

## 2026-04-28 — fix(billing): 已付課程殘留帳單防重核帳

- Fixed 已付課程即使殘留未繳 Invoice 也不可再被主任核帳或家長回報確認入帳，避免同一門課產生錯誤對帳紀錄

---

## 2026-04-28 — feat(dashboard): 總覽儀表板高級視覺校準

- Changed 主任總覽儀表板與近 7 天代課紀錄卡片的字體、間距與資訊層級，延續 Porsche-inspired light-first 視覺系統

---

## 2026-04-28 — fix(accounting): 已結清查詢與例外處理

- Changed 帳務中心新增完整已結清查詢並將 AR ledger 例外帳集中提供撤銷/沖銷處理入口，堂數制課程也可從課程管理開啟帳單/對帳

## 2026-04-28 — fix(substitute): 單堂代課可回正班老師

- Fixed 課程管理單堂代課後可直接回復正班老師，避免正班老師被代課選擇器排除而搜尋不到

## 2026-04-28 — fix(accounting): 帳單套用與溢收標示

- Fixed 學生 AR 對帳表將同一帳單多筆收款依應收金額分流為已套用、溢收/待沖銷，並改用正式帳單/收據/課程編號避免 DB ID 混淆

## 2026-04-28 — fix(accounting): 學生帳務對帳表

- Added 帳務中心與課程帳單共用學生 AR 對帳表，將 Invoice、Payment、Receipt 與沖銷流水收斂到同一視圖並標示異常

## 2026-04-28 — fix(scheduling): 請假順延補堂防半套資料

- Fixed 行事曆請假失敗時不再直接寫入 `schedules`，並補上既有請假半套資料可安全重跑順延、補足購買堂數的 regression coverage

## 2026-04-28 — docs(agents): 外部 agent playbook 引用準則

- Added `agency-agents` 可參考但不可整包覆蓋 AllTrue P0/SOP 的整合準則

## 2026-04-28 — fix(accounting): 收款與帳單稽核口徑

- Changed 帳務中心區分有效收款流水與對應課程數，並在課程帳單展開付款/沖銷明細與收據號以利錯帳更正

## 2026-04-28 — fix(billing): 月結提醒使用逐期帳單應繳日

- Fixed 月結課程已預建未來期帳單時，繳費提醒改以未結清 Invoice 的 DueDate/billing_period 判斷，避免未來月份被誤判成本月逾期

## 2026-04-28 — fix(accounting): 逐筆錯帳撤銷入口

- Changed 收款與收據紀錄可直接逐筆撤銷已核帳收款，強制填寫原因並保留負值沖銷稽核，不刪除原付款資料

## 2026-04-28 — fix(attendance): 出缺勤紀錄日期顯示

- Changed 出缺勤紀錄預設顯示今天，最近 7 天模式新增日期欄避免只看時間難以判讀

## 2026-04-28 — fix(accounting): 收據與繳費單品牌語意

- Changed 繳費單與收據抬頭顯示完整補習班品牌與分校，並合併帳務中心收款／收據紀錄避免重複分頁混淆

## 2026-04-28 — fix(courses): 改正式老師保留跨日時段

- Fixed 課程編輯只改正式老師時，週三+週日等跨日固定時段不再被剩餘舊堂次洗成只剩週三

## 2026-04-28 — fix(courses): 固定時段契約不再被舊堂次覆蓋

- Fixed 課程編輯新增週日固定時段後，後端不再用舊未來堂次反寫覆蓋新排課契約

## 2026-04-28 — fix(courses): 固定時段列式編輯與核帳防重補強

- Fixed 課程編輯新增固定時段可直接在每列選週日，並補上家長回報核帳確認路徑的已繳課程重複入帳防護

## 2026-04-28 — fix(courses): 編輯多日課表與重複核帳防護

- Fixed 課程編輯週三+週日等多日固定時段被漏存，以及已繳課程可被再次核帳產生多筆付款的問題

## 2026-04-28 — fix(courses): 帳單日期與編輯多時段同步

- Fixed 月結帳單 Modal 區分應繳日與實際付款日，並修正課程編輯新增同日多固定時段時未來堂次仍只保留單時段的問題

## 2026-04-28 — fix(billing): 月結續報新一期與堂數超排防呆

- Fixed 月結續報改為建立新一期課程並結算舊期，且堂數詳情會區分補課例外與超出購買堂數的異常堂次

## 2026-04-27 — feat(ui): Porsche 視覺系統規格

- Added AllTrue Porsche-inspired light-first 視覺系統規格與共用前端 token/class，作為後續頁面升級的單一設計依據

## 2026-04-27 — feat(dashboard): Porsche-style 工作面板

- Changed 主任儀表板今日課表、繳費提醒、待審評量、通知與 KPI 面板為 light-first Porsche-style 霧面工作卡

## 2026-04-27 — feat(ui): Porsche-inspired 視覺校準

- Changed 課程管理與主任儀表板首屏為 light-first 高級霧面視覺，收斂過重 HUD/雷達感並統一 Porsche-inspired 設計語言

## 2026-04-27 — feat(dashboard): 主任指揮艙首屏

- Added 主任儀表板首屏的高級營運指揮艙視覺，包含分校 hero、每日待辦任務列與 performance HUD 統計

## 2026-04-27 — feat(courses): AAA 狀態與 Modal 補強

- Added 課程管理空狀態、loading skeleton、歷史課程卡與高風險 modal 的 AAA 視覺一致性補強

## 2026-04-27 — feat(courses): 戰術課程列表視覺

- Added 課程管理學生群組、課程 row、操作按鈕與詳情面板的戰術資訊列視覺，提升掃描速度與高風險狀態辨識度

## 2026-04-27 — feat(courses): Porsche-inspired command center 首屏

- Added 課程管理首屏的高級性能指揮艙視覺：深色霧面 hero、精準控制列、性能儀表統計與玻璃質感背景

## 2026-04-27 — feat(courses): 高質感課程列表

- Added 課程管理主列表、學生群組卡、課程狀態標籤、歷史課程卡與空狀態的 premium 視覺層級，並補上初次載入 skeleton

## 2026-04-27 — feat(courses): 高質感課程操作 Modal

- Added 課程管理加購、月結續約、暫停/恢復、刪除確認 modal 的 premium command-card 視覺層級，提升高風險操作的可讀性與辨識度

## 2026-04-27 — fix(courses): 複雜流程送出中防呆

- Fixed 課程管理加購、月結續約、暫停/恢復、刪除在送出中會鎖定按鈕與 modal，降低雙擊或重送造成重複操作的風險

## 2026-04-27 — fix(billing): 續報重複送出防護

- Fixed 續報確認與舊加購入口會鎖定來源課程並拒絕相同學生、科目、開課日與堂數的重複批次，避免雙擊或重送產生重複課程

## 2026-04-27 — fix(courses): 課程管理顯示月結逐期帳單

- Added 課程管理月結課程「帳單」入口，主任可查看各期 billing period 的已繳、未繳、部分繳狀態

## 2026-04-27 — fix(billing): 續報重複送出防護

- Fixed 續報確認與舊加購入口會鎖定來源課程並拒絕相同學生、科目、開課日與堂數的重複批次，避免雙擊或重送產生重複課程

## 2026-04-27 — feat(billing): 續報 preview/confirm 防呆 API

- Added 續報預覽與確認 API，確認前回傳帳單、排課與風險摘要，並用 state hash 防止課程資料變更後誤送舊確認

## 2026-04-27 — fix(billing): 0元課程可核帳結算

- Fixed 輔導課、試聽課等 Charge=0 課程可用 NT$0 完成主任核帳，避免免費課程永遠停留在未繳狀態
- Fixed 通知中心「確認已繳費」同樣支援 NT$0 免費課程結算
- Fixed 智慧課表同一學生同一天同開始時間已有基底課程時，不再重複渲染 scheduled 例外列

## 2026-04-27 — feat(billing): 月結制逐期帳單，修復白嫖漏洞

- Fixed 月結課程「月結續約」後 `StudentClass.Paid` 不再維持 1；renew-monthly 同步重置 Paid=0 並建立新期 Invoice（billing_period YYYY-MM），杜絕後續月份免費白嫖
- Added `Invoice.billing_period` 欄位（migration），實現業界「合約與帳單分離」設計
- Changed `directorRecord` 優先對應當月 billing_period 的未繳 Invoice，確保逐期帳務可追溯
- Added 新 API `GET /api/v1/student-classes/{id}/invoices`，回傳月結課程逐期帳單列表
- Added 前端月結課程新增「帳單」按鈕，可查逐期帳單 modal（期別、金額、狀態 chip）

## 2026-04-27 — fix(courses): 課程管理補課與暫停 UI SaaS 化

- Changed 課程管理將「新增堂次」統一改為「補課／補登」，重做暫停課程確認 modal 與狀態列，並修正單堂備註／時段的費用預覽語意

## 2026-04-27 — feat(courses): 統一課程方案建立入口

- Added 主任建立課程時可先選「每科獨立堂數」或「多科共用堂數」，保留既有一般課程與共用方案核心邏輯

## 2026-04-27 — docs(ops): 補 token 與 Actions 節流 SOP

- Changed Runbook 補低風險 docs 小修先累積、同類 docs 批次送出、避免混合 deployable diff 與 token conservation 規則
- Changed AGENTS 補 Agent Orchestration SOP：任務分級、bounded context 切分、artifact-only handoff 與 architecture boundary 原則
- Changed AI 公司組織圖新增 ORCH、INT、DOCS/MEM 職責，並明確小任務不啟動全流程
- Changed INDEX/Runbook/AGENTS 清理長篇 archive 判讀規則、branch protection 舊 reviewer 說法與過時協作者入口
- Security 必讀安全警示補強 code backup source of truth、DB offsite manifest、restore drill 與 production 禁令
- Changed Workflow 補 Risk Tier、Definition of Ready/Done、Stop-the-line 與 post-merge learning loop，對齊大廠與 AI orchestration 實務

## 2026-04-27 — docs(readme): 補 Architecture Diagram 與 ERD

- Changed README 新增 Mermaid Architecture Diagram、核心 ERD、Engineering Maturity 與 Known Gaps/Roadmap，方便對外展示系統成熟度

## 2026-04-27 — ops(governance): GitHub Pro 與備份成熟度補強

- Ops 啟用 `main` branch protection required checks，補強 Drive 備份 manifest、RPO/RTO/DR 演練 SOP，並登記 MySQL PITR/binlog 為 P1 技術債
- Ops 將 `scripts/` 納入 deployable diff，確保備份腳本變更會透過 `deploy.yml` 同步到 Pi

## 2026-04-27 — docs(governance): 清理 AI 導航過期連結

- Changed 文件導航、FAQ、部署與角色手冊移除不存在的舊入口連結與 `jerry-sync-main` 說法，統一為 feature branch → PR → CI → `deploy.yml`

## 2026-04-27 — changed(courses): 多科共用方案入口降噪

- Changed 新增課程預設直接進入一般課程建立，保留多科共用方案舊資料維護能力但不再作為主任日常新建入口

## 2026-04-27 — fix(courses): 推算月結堂次可單堂編輯

- Fixed 舊月結課程的推算預排日期可點擊建立實體堂次並進入單堂編輯，且同日期同時間不重複建立

## 2026-04-27 — fix(courses): legacy 月結詳情固定日期

- Fixed 舊月結課程缺 `EndDate` 或只有部分 `ClassSession` 時，課程詳情仍依固定星期顯示查詢月份的每週上課日期

## 2026-04-27 — fix(courses): 加購批次詳情顯示

- Fixed 堂數制加購成功後明確提示新批次課程與上課日期，並依課程期間載入詳情堂次，避免主任誤看原課程以為未更新

## 2026-04-27 — feat(learning-records): 主任給老師評語

- Added 學習評量表新增主任給老師的內部評語，老師可讀取並標記已讀

## 2026-04-27 — fix(courses): 預排堂次補齊與缺口校正

- Fixed 月結課程續約或編輯固定時段後補齊未來 `ClassSession`，讓課程管理詳情立即顯示該月固定預排堂次且不重複建立
- Fixed 堂數制補建堂次優先補中間缺口，避免漏排日期後在尾端多出第 N+1 堂

## 2026-04-27 — fix(courses): 結案課程取消未來堂次一致性

- Fixed 課程直接改為歷史狀態時也會取消未來 scheduled 堂次，避免結算後仍顯示未來排課

## 2026-04-27 — chore(campus): 新增新莊分校 & 中平分校

- Added 新莊分校（code: xinzhuang）、中平分校（code: zhongping）上線，migration 冪等零 downtime

## 2026-04-27 — feat(accounting): 帳務中心收款與收據紀錄

- Added 帳務中心新增已核帳收款清單、收據紀錄、預收標籤、CSV/PDF 匯出，並強化收據跨分校存取檢查

## 2026-04-27 — feat(monthly-courses): 月結建立課程日曆調整

- Added 月結制一般課程建立時可在日曆排除或加入初始堂次，避免建立後逐堂調課

## 2026-04-27 — chore(security): Composer audit warning 清償

- Security 升級 `league/commonmark` 至 2.8.2，並登記 Laravel major upgrade 技術債以追蹤剩餘 framework audit warning

## 2026-04-26 — feat(ambient-music): 工作音樂小彩蛋

- Added 主任／老師後台新增手動開啟的工作音樂播放器，使用瀏覽器本地合成音景避免第三方音檔授權風險
- Changed 工作音樂改用 AllTrue 自製 MP3（Tutoring Loop / Paper Window / Paperwork Rain）取代本地合成音景

## 2026-04-26 — fix(parent-feedback): 修復已讀反彈並強化回饋定位

- Fixed 家長回饋 mark-read 不再更新回饋內容時間，避免刷新後又變未讀；學習評量表新增有回饋／未讀回饋篩選

## 2026-04-26 — feat(learning-records): 家長溝通快捷片語優化

- Changed 學習評量「學習進度與家長溝通」快捷片語擴充為 10 個家長友善選項，預設顯示 6 個並可展開更多，降低老師撰寫評語負擔

## 2026-04-26 — feat(notifications): 通知中心核帳流程升級（PR #104）

- Added 通知中心「標記已繳費」可填繳費日期、方式、金額與備註，並同步建立 Payment / Invoice 核帳記錄
- Changed 繳費與堂數提醒的「前往處理」導向催繳名單，通知卡片補充學生、科目與金額摘要

## 2026-04-26 — fix(sentry): 處理 MIME type 錯誤 #100 及 N+1 誤判 #101（PR #102）

- Fixed 加入 `window.addEventListener('error')` 捕捉 MIME type 錯誤並自動 reload；Sentry ignoreErrors 加入對應樣式
- Fixed `classSessionsApi.js` CHUNK_SIZE 60→200，消除分批請求被 Sentry 誤判 N+1 的根因

## 2026-04-26 — fix(students): 月結課程在學生管理被誤判歷史課程而隱藏（PR #98）

- Fixed `isHistoricalCourse()` 對月結制（payment_type=monthly）課程缺少保護，RemainingSessions=0 + 已繳費導致課程被過濾，補習科目/堂數欄顯示「尚未設定」

## 2026-04-26 — feat(parent-feedback): 家長建議回饋系統（PR #94, #95）

- Added: 家長入口學習 Tab 底部新增「台北全真一對一補習班自主研發」品牌回饋卡片（分類 Chip + 星評 + 文字輸入，mobile-first）
- Added: 後端 `parent_feedback` 資料表 + `ParentFeedbackController`（4 支 API）
- Added: super_admin Bug 回報頁新增「家長回饋」Tab，含未讀 badge、分類篩選、標記已處理

## 2026-04-26 — feat(parent-portal): 三 Tab 架構改版，學習優先（PR #90）

- Changed: 家長入口頁改為三 Tab（學習/課表/帳務），預設進入「學習」Tab 顯示評量與出缺勤
- Changed: 移除 Profile Card 的剩餘堂數環形圖，降低「催費感」
- Changed: 繳費提醒移至「帳務」Tab，改柔和藍色 info-card 取代橙色警報樣式
- Added: 帳務 Tab 有待繳費項目時顯示紅色數字 badge

## 2026-04-26 — fix(ops): 還原 GitHub Actions SSH Deploy 三組 secrets（PI_SSH_KEY / PI_USER / PI_HOST）

- Ops: `PI_SSH_KEY` 還原為原始 `rpi_actions_deploy` 私鑰（指紋 `B/tQBH...`），修復 deploy.yml Permission denied
- Ops: `PI_USER` 由錯誤的 `admin@pi.lifenet.com.tw` 格式改回 `admin`，修復 pi-health.yml Invalid user
- Ops: `PI_HOST` 改回 `pi.lifenet.com.tw`，修復 pi-health.yml 連線

## 2026-04-26 — fix(security): 密碼改完撤銷全部 token + CSP Report-Only + debug_mode 監測 (PR #84)

- Security: `PUT /api/v1/me` 改密碼時刪除所有舊 AuthToken，簽發新 token（`new_token` 欄位），其他裝置自動失效
- Security: frontend 收到 `new_token` 自動更新 localStorage，當前工作階段不中斷
- Security: `GET /api/v1/health` 新增 `security.debug_mode` 欄位，可即時確認 production 是否 APP_DEBUG=true
- Security: `SecurityHeaders` middleware 加入 `Content-Security-Policy-Report-Only`，透過 Sentry CSP 端點接收 violation report（Report-Only 不擋截，安全）
- Test: 新增 `PasswordChangeTokenRevokeTest`（4 tests）；修復 `TeacherBulkAccountOnboardingTest` 使用密碼改後的新 token

## 2026-04-26 — chore: 補上最後 4 個工程缺口 (PR #83)

- Security: Composer audit 改為 HIGH/CRITICAL 才擋 CI，MEDIUM/LOW 僅印警告（目前 2 個 medium 不阻斷）
- Security: npm audit `--audit-level=high` 移除 fallback echo，HIGH+ 真正 fail CI
- Added: `GET /api/v1/health` 新增 `sentry.configured` + `sentry.sdk_bound` 欄位，可 API 驗證 Sentry 是否載入
- Changed: App.vue 所有 22 個頁面改為 `defineAsyncComponent` 懶載入，initial JS chunk 從 1176 KB 降至 **137 KB**（-88%）
- Changed: `vite.config.js` 加入 `manualChunks` 分離 vue / sentry vendor 到獨立 chunk，共 38 chunks
- Added: 新增 `RoomControllerTest`（6 tests）、`CampusControllerTest`（3 tests）、`TeacherBranchControllerTest`（5 tests）覆蓋 3 個先前 0% controller；coverage 64.6% → 65.0%

---

## 2026-04-26 — chore: 補上 3 項工程缺口 (#82)
- Changed: Coverage gate 從 warning 改為真正擋關（block < 60%，warn < 70%，目前 64.6%）
- Added: CI 自動生成 API route 文件（`php artisan route:list`）並上傳為 artifact
- Changed: `SENTRY_DSN` secret 更新為正確值；deploy.yml 自動注入 Pi `.env` 的 `SENTRY_LARAVEL_DSN`
- Ops: Branch protection (Gap 1) 確認需 GitHub Pro；local pre-push hook 維持現有保護

## 2026-04-26 — feat: 通知中心 v2 (#81)
- Changed: 類型下拉改為分類 Tab（全部/繳費/評量/刷卡/堂數/系統），各 Tab 顯示未讀計數徽章
- Changed: 「標記已繳費」改為彈出核帳確認 Modal，避免誤觸直接異動 DB
- Added: 「清除已解除」批次操作，將已解除通知標記已讀一鍵清除
- Added: 深度連結擴充 — `low_sessions` → 課程管理，`schedule_change/substitute_confirm` → 行事曆
- Changed: 通知 item 視覺層次強化 — 未讀藍色左邊框、urgent 紅色背景、已解除灰化刪除線
- Added: API 30 天封存過濾 — 已讀且超過 30 天的通知預設不顯示，未讀永遠顯示
- Added: 支援 `schedule_change`、`substitute_confirm`、`low_sessions` 類型 label 與樣式

---

## 2026-04-25 — fix: 家長入口跨家庭學生資料洩漏修復 (#74 #75) [SECURITY]
- Fixed: parent portal showed 7 unrelated students from different campuses as switchable siblings
- Root cause: 2026-04-16 backfill migration copied invalid/shared Student.LineID into student_line_bindings
- Fix 1 (code): filter line_user_id to valid LINE format (U+32hex) in sibling detection (login, switchStudent, dashboard)
- Fix 2 (data): migration to remove invalid-format LINE IDs from student_line_bindings
- Fix 3 (data): migration to remove cross-campus LINE user IDs (impossible for real family groups)

## 2026-04-25 — chore: P1 workflow improvements — PR template, PHPStan, smoke test, CODEOWNERS (#71)
- Added `.github/pull_request_template.md` for structured PR descriptions
- Added `.github/workflows/codeql.yml` with PHPStan level 5 static analysis (CodeQL requires GHAS on private repo)
- Enhanced `deploy.yml` smoke test: verify `/branches`, `/swipe-rfid`, `/auth/login` after deploy
- Added `.github/CODEOWNERS` to auto-request review for high-risk modules

## 2026-04-25 — feat: 學習評量頁家長回饋視覺化 (#67)
- Added orange left-border on unread feedback cards/rows; grey border for read
- Added `💬 未讀回饋` / `💬 家長回饋` chip with click-to-expand inline preview (marks as read)
- List view rows now show colour-coded left-border for feedback state

## 2026-04-25

- Added Sentry 錯誤追蹤整合：前端 @sentry/vue + 後端 sentry-laravel，production exception 自動上報（PR #61）
- Added P1+P2 工程改善：CI 安全掃描（npm audit + composer audit）、bundle size budget、pre-push/pre-commit git hooks、Dependabot（PR #56）
- Fixed 前端 bundle 與 version.json 被 git 追蹤舊版，每次 backend-only deploy 後 git reset 還原舊檔案導致 UI 退化；移除所有 build artifact 出 git、deploy 加 ASSETS_MISSING fallback 強制重 build（PR #55）
- Added 家長回饋系統內通知：側欄 badge + 5 分鐘輪詢、老師/主任首頁待辦卡、評量頁卡片/列表切換（PR #54）
- Fixed 學習評量表 API 全線 500：migration 偵測用 `grep "Pending"` 在 Laravel 8 失效（應為 `| No`），導致 `learning_record_feedbacks` 表未建立；同時加 `Schema::hasTable` guard 防止表不存在時 crash（PR #52）

- Fixed 跨校支援老師在出缺勤頁面提交「回報出入」回傳 403 Forbidden（PR #49）
- Fixed 教學工作台「補填提醒」點擊後無法找到昨日課程：自動切到正確的週並開啟填寫 modal（PR #50）
- Added 家長可在評量表留下回饋，老師與主任可在評量頁查看（PR #51）

## 2026-04-24

- Changed: 強制繳費核帳流程 — 標記已繳費必須填繳款日期（PaymentEntryModal），API 層同步加 422 guard（#48）

## 2026-04-24（深夜 v5）

### Fixed
- 版本標籤顯示「早上 8:00」：`App.vue` 的 `formatBuildTime` 將純日期字串（`YYYY-MM-DD`）傳入 `new Date()` 後被解析成 UTC 午夜，台灣 +8 小時後變 08:00。修復：偵測純日期格式直接回傳，不做 Date 轉換（見 `fix/version-display-timezone`）

## 2026-04-24（深夜 v4）

### Changed
- 版本顯示改回日期格式（`建置 2026-04-24`）：`vite.config.js` 的 `version.json` 輸出由 commit hash 改為 build 日期（`t` 欄位），同時保留 `hash` 欄位供開發者追溯（見 `chore/version-display-date`）

## 2026-04-24（深夜 v3）

### Fixed
- 出缺勤管理頁面一片空白（P0 regression hotfix）：`AttendancePage.vue` 中 `quickForm` ref 初始化時呼叫 `localTodayYmd()`，但 `localTodayYmd` 為 `const` arrow function 且宣告在後面（line 1620）→ JavaScript TDZ ReferenceError → `setup()` 中止 → 整頁空白。修復：將 `localTodayYmd` 宣告移到 `quickForm` 之前（line 1467）。由 PR #41 引入，PR #45 hotfix 修復。

## 2026-04-24（深夜 v2）

### Fixed
- 出勤頁「出缺勤紀錄」管理員預設改顯示最近 7 天（而非只有今天）：`AttendanceController::index` 新增 `start_date`/`end_date` 區間參數，無參數時預設最近 7 天；前端管理員加「最近 7 天 / 今天」快捷切換（見 `fix/attendance-range-view`，bugfix plan `bugfix_attendance_range_view_2026-04-24.md`）

## 2026-04-24（深夜）

### Fixed
- 補課流程三連修：(A) `ScheduleController::store` 補建補課時同步 `ClassSession::firstOrCreate`，出勤與評量頁面可見補課堂次；(B) `submitQuickAttend` 補上 `StudentID` 防 422 靜默失敗；(C) 老師快速點名加日期選擇器（最多回溯 14 天）；出勤頁加日期篩選器，管理員可查詢過去紀錄（見 `fix/makeup-attendance-flow`，bugfix plan `bugfix_makeup_class_session_missing_2026-04-24.md`）

---

## 2026-04-24（晚上）

### Fixed
- Bug 回報：附截圖時回傳 500 → 修 `deploy.yml` 補 `storage:link --force` + `chmod 775`；`BugReportService::attachUploadedFiles` / `AuthController::toAvatarUrl` 加 try-catch 讓存檔失敗降級（不中斷主流程），回傳 201 + `attachment_errors` 欄位（RC-1，見 `fix/bug-attachment-storage-500`）
- 家長入口評量：科目名稱顯示不一致（有的顯示 `English`、有的顯示 `英文課`）→ `ParentPortalController::resolveSubjectName` 邏輯修正：(1) 有課程科目且非 '課程' 時優先用課程名稱；(2) 課程無科目時對 `LearningRecord.Subject` 原始值套 `mapSubjectLabel`；(3) `mapSubjectLabel` 補中文別名（`英文課→英文`、`數學課→數學` 等）（PR #39 — 見 R11）

---

## 2026-04-24（下午）

### Fixed
- 家長入口：登入驗證改讀 `parent_phone`（UI「家長手機」欄），舊 `Phone` 欄 fallback 相容（PR #38 — 見 R10）

---

## 2026-04-24

### Security / Ops
- CI/CD: 移除 `ci.yml` 中的明碼 DB 密碼，改用 GitHub Secret `CI_DB_PASSWORD`（FR-007）
- CI/CD: `deploy.yml` 移除 `StrictHostKeyChecking=no`（`ssh-keyscan` 已在 Setup SSH 建立 known_hosts）（FR-008）

### Fixed
- CI/CD: `deploy.yml` 將 `php artisan optimize:clear` 改為 `config:cache && route:cache`，消除部署時短暫快取空白期（FR-004）

### Added
- CI/CD: `deploy.yml` 新增自動 rollback 機制—health check 失敗時自動執行 `git reset --hard`、前端重 build、快取重建、migration rollback（若有），並二次確認 health check（FR-005/006）
- CI/CD: `scripts/git-sync.sh` 加入 main branch 守門，在 main 執行時直接 abort 並提示建 feature branch（FR-009）
- CI/CD: `scripts/git-sync.sh` push 後自動執行 `gh pr create --fill --draft`（FR-010）

### Changed
- Docs: `README.md` GitHub 同步工作流程更新為 feature branch → PR → CI → merge 流程，移除過期的 `jerry-sync-main` 說明（FR-011）

### Ops（CI/CD 部署通道修復，同日下午）
- Pi: `StrictModes no` 加入 `/etc/ssh/sshd_config`（根因：`/home/admin` 為 `admin:www-data 775`，SSH 拒絕 public key — 見 R7）
- Pi: fail2ban unban 9 個 GitHub Actions runner IP + 永久白名單 GitHub Actions IP 範圍（`jail.local`）
- `deploy.yml`: `git pull` 改為 `git fetch origin main && git reset --hard origin/main`（根因：Pi 本地 nightly auto-commit 造成 divergent branches — 見 R9）
- `deploy.yml`: 移除 `composer install` 的 `--no-dev` flag（根因：Pi vendor 有舊 dev 安裝，--no-dev 造成 Collision class not found → health 500 — 見 R8）
- `deploy.yml`: `bootstrap/cache/packages.php` 部署前清除（搭配 R8 修復，已不需要，但保留作保險）
- **首次端對端自動部署成功**：`push → CI → deploy → health ok` 全流程驗證通過（2026-04-24 14:17 TWN）
- `deploy.yml`: `git diff` 偵測 `frontend/` 有無變動，無變動跳過 `npm run deploy`（docs/backend-only commit 不觸發用戶更新通知）
- `vite.config.js`: `version.json` 版本識別碼從 build 時間戳改為 git commit hash（相同 commit 多次 build 結果穩定）

---

## 2026-04-23

### Fixed (cont.)
- Tests: 7 個 time-sensitive tests 加入 `Carbon::setTestNow(today 10:00)` 修復午夜跨日 flaky（CI 22:00+ TWN 後 EndTime 變 "01:xx" 導致 session 窗口失敗）（PR #36）

### Fixed (cont.)
- Teacher attendance: 補卡後主表 `SignInDT`/`SignOutDT` 同步更新，前端不再顯示「未簽退」；`unclosed` 清單也正確排除已補卡記錄（PR #35）
- Teacher attendance: super_admin 傳入 `campus_id` 時現在會過濾至指定分校（不傳則維持看全部）（PR #35）

### Security / Fixed
- Teacher attendance: `index`/`unclosed`/`export`/`exportMonthly` 四個 API 加入 `campus_id` 參數隔離，修復多分校 director 可看到其他分校老師出勤記錄的越界問題（PR #34）
- Teacher attendance: `auth_campus_ids` 為空的非 super_admin 用戶改回 403，防止 bypass 全分校過濾（PR #34）

### Added
- Teacher attendance: `teacher-signin:close-orphans` 每日 00:05 自動補登前日未簽退記錄（SignOutDT=23:59, Status=adjusted, Memo=系統自動補登簽退）（PR #25）
- Migration: `TeacherSingIn.Memo varchar(512)` 補齊 migration 記錄（欄位本已存在 prod DB）（PR #25）

### Fixed
- Swipe: RFID 刷卡後同步 `ClassSession.Status`（attended/late），修復老師「待點名」計數虛高（PR #23）
- Swipe: `TeacherID=NULL` fallback 查詢，防止刷卡記錄從老師出缺勤視角消失（PR #23）
- Data patch: `StudentSingIn.id=945`（游家豫）`TeacherID` NULL→17（PR #23 前的歷史資料）
- Teacher attendance: 行政出勤狀態誤顯示「系統待確認」，backfill migration 修復（PR #19）
- TD-004: `findMatchingClass` 排除 `Status=leave` 的 ClassSession（PR #19）
- TD-006: 刷卡 60s debounce，防 RF bounce 秒速簽退（PR #19）
- TD-007: sign_in 前先查 ClassSession 是否已有記錄，重複刷回傳 `duplicate_ignored`（PR #19）
- TD-009: `backfillPresenceWindow` 加 EndTime null guard（PR #19）
- RFID: 同分校同卡不再靜默覆蓋，回傳 422（composite unique index）（PR #18）

### Added
- `AttendanceEffectsService`: ClassSession 狀態解析共用 Service（resolveSwipeStatus + applySessionStatus guard）（PR #23）
- 老師月報 XLSX 匯出：每位老師獨立 Sheet，左流水/右月曆格式（PR #19）
- `DELETE /attendance/{id}`: 軟刪除出缺勤記錄，自動沖回扣堂（PR #17）
- `POST /attendance/{id}/convert-to-attended`: 自修記錄轉到班（PR #17）
- TD-008: `CloseOrphanStudentSignIns` 每日 02:30 自動關閉孤兒記錄（PR #19）
- `docs/SYSTEM_TECH_GUIDE.md`: 後端技術實作索引（Identity/Swipe/ClassSession/Service 職責）

### Changed
- TD-011: `findMatchingClass` 窗口從 ±30min 改為 `(StartTime-30min) ≤ swipeAt ≤ EndTime`（PR #19）

### UI
- 教學工作台打卡狀態卡片：手機雙 chip 並排 + 彩色左邊框 + skeleton（PR #22）
- 出缺勤頁：自修記錄橘色 badge + 自修篩選器 + 刪除 Dialog + 轉換 Modal（PR #17）

---

## 2026-04-22

### Fixed
- `StudentsList`: 方案課程剩餘堂數 mapper 遺漏 `PackageID` 等欄位，顯示錯誤（§FRONTEND-005）
- 代課後調課：`schedules` 表未同步，代課老師顯示原老師（Issue #3）
- `directors/pending`: 排除被誤標為主任的教師帳號（Issue #6）
- `retroLeave`: 補請假重複 INSERT `StudentSignIn` 導致 500，改 `updateOrCreate`（Issue #2）

### Security
- Route throttle: `auth/register` 10/10min、`forgot-password` 5/60min、`swipe-rfid` 30/1min（SEC-002/003/006）
- 密碼最低長度全部入口統一 `min:8`（SEC-004）
- HTTP 安全標頭：HSTS / X-Frame-Options / nosniff / Referrer-Policy / Permissions-Policy（SEC-005）

### Ops
- 備份: KEEP=12（3 天）、nightly 統計告警、gdrive-sync sixhour 異地快照
- CI: `bootstrap.php` 加 `DB_DATABASE=AllTrue` 斷路器，防測試誤操作 production
- 月度還原演練 `monthly-restore-drill.sh`（每月 1 日 02:00，四表 row count 比對）

---

## 2026-04-21

### Fixed
- b7: 試聽容量誤判（`one_on_three` 被算入試聽名額）+ OPcache 陳舊導致調課失敗
- B1: 代點名代課可見性復發（nightly auto-backup 意外覆蓋修復 commit）
- C1: 代課後單堂顯示原老師（`start_time HH:MM:SS` 格式容錯，SUBSTRING 雙側比對）
- b5: 歷史堂數制課程 Charge 欄位錯誤（純資料修正，StudentClass ID=171，24000→12000）
- b3: 月結制課程無法進入歷史課程（`effectiveClosedReason` 月結分支補齊）
- b4: 月結制加購錯誤變堂數制（`renewMonthly` API + 前端分流）
- 繳費狀態切換未清除 `paid_at`（+ `SessionDeductionService` 移除誤清 `Paid=0` 邏輯）

### Added
- b6: 課表回報管理頁 30s 輪詢 + Nav Badge 每 60s 更新
- `opcache-reset` 部署端點（`X-Deploy-Secret` 驗證），`npm run deploy` 自動觸發
- git-sync `CODE_REVERT_GUARD`（controllers/migrations/tests 路徑淨刪除 ≥30 行時 exit 1）
- 備份失敗 EXIT Trap → Telegram 告警（nightly + sixhour）

---

## 2026-04-20

### Fixed
- ClassSession 時間異動未同步 `schedules` exception（`syncScheduleExceptionTime`，16 筆歷史 drift 修復）
- 排課例外在無 ClassSession 時靜默寫入孤兒記錄（`no_class_session` 422 防護）
- 代課假陽性衝堂：更換代課老師時舊 `scheduled` 列被計入（`exclude_schedule_id`）
- 試聽課型容量守衛誤判（trial 豁免分支，不影響正式課型）
- 學生備註清除仍顯示舊值（`isset` → `array_key_exists` + Supabase mirror 補同步）

### Added
- 代課老師容量標籤三態：有空 ✓ / 尚有容量 ⚠ / 已滿 ✗（後端 `remaining_capacity` 欄位）
- 多科共用方案建立時支援設定排課星期+時間（`createMultiSubject` 正式啟用）
- 方案管理頁：成員格排課狀態 tag + 健康度 badge + 不完整方案統計列
- `PUT /course-packages/{id}` 支援 `total_sessions`，全成員自動同步補排/取消

---

## 2026-04-19 以前

→ 見 [CHANGELOG_ARCHIVE_2026-04.md](CHANGELOG_ARCHIVE_2026-04.md)

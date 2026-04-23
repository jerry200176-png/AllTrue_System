---
name: DB Optimization PRD
overview: 為 AllTrue 在樹莓派環境制定完整資料庫效能優化 PRD，採分階段推進：P0 先完成索引與查詢優化，P1 再導入讀寫分離與連線池/代理，並納入 QA、資安、維運與簽核流程。
todos:
  - id: baseline-capture
    content: "[IT/Ops] 【必須最先執行】在任何程式改動前，建立效能基線快照：開啟 MySQL slow query log、記錄各熱點 API 的 P95 現況、對核心表執行 EXPLAIN ANALYZE，作為 KPI 驗收的對照基準。"
    status: completed
  - id: replica-feasibility
    content: "[IT/Ops] P1 前置確認：評估 Raspberry Pi 設備是否支援 MySQL replica 佈署，包含 SD 卡寫入壽命（replica sync 高寫入會加速磨損）、記憶體與 CPU 額外負擔；若硬體不支援，向 PM 提出替代方案（如只做 app-level 連線池，不做 read replica）。"
    status: completed
  - id: db-backup
    content: "[IT/Ops] 每次 migration 上線前，先執行完整資料庫備份，確認備份可還原後才開始套用索引 migration。"
    status: completed
  - id: backend-api-data
    content: "[FEATURE] 後端 API/資料層：（P0）補齊核心表索引（詳見 PRD 第 8 節索引候選清單）、重構熱點查詢（N+1、分頁、全表掃描）；（P1）完成讀寫分離 Laravel 設定、sticky 策略、連線池/代理落地、降級開關。"
    status: completed
  - id: frontend-ui
    content: "[FEATURE] 前端 UI：本次不適用，原因：本案以後端查詢性能與資料庫架構優化為主，不新增前端功能；僅驗證既有頁面性能體感。"
    status: completed
  - id: test-design
    content: "[TEST] 測試設計：建立 FR 對應的性能測試、回歸測試與壓測腳本，含 P95、慢查詢、讀寫一致性驗證；FR-004 需包含基線對比報告驗證。"
    status: completed
  - id: qa-validation
    content: "[QA] QA 驗收：執行 PRD 第 10 節全部案例（Happy/Edge/Error/Regression），提交驗收報告與阻擋清單；必須對照基線快照確認 KPI 達標。"
    status: completed
  - id: security-review
    content: "[REVIEW]/資安：確認 role 與 campus 隔離不被破壞，完成 STRIDE 快評與敏感資料日志檢查；確認 replica 帳號最小權限設定。"
    status: completed
  - id: code-review
    content: "[REVIEW] Code Review：審查 migration、查詢重構、讀寫切流與降級策略，確保可維護性與一致性；重點審查一致性敏感路徑（付款、扣堂）是否正確走 primary/sticky。"
    status: completed
  - id: docs-update
    content: "[DOCS] 文件更新：更新 docs/CHANGELOG.md、效能優化 runbook（含基線量測 SOP）、讀寫分離與回滾 SOP、維護窗口操作說明。"
    status: completed
  - id: deploy-go-live
    content: "[IT/Ops] 部署與上線：按維護窗口逐階段執行 migration/配置變更，每階段觀測 24~48 小時後再進行下一階段，必要時啟動回滾。"
    status: completed
  - id: cto-signoff
    content: "[CTO/工程 Lead] CTO/工程 Lead sign-off：確認技術實作符合架構決策、KPI 達標、無高風險殘留項，簽核後由 Draft 轉 Approved。"
    status: completed
  - id: pm-signoff
    content: "[PM] PM sign-off：確認 DoD 全部勾選完成（含 CTO 簽核），並核准由 Draft 轉為 Approved。"
    status: completed
isProject: false
---

# AllTrue 資料庫性能優化 PRD

## 1. 文件資訊
- 功能名稱：AllTrue Database Optimization（樹莓派環境）
- 版本 / 日期：v1.0 / 2026-04-16
- 狀態：Draft
- 目標角色：主任、老師、家長（間接受益）；工程、QA、資安、IT/Ops（直接執行）

## 2. 目標與業務背景
- 痛點
  - 目前資料量非大數據，但在 Raspberry Pi 的磁碟 IO 與 CPU 條件下，查詢若走全表掃描會造成頁面明顯卡頓。
  - 核心舊表（如 `Student`、`StudentClass`、`Invoice`、`Payment`）索引覆蓋不完整，且部分查詢存在可再優化空間。
  - 單資料庫連線模型在尖峰時段對延遲與可用性不夠友善。
- 業務價值
  - 提升主任/老師日常操作流暢度，縮短查詢等待時間，降低「系統卡住」感受。
  - 降低高峰時段 API 超時與慢查詢機率，提升系統穩定性與可預測性。
- 成功指標（KPI）
  - `GET /api/v1/students` P95 < 400ms（同分校、常用篩選）
  - `GET /api/v1/student-classes` P95 < 600ms
  - `GET /api/v1/class-sessions` P95 < 700ms
  - 慢查詢日誌（>800ms）數量較基線下降至少 60%
  - 讀寫分離上線後，讀請求 70% 以上可路由至 read side（P1）

## 3. 範圍
- In Scope
  - 建立與補齊核心熱點欄位索引（複合索引優先）。
  - 熱點查詢重構（分頁預設、避免 N+1、減少全表掃描、降低不必要排序成本）。
  - 讀寫分離方案設計與導入（Laravel `read/write/sticky` + DB 拓樸與切流規則）。
  - 連線池/連線代理方案導入（以 MySQL 路線為主，評估 ProxySQL/中介層）。
  - 監控、告警、壓測與回滾機制。
- Out of Scope
  - 大規模資料庫引擎轉換（如 MySQL->PostgreSQL）。
  - 核心商業規則改寫（堂數扣除、繳費規則本身不變）。
  - 前端功能新增（僅允許必要的查詢參數優化，不做產品行為改版）。

## 4. RACI
- PM：A（需求定義、優先級、DoD 驗收）
- CTO / 工程 Lead：R（技術決策與實作排程）
- QA：R（測試設計與驗收執行）
- 資安：C（存取控制、風險評估、稽核需求）
- IT / Ops：I（部署、監控、回滾執行）

## 5. User Stories
- As a 主任, I want 學生清單與查詢快速回應, so that 我可以在櫃檯尖峰時段快速處理報名與查詢。
  - Acceptance Criteria
  - [ ] 在同分校、姓名/狀態篩選下，學生列表在 P95 < 400ms。
  - [ ] 分頁翻頁不出現明顯卡頓（主觀體感 < 1 秒）。
- As a 老師, I want 課程與堂次列表即時載入, so that 我可快速完成點名與評量作業。
  - Acceptance Criteria
  - [ ] 課程與堂次 API 在高峰時段維持 P95 目標內。
  - [ ] 不因優化造成跨校區資料混入。
- As an IT/Ops, I want 可觀測且可回滾的效能優化方案, so that 發生異常時能快速復原。
  - Acceptance Criteria
  - [ ] 每階段都有明確 rollback runbook。
  - [ ] 監控指標可分辨 query 退化、連線耗盡與複本延遲問題。

## 6. 功能需求（FR）
- FR-000：系統應在任何優化改動前，建立可測量的效能基線快照（至少包含：各熱點 API P95 現況、MySQL slow query log 統計、核心表 EXPLAIN ANALYZE 結果），以供 KPI 驗收對照。
- FR-001：系統應為核心熱點查詢欄位建立索引，至少涵蓋 `CampusID`、`StudentID`、`TeacherID`、`Status`、`SessionDate`、`RFID` 與關鍵複合條件。
- FR-002：系統應對熱點 API 查詢採分頁與合理預設上限，避免一次抓取大量資料造成 IO 壓力。
- FR-003：系統應消除可識別的 N+1 查詢，並以 join/eager loading/批次查詢方式替代。
- FR-004：系統應提供索引變更前後的效能比較報告（基線、壓測、P95、慢查詢數）。
- FR-005：系統應支援讀寫分離配置，寫入走 primary、讀取走 replica，並具備 sticky/read-after-write 保證。
- FR-006：系統應導入連線池或連線代理，降低頻繁連線建立與銷毀成本。
- FR-007：系統應在複本延遲超標時自動降級為 primary-only 讀取策略。
- FR-008：系統應保留校區隔離與角色授權控制，不得因效能調整破壞資料邊界。

## 7. 非功能需求（NFR）
- 效能
  - API P95 目標如第 2 節 KPI。
  - 尖峰 5 分鐘窗口內，error rate < 1%。
- 可用性
  - P1 切換期間不允許核心流程停機超過 5 分鐘（計畫性維護除外）。
- 觀測性
  - 需具備慢查詢、連線數、複本延遲、查詢錯誤率儀表板。
- 錯誤處理與降級
  - replica 異常時，讀流量可切回 primary；保留功能正確性優先於性能。

## 8. 技術方向（給 CTO，非實作細節）
- 受影響頁面
  - `StudentsList.vue`
  - `CourseManagement.vue`
  - `LearningRecordsPage.vue`
  - `AttendancePage.vue`
  - `DirectorDashboard.vue`
- 受影響 API 路徑
  - `/api/v1/students`
  - `/api/v1/student-classes`
  - `/api/v1/class-sessions`
  - `/api/v1/learning-records`
  - `/api/v1/attendance`
  - `/api/v1/alerts/tuition`（僅性能觀測與查詢效率，不改商業規則）
- 受影響資料表
  - `Student`
  - `StudentClass`
  - `ClassSession`
  - `StudentSingIn`
  - `LearningRecord`
  - `Invoice`
  - `Payment`
  - `UserCampus`
- 架構取捨
  - P0 優先索引與查詢重構：改動風險較低、回報最快、可快速改善樹莓派體感延遲。
  - P1 導入讀寫分離與連線池：可進一步擴展可用性與尖峰承載，但需增加維運複雜度。
  - 以「正確性先於性能」為原則，對付款/扣堂等一致性敏感查詢保留 primary 或 sticky 策略。
- 索引候選清單（供 `[FEATURE]` Agent 參考，至少涵蓋以下組合）
  - `Student`：`(CampusID)`、`(CampusID, name)`、`(CampusID, status)`、`(RFID)`
  - `StudentClass`：`(StudentID)`、`(TeacherID)`、`(CampusID)` [TODO: 需確認是否有 CampusID 欄位或以 join Student 取代]、`(Stop, StudentID)`
  - `ClassSession`：`(StudentClassID, SessionDate)`（已有 migration，確認已套用）、`(Status)`
  - `StudentSingIn`：`(StudentID)`、`(StudentClassID)`、`(SignInDT)`
  - `Invoice`：`(StudentID)`、`(StudentClassID)`、`(Status)`
  - `Payment`：`(InvoiceID)`
  - `UserCampus`：`(UserID)`、`(CampusID, Approved)`
- 已知 N+1 熱點提示（供 `[FEATURE]` Agent 優先處理）
  - `StudentController::activeCourses`（`backend/app/Http/Controllers/StudentController.php` line 124）：在迴圈內對每筆 `StudentClass` 執行逐筆 `DB::table('Subject')->where('id', ...)->value(...)` 查詢，應改為批次查詢或 eager loading。
- Migration 需求
  - 需要新增多支索引 migration，並按低風險時段分批上線。
  - 每次 migration 前須完成資料庫備份。
- 子任務 Agent 派發
  - `[FEATURE]`：索引與查詢優化、讀寫分離與連線池配置落地。
  - `[TEST]`：效能回歸測試、壓測腳本、P95 驗收案例。
  - `[REVIEW]`：權限與校區隔離、資料一致性、效能策略審查。
  - `[DOCS]`：維運手冊、變更紀錄、回滾 SOP。

## 9. 資安與存取控制（給資安 / IT）
- 角色與存取
  - 維持既有 `role:*` 與 `require_campus` 控制，不得因查詢重構繞過授權。
- PII / 敏感資料
  - `Student.name`、`Phone`、`parent_phone` 屬 PII；log 與效能報表需避免明文暴露。
- 稽核 log
  - 應記錄配置切換（讀寫分離開關、降級切換、連線池策略變更）與操作者。
- STRIDE 快評
  - Spoofing：需限制 DBA/維運憑證與來源。
  - Tampering：migration 與配置變更需審批與可追蹤。
  - Repudiation：保留操作審計紀錄。
  - Information Disclosure：禁在 debug/perf log 輸出敏感欄位。
  - Denial of Service：需有連線上限、逾時與熔斷策略。
  - Elevation of Privilege：確保 replica 帳號最小權限。

## 10. QA 驗收標準與測試計畫
- FR-000（基線快照）
  - Happy Path：基線量測完成，各 API P95 已記錄，slow query log 輸出可讀。
  - Error Case：若基線量測失敗（log 無資料），禁止繼續後續優化改動。
- FR-001/FR-002/FR-003（索引與查詢）
  - Happy Path：常見分校篩選、姓名搜尋、課程列表、堂次列表均達 P95，且 P95 較基線改善。
  - Edge Case：無資料分校、大分校（數百學生）、跨月查詢、複合篩選、`orderBy(name)` 排序是否走索引。
  - Error Case：索引建立中斷、查詢 fallback。
  - Regression：對照 `docs/AI_REGRESSION_LESSONS.md`，確認不破壞既有業務流程。
- FR-004（效能比較報告）
  - Happy Path：可提供「優化前 vs 優化後」P95、慢查詢數量、EXPLAIN ANALYZE 計畫對比。
  - Error Case：若報告無法顯示改善，需阻擋上線並提交根因分析。
- FR-005/FR-006/FR-007（讀寫分離與連線池）
  - Happy Path：讀請求正確路由至 replica，寫入後可讀一致性符合 sticky 規則。
  - Edge Case：replica 延遲升高、replica 不可用、連線池耗盡。
  - Error Case：自動降級到 primary-only，功能仍正確。
  - Regression：繳費、出缺勤、評量審核等一致性敏感流程無誤。
- FR-008（存取控制）
  - Happy Path：同分校查詢正確。
  - Edge Case：多校帳號切換與授權邊界。
  - Error Case：越權查詢應回 403，不洩漏資料。

## 11. 上線與維運（給 IT / Ops）
- 維護窗口
  - 定義：每次 migration/配置變更應在非上課尖峰時段執行（建議平日 22:00 後或週日）。
  - 操作前通知主任，告知預計停機/影響時間窗口（< 5 分鐘）。
- 部署步驟
  - Phase P0
  - 執行完整資料庫備份，確認備份可還原。
  - 建立基線監控與慢查詢統計（FR-000）。
  - 逐批上線索引 migration（離峰、可回退），每批觀察 24 小時。
  - 上線熱點查詢優化，觀察 24~48 小時，確認 KPI 達標後進入 P1。
  - Phase P1（前提：IT/Ops 確認 RPi 硬體可行性）
  - 執行完整資料庫備份。
  - 建立 replica 與健康檢查腳本。
  - 啟用 app 讀寫分離設定與 sticky。
  - 導入連線池/代理並設定連線上限、逾時、重試。
- 監控新增項目
  - DB 連線數、慢查詢數、replica 延遲、API P95、5xx rate。
- 回滾方案
  - P0：逐一 rollback 索引 migration 或停用新查詢路徑。
  - P1：關閉讀寫分離切回單主；停用連線代理改直連。

## 12. 里程碑與優先級
- P0（Must Have）
  - 索引補齊與查詢重構、效能基線與回歸報告。
  - 預估工期：5~8 個工作天。
  - 執行 Agent：`[FEATURE]`、`[TEST]`、`[REVIEW]`、`[DOCS]`。
- P1（Should Have）
  - 讀寫分離與 sticky、連線池/代理、降級策略。
  - 預估工期：7~12 個工作天（含壓測與灰度）。
  - 執行 Agent：`[FEATURE]`、`[TEST]`、`[REVIEW]`、IT/Ops。
- P2（Nice to Have）
  - 自動化容量預測與定期索引健康檢查報表。
  - 預估工期：3~5 個工作天。
  - 執行 Agent：`[DOCS]`、IT/Ops。

## 13. 風險、假設、開放問題
- 風險
  - 高：讀寫分離造成讀到舊資料，影響付款/扣堂流程。
  - 高：RPi SD 卡寫入壽命風險——replica sync 會大幅增加寫入次數，加速 SD 卡磨損，可能導致儲存媒體提早失效；P1 執行前須評估替代儲存方案（如 SSD、外接硬碟）。
  - 中：新增索引可能增加寫入成本（INSERT/UPDATE 時維護索引成本）。
  - 中：連線代理配置不當導致可用性下降。
  - 低：P0 索引優化後仍未達 KPI——若 P0 完成後 P95 改善未達 60%，應執行根因分析，評估是否需提前引入 P1 架構或放寬 KPI 目標。
- 緩解
  - 一致性敏感流程採 sticky 或 primary read。
  - 索引分批上線並觀測寫入延遲。
  - 先灰度再全量，保留快速切回機制。
  - P1 執行前確認 RPi 儲存媒體壽命與備份策略。
- 假設
  - 現有 MySQL 可支援 replica 佈署（RPi 硬體需 IT/Ops 先確認）。
  - 現場網路與設備允許新增代理元件。
  - SD 卡或儲存媒體的健康狀態足以承擔 P1 的額外寫入負載。
- 開放問題
  - [TODO: 需確認] RPi 儲存媒體類型與剩餘壽命評估，Owner：IT/Ops。
  - [TODO: 需確認] P1 連線池方案最終選型（ProxySQL / app-level persistent PDO / 其他），Owner：CTO。
  - [TODO: 需確認] replica 一致性 SLA（可接受延遲毫秒數），Owner：CTO + PM。
  - [TODO: 需確認] `StudentClass` 是否有 `CampusID` 欄位或需 join `Student` 取得，影響複合索引設計，Owner：`[FEATURE]` 工程師。

## 14. Definition of Done
- [ ] FR-000：效能基線快照已建立並歸檔。
- [ ] 所有 FR（FR-001 ~ FR-008）通過 QA 驗收。
- [ ] FR-004：效能比較報告已提交，P95 較基線改善且達 KPI。
- [ ] 資安審查無阻擋項（replica 帳號最小權限、PII log 審查）。
- [ ] 上線後 API health 與性能指標達標。
- [ ] migration 前備份已完成，備份可還原已驗證。
- [ ] 回滾 SOP 已撰寫並由 IT/Ops 演練。
- [ ] `docs/CHANGELOG.md` 更新。
- [ ] CTO / 工程 Lead sign-off。
- [ ] PM sign-off。
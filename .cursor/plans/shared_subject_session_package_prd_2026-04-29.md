# 多科共用堂數方案 PRD

## 0. 根因 / 背景脈絡

主任目前建立學生課程時，若學生同時報名兩科以上，系統日常入口傾向拆成多筆 `StudentClass` 契約。部分分校主任的實務需求不是「每科各有一包堂數」，而是「學生買一包 8 堂，數學、英文、自然等科目共同消耗同一包剩餘堂數」。AllTrue 先前已建立 `CoursePackage` / `PackageDeductionService` / `package_session_ledger` 的共用方案核心，但 2026-04-27 因 R24「多科固定時段優先走一般課程」將入口降噪為 legacy 維護能力，導致主任日常建立時不容易理解或使用。

本次不是從零建立新模型，而是把既有 `CoursePackage` 能力重新產品化：入口與文案清楚區分「一般多科課程」與「多科共用堂數方案」，並補齊續報加購與舊資料綁定規則。

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 多科共用堂數方案重新產品化 |
| 文件版本 | v0.1 |
| 日期 | 2026-04-29 |
| 狀態 | PLAN 草案 |
| Risk Tier | T3 safety-critical（堂數扣除 + 繳費 + 分校資料） |
| 目標角色 | 分校主任、行政、super_admin |
| 主要模組 | 課程管理、學生管理、多科共用方案、堂數扣除、繳費提醒 |

## 2. 目標與業務背景

### 痛點

- 主任遇到「同一學生報兩科以上，共用一包 8 堂」時，現行一般課程入口會自然拆成多筆契約，主任要分別看每科剩餘堂數，與實務收費口徑不一致。
- 既有方案能力還在，但入口與中文說明不夠清楚，主任容易不知道何時該用。
- 續報加購若仍從單科課程進入，容易讓主任誤以為只加購某一科，而非整包方案。

### 業務價值

- 主任可用一筆方案管理多科總堂數，降低人工解釋與改堂數成本。
- 各科仍保留老師、排課、評量與出勤紀錄，避免把教學流程混成不可管理的一筆課。
- 堂數池與 ledger 可追溯，降低剩餘堂數爭議。

### KPI

- 建立多科共用方案時，主任能在 1 次流程內完成學生、總堂數、科目/老師/時段設定。
- 方案課程在課程管理與學生管理的剩餘堂數 100% 顯示同一方案池數字。
- 方案課程續報加購 100% 更新 `CoursePackage.total_sessions`，不可建立單科新批次。
- 回歸測試覆蓋建立、扣堂、加購、舊資料綁定 dry-run、分校隔離。

## 3. 範圍

### In Scope

- 恢復或強化「多科共用堂數方案」入口，但定位為進階選項。
- 中文說明明確區分：
  - 一般多科課程：同一學生多科、多時段，但每科各自管理堂數。
  - 多科共用堂數方案：多科共用同一包總堂數。
- 方案建立流程支援堂數制共用池。
- `PackageID` 課程的續報加購走方案層更新總堂數。
- 已拆成多筆契約的舊資料，提供受控「綁定到方案」流程，先 dry-run 再 apply。
- 課程管理 / 學生管理 / 編輯課程顯示方案池剩餘與不可直接改單科剩餘的原因。
- 測試、CHANGELOG、必要 SOP / regression 文件更新。

### Out of Scope

- 不把多科真正合併成單一 `StudentClass` row。
- 不重寫出勤、評量、排課資料模型。
- 不修改主任繳費提醒既有規則，除非明確是 `CoursePackage` 方案層顯示缺口。
- 不一次處理月結多科方案的完整重設計；本次主軸是堂數制共用池。
- 不直接批次轉換 production 舊資料；舊資料綁定需由 UI/API 受控操作。

## 4. RACI

| 角色 | 代表 | R/A/C/I |
|---|---|---|
| AI Agent（Product / PLAN） | `[PLAN]` Agent | R |
| AI Agent（架構） | `[ARCH]` Agent | R |
| AI Agent（實作） | `[FEATURE]` Agent | R |
| AI Agent（測試） | `[TEST]` Agent | R |
| AI Agent（資安審查） | `[SEC]` Agent | R |
| AI Agent（Code Review） | `[REVIEW]` Agent | R |
| AI Agent（文件） | `[DOCS]` Agent | R |
| AI Agent（部署） | `[OPS]` Agent | R |
| 人類（可閱讀） | 使用者 / 分校主任 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|---|---|---|
| 既有後端能力 | `CoursePackageController::createMultiSubject`、`CoursePackageController::update`、`bindCourses`、`PackageDeductionService` | 已存在，需 ARCH 複核 |
| 既有前端能力 | `UniversalClassScheduler`、`CoursePackagesPage`、`CourseManagement`、`StudentsList` 已有部分方案 UI | 已存在，需重新產品化 |
| DB schema | `course_packages`、`package_session_ledger`、`StudentClass.PackageID` | 已存在，預期不需 migration；ARCH 需確認 |
| 外部服務 | 無第三方 API 依賴 | 無 |
| 風險文件 | R24 禁止把多科方案與一般課程並列推薦 | 已存在，需同步更新或補充例外條件 |

## 5. User Stories + Acceptance Criteria

### US-001：主任建立多科共用堂數方案

As a 分校主任, I want to create one shared package for multiple subjects, so that I can manage one remaining-session pool for a student.

AC:
- Given 主任在課程管理新增課程, when 選擇「多科共用堂數方案」, then 頁面顯示共用堂數說明與適用情境。
- Given 主任輸入總堂數 8 並新增 2 科以上, when 送出, then 系統建立 1 筆 `CoursePackage` 與多筆 `StudentClass` 成員。
- Given 任一成員被點名出席, when 扣堂完成, then 方案剩餘堂數減 1，所有成員顯示同一剩餘堂數。

### US-002：主任知道何時不該用共用方案

As a 分校主任, I want clear guidance, so that I do not misuse shared packages for normal independent subjects.

AC:
- Given 主任打開新增課程, when 看見選項, then 一般課程為預設，且多科共用方案標示為「進階」。
- Given 主任只需要多個固定時段但堂數分開, then 說明文字引導使用一般課程。
- Given 主任需要國英數共用 8 堂, then 說明文字引導使用共用方案。

### US-003：方案續報加購

As a 分校主任, I want renewal/add-on from a package member to update the whole package, so that all subjects share the new sessions.

AC:
- Given 課程有 `PackageID`, when 主任按「續報加購」, then modal 顯示「加購會增加整個方案總堂數」。
- Given 主任加購 8 堂, when 儲存, then `CoursePackage.total_sessions` 增加 8，`remaining_sessions` 依 ledger 重算。
- Given 加購完成, then 所有同方案成員顯示新方案總堂數與剩餘堂數。

### US-004：舊資料綁定

As a 分校主任, I want to bind existing split contracts into a shared package, so that old students can use the new workflow without data loss.

AC:
- Given 選取同學生、同分校、堂數制、未停用或可轉換的多筆課程, when dry-run, then 系統回傳將綁定的課程、目前剩餘堂數、方案初始總堂數建議。
- Given dry-run 有跨分校、不同學生、月結或已屬其他方案, then apply 被拒絕並說明原因。
- Given apply 成功, then 課程只新增 `PackageID` 關聯，不刪除歷史課程與出勤資料。

## 5b. UI/UX 精緻化

### `UniversalClassScheduler` / 新增課程入口

| 面向 | 規格 |
|---|---|
| 版面層次 | 預設顯示「一般課程」主 CTA；「多科共用堂數方案」放在進階卡片，使用較次要樣式但清楚可點 |
| 色彩一致性 | 共用方案 badge 沿用紫色 `tag-package`；警示說明用 warning 色，不用 danger |
| 互動回饋 | 選擇方案後顯示 3 行摘要：共用總堂數、每科仍獨立排課、任一科上課都扣同一包 |
| 空狀態設計 | 尚未新增科目時顯示「至少新增 2 科，或回到一般課程建立單科/獨立堂數」 |
| 載入狀態 | 建立送出按鈕顯示 spinner，送出期間 disabled |
| 防呆設計 | 必填欄位：學生、方案名稱、總堂數、至少 2 科；錯誤用 inline 正向文字 |
| 響應式 | Modal 在窄螢幕卡片垂直堆疊，科目列可折疊 |
| 無障礙 | 選項卡可 keyboard focus；按鈕 aria-label 說明「建立共用堂數方案」 |

### `StudentsList` / `CourseManagement` 方案顯示

| 面向 | 規格 |
|---|---|
| 版面層次 | 方案成員列顯示「方案」badge 與「方案池剩餘 x / y 堂」 |
| 色彩一致性 | 剩餘 <= 2 堂沿用現有低堂數警示；方案 badge 不取代科目 tag |
| 互動回饋 | 點「續報加購」先開方案加購 modal，標題含方案名稱 |
| 空狀態設計 | 若方案資料缺失，顯示「此課程標記為方案成員，但方案資料載入失敗，請重新整理」 |
| 載入狀態 | 查詢方案明細時顯示 skeleton 或 spinner |
| 防呆設計 | 方案成員編輯頁停用單科剩餘堂數欄位，旁邊說明「由方案池管理」 |
| 響應式 | 手機版保留方案剩餘堂數，不因欄位不足被隱藏 |
| 無障礙 | 方案 badge title 同步 aria-label，不只依顏色辨識 |

### `CoursePackagesPage` / 方案管理

| 面向 | 規格 |
|---|---|
| 版面層次 | 卡片頂部顯示學生、方案名稱、剩餘/總堂數；成員科目列表在下方 |
| 色彩一致性 | 健康狀態：完整排課為 green，不完整為 warning |
| 互動回饋 | 重算 / 綁定 / 加購均需確認 dialog，成功後 toast 顯示更新後剩餘堂數 |
| 空狀態設計 | 尚無方案時提供「建立多科共用堂數方案」CTA 與用途說明 |
| 載入狀態 | 列表載入使用 skeleton cards |
| 防呆設計 | 綁定舊課程必須先 dry-run，apply button 在 dry-run 成功前 disabled |
| 響應式 | 成員課程在手機版改為 stacked list |
| 無障礙 | 確認 dialog 有焦點鎖定與 Escape 關閉 |

## 6. 功能需求

- FR-001：系統應保留一般課程為預設新增流程，並將「多科共用堂數方案」標示為進階選項。
- FR-002：系統應在方案入口以中文說明「多科共用同一包堂數；各科仍獨立排課、老師、評量」。
- FR-003：主任可建立堂數制 `CoursePackage`，包含 2 至 10 個科目成員。
- FR-004：建立方案時，每個科目成員應建立獨立 `StudentClass`，且全部帶相同 `PackageID`。
- FR-005：方案池總堂數與剩餘堂數應以 `CoursePackage` + `package_session_ledger` 為顯示基準。
- FR-006：方案成員出席扣堂時，同方案所有成員顯示同一剩餘堂數。
- FR-007：方案成員課程不可直接修改單科剩餘堂數；若要加購，必須更新方案總堂數。
- FR-008：方案續報加購應將 `CoursePackage.total_sessions` 增加 N，並依已用堂數重算剩餘。
- FR-009：舊課程綁定方案必須支援 dry-run，列出可綁定 / 不可綁定原因。
- FR-010：綁定舊課程不可跨學生、跨分校、跨付款模式或覆蓋其他方案。
- FR-011：課程管理與學生管理列表應一致顯示方案池剩餘與方案 badge。
- FR-012：主任繳費提醒不得把方案成員重複列成多筆未繳；若方案層已處理，成員需避免重複提醒。
- FR-013：所有新查詢必須遵守 `branch_id` / `campus_id` 隔離。
- FR-014：所有方案建立、加購、綁定、重算操作需寫入 log，包含操作者、分校、方案 id。

## 7. 非功能需求

- NFR-001：方案列表 100 筆內 API response < 2s。
- NFR-002：方案扣堂與重算需冪等，同一出席不可重複扣同一堂。
- NFR-003：綁定舊課程 dry-run 不寫 DB。
- NFR-004：加購 / 綁定 / 重算需用 transaction，失敗時不得留下半套 `PackageID`。
- NFR-005：前端建立流程在 API 失敗時保留使用者已輸入資料。
- NFR-006：若方案資料載入失敗，列表應 fallback 顯示原課程資料並標 warning，不得整頁空白。

## 8. 技術方向

### 資料模型

- 沿用 `CoursePackage` 作為方案主檔。
- 沿用 `StudentClass.PackageID` 表示成員課程。
- 沿用 `package_session_ledger` 作為扣堂事件來源。
- 預期不新增 migration；ARCH 需確認現有欄位足夠支援方案加購與綁定 UX。

### API

- 沿用 `POST /api/v1/course-packages/create-multi-subject` 建立方案。
- 沿用或強化 `PUT /api/v1/course-packages/{id}` 作為方案層加購總堂數更新入口。
- 沿用或強化 `POST /api/v1/course-packages/{id}/bind-courses` 作為舊資料綁定入口。
- 課程列表 `GET /api/v1/student-classes` 繼續回傳 `package_*` 顯示欄位。

### 前端

- `UniversalClassScheduler`：恢復清楚入口與說明。
- `CourseManagement` / `StudentsList`：方案成員續報加購改導向方案層。
- `CoursePackagesPage`：作為進階管理 / 綁定 / 重算入口。

### 架構取捨

- 不合併成單一 `StudentClass`，因為排課、老師、評量、出勤都依科目成員運作。
- 不讓方案成員直接改 `RemainingSessions`，避免與方案池 ledger 分叉。
- 不新增一套 credit 系統，避免重複 `CoursePackage` 能力。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|---|---|---|---|
| 2026-04-29 | 以 `CoursePackage` 作為共用堂數方案主模型 | 把多科合併為單一 `StudentClass` / 新增 credits 表 | 既有服務已支援方案池與 ledger；合併單一課程會破壞老師、評量、排課邊界 |
| 2026-04-29 | 一般課程仍為預設，方案為進階選項 | 與一般課程並列主推薦 | 遵守 R24，避免主任把所有多科固定時段誤建成共享付款池 |
| 2026-04-29 | 方案加購更新總堂數，不建單科新批次 | 從任一成員建立新的 `StudentClass` | 共用方案語意是同一池加值，單科新批次會再次拆裂剩餘堂數 |
| 2026-04-29 | 舊資料綁定必須 dry-run | 直接批次轉換 | 堂數與繳費屬高風險資料，需可預覽與可拒絕不合法資料 |

## 9. 資安與存取控制

**觸發原因**：涉及學生資料、堂數/繳費、director 權限與分校隔離。

**STRIDE 掃描**

- S：使用既有 Bearer token + role middleware；teacher 不可建立/綁定方案。
- T：所有 POST/PUT body 必須 validate；不可讓前端任意寫 `PackageID` 到他校課程。
- R：建立、加購、綁定、重算均需 Log。
- I：錯誤訊息不可洩漏其他分校學生或課程 id；跨分校回 403。
- D：批次科目上限 10；綁定課程上限 10；列表分頁或 branch filter。
- E：course-package routes 必須維持 director/admin/super_admin 權限與 campus check。

**Campus 隔離**

- 建立方案：`student.CampusID` 必須等於 `branch_id`，且 director 必須擁有該 campus。
- 綁定舊課程：所有 `StudentClass.StudentID` 必須同學生，且學生 campus 必須在授權 campus。
- 查詢方案：非 super_admin 僅能查自己 `auth_campus_ids`。

## 10. QA 驗收

### Happy Path

- 建立 2 科共用 8 堂方案，列表顯示 2 個成員與同一池剩餘 8 堂。
- 點名其中一科後，方案池剩餘 7 堂，另一科列表也顯示 7 堂。
- 對方案成員加購 8 堂後，方案總堂數增加，所有成員顯示新總堂數。
- 綁定兩筆同學生舊課程 dry-run 成功，apply 後兩筆都有相同 `PackageID`。

### Edge Cases

- 只選 1 科時，建立方案被阻擋並引導使用一般課程。
- 方案已使用 5 堂時，總堂數不可改成小於 5。
- 群組課同時段多學生不可重複扣方案池。
- 同日不同時段兩科應扣 2 堂。
- 已停用課程綁定規則需明確：預設不允許，除非 ARCH 定義歷史修復模式。

### Error / Security

- director 嘗試綁定他校學生課程 → 403。
- 方案成員嘗試直接改單科 remaining_sessions → 不應改變方案池。
- API 失敗時前端保留表單資料並顯示錯誤。

### UI/UX 清單

- [ ] 空狀態有說明與 CTA。
- [ ] 建立、加購、綁定皆有 loading。
- [ ] 成功 / 失敗皆有 toast 或 inline message。
- [ ] 必填欄位有 asterisk 與正向錯誤訊息。
- [ ] 危險操作有二次確認。
- [ ] 手機版無水平 overflow。
- [ ] 方案語意不只靠顏色，需文字標示「方案共用」。

## 11. 上線與維運

### 部署步驟

1. Feature branch 開發。
2. PHPUnit / Vite / PHPStan CI 全綠。
3. PR merge 後由 `deploy.yml` 自動部署。
4. 若 GitHub-hosted deploy 因額度失敗，需另行明確批准緊急手動部署。
5. 部署後 health check + smoke test。

### Feature Flag 策略

- 建議新增前端 feature flag / 設定常數：`enable_shared_package_create_entry`。
- 第一階段只對 super_admin / 指定分校顯示入口。
- 第二階段開放有需求分校。
- 第三階段全分校開放，但仍保留「進階選項」定位。

### Observability

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---|---|---|---|
| 方案建立 | `CoursePackage createMultiSubject` | 4xx 比率 > 5% | `[OPS]` |
| 方案加購 | `CoursePackage totalSessions changed` | 任一 5xx | `[OPS]` |
| 舊課程綁定 | `CoursePackage bind-courses` | 跨校 403 激增 | `[SEC]` |
| 扣堂重算 | `PackageDeductionService` ledger rebuild / recompute | remaining < 0 或 used > total | `[OPS]` |

### 回滾

- 無 migration 時：`git revert <PR merge commit>` 後走 CI/deploy。
- 若只前端入口出問題：可關閉 feature flag，保留後端能力。
- 若扣堂異常：停止入口，使用 `rebuild-ledger` dry-run 檢查，必要時由獨立資料修復 PR 處理。

## 12. 里程碑與優先級

| 優先級 | 任務 | Agent |
|---|---|---|
| P0 | ARCH 複核 `CoursePackage` 現有 API / DB 是否足夠，不足處列 migration plan | `[ARCH]` |
| P0 | 定義方案加購口徑與舊資料綁定合法條件 | `[ARCH]` |
| P1 | 前端恢復進階入口與中文說明 | `[FEATURE]` |
| P1 | 方案成員續報加購導向方案層 | `[FEATURE]` |
| P1 | 舊資料綁定 dry-run / apply UI | `[FEATURE]` |
| P1 | PHPUnit 覆蓋建立、扣堂、加購、綁定、分校隔離 | `[TEST]` |
| P1 | STRIDE + campus isolation review | `[SEC]` |
| P1 | Code review 對照 FR | `[REVIEW]` |
| P2 | 補充使用說明與主任 FAQ | `[DOCS]` |
| P2 | 部署與分校灰度開放 | `[OPS]` |

## 13. 風險 / 假設 / 開放問題

### 三層研究摘要

- 本專案：已存在 `CoursePackage`、`PackageDeductionService`、`CoursePackageController`、`CoursePackagesPage`，且 R24 明定「一般多科固定時段優先走一般課程，共用方案保留但不可誤導」。
- 業界：ClassPass / Mindbody 常見模式是 credit bank / package，使用者買一包 credits，可用於不同課程或服務，每次預約/出席扣 credits。
- 開源：OpenEduCat / UniTime 等教育系統多採「學生 / 課程 / 排課 / 費用」模組分層，適合作為 AllTrue 保留子課程、方案層聚合的參考；不適合直接套用大學 credit-hour transcript 模型。

| 風險 | 等級 | 業界/開源參考 | 本專案採行方式 |
|---|---|---|---|
| 主任誤把一般多科課程建成共用方案 | 高 | SaaS 通常用明確 package / membership 文案區分一般 class booking | 一般課程預設，方案為進階選項，中文說明與例子明確 |
| 方案池與單科 RemainingSessions 分叉 | 高 | ClassPass 類 credit bank 以 credit ledger 為準 | 顯示與加購以 `CoursePackage` / ledger 為準，禁改單科剩餘 |
| 舊資料綁定造成剩餘堂數錯 | 高 | Stripe-style event replay / dry-run repair | 綁定先 dry-run；不刪歷史；必要時 rebuild ledger |
| 跨分校綁定資料外洩 | 高 | 多校區 ERP 以 tenant/campus scope 隔離 | API 驗證 student campus 與 auth campus |
| 月結方案與堂數方案語意混淆 | 中 | 教育 ERP 常分 fee plan 與 attendance | 本次主軸堂數制；月結只保持既有路徑不重設計 |
| 前端入口重新開放造成回歸 | 中 | 大型 SaaS 以 feature flag 灰度 | 使用 feature flag / 指定分校開放 |

### 假設

- 假設現有 DB schema 足夠支援本次功能；若 ARCH 發現欄位不足，需先產 migration plan，且 migration 必須 CI + deploy.yml 執行。
- 假設 `PackageDeductionService` 現有冪等邏輯可支援本次扣堂；若測試發現群組課或同時段誤扣，需先修扣堂服務再開入口。
- 假設主任願意接受「畫面上是一筆方案，底層仍是多筆科目課程」；若不成立，需重新評估資料模型，不能直接把多科塞入單一 `StudentClass`。

### 開放問題

- [AI-RESOLVABLE] 現有 `CoursePackagesPage` 是否仍在主導航可進入，或只是 orphan page。
- [AI-RESOLVABLE] `bindCourses` 目前是否完整檢查同學生 / 同分校 / 堂數制，若不足需補後端 guard。
- [AI-RESOLVABLE] 方案加購從 `StudentsList` 與 `CourseManagement` 兩處入口是否都已導到同一邏輯。
- [BLOCKED: 需產品取捨] 已停課歷史課程是否允許綁定成方案以修復舊資料，或只允許 active 課程。

## 14. Definition of Done

- [ ] PRD 完整：驗證方式：檢查本文件含 0-14 節、5b、8b、13 節研究表。
- [ ] ARCH 完成：驗證方式：產出 API 合約、DB 變更清單、campus isolation 設計，無 `[BLOCKED]` 未處理。
- [ ] 建立方案：驗證方式：PHPUnit 建立 2 科共用 8 堂方案，回傳 1 package + 2 members。
- [ ] 扣堂同步：驗證方式：PHPUnit 出席其中 1 科後，package remaining 8 → 7，兩成員列表皆顯示 7。
- [ ] 方案加購：驗證方式：PHPUnit 對方案加購 8 堂後，total_sessions 增加且 remaining 依 used 重算。
- [ ] 綁定 dry-run：驗證方式：PHPUnit dry-run 不寫 DB，回傳 report。
- [ ] 分校隔離：驗證方式：PHPUnit 他校 director 綁定 / 查詢方案回 403。
- [ ] 前端 build：驗證方式：`cd frontend && npm run build` 成功。
- [ ] 後端測試：驗證方式：`cd backend && ./vendor/bin/phpunit --filter CoursePackage` 0 failures。
- [ ] STRIDE：驗證方式：PR 描述含 STRIDE 審查，無 HIGH。
- [ ] CHANGELOG：驗證方式：`docs/CHANGELOG.md` 含一行功能記錄。
- [ ] Deploy：驗證方式：`curl -sk https://daan.lifenet.com.tw/api/v1/health` 回傳 `status=ok`。

## Todos（跨功能）

| 類別 | 任務 | Agent |
|---|---|---|
| 後端 API / 資料 | 複核並補強 `course-packages` 建立、更新總堂數、bind-courses guard | `[FEATURE]` |
| 前端 UI 功能 | 恢復進階入口、方案加購 modal、舊資料綁定 UI | `[FEATURE]` |
| UI/UX 精緻化 | 對照第 5b 節完成文案、空狀態、loading、防呆、響應式 | `[FEATURE]` |
| 測試與自動 QA | 新增 CoursePackage 建立/扣堂/加購/綁定/campus tests | `[TEST]` |
| 資安靜態審查 | STRIDE + campus isolation + 批次上限檢查 | `[SEC]` |
| Code Review | 對照 FR-001 至 FR-014 | `[REVIEW]` |
| 文件更新 | CHANGELOG、AI_REGRESSION_LESSONS R24 補充例外、主任 FAQ | `[DOCS]` |
| 部署與 health check | PR merge 後監控 CI/deploy/health/version | `[OPS]` |
| 不適用項 | 外部 API / 第三方整合 | 無，本次不涉及 |

# PRD — #613 補課部分時數扣堂（A1：分鐘制餘額）

## 1. 文件資訊
- Issue：#613（#142 §3）｜決策：使用者選 **A1（minutes-based）**，rounding = **ROUND_HALF_UP**（半堂進位）
- Risk Tier：**T3 safety-critical**（堂數扣除＝金額相關；觸碰 DEV-forbidden `SessionDeductionService`；需 migration）
- 作者：AI（ARCH）｜狀態：PLAN，等使用者於「引擎變更」前 OK

## 2. 目標 / KPI
- 補課只覆蓋部分時數時，依「實際補課分鐘 / 應上分鐘」扣除對應比例，不再一律扣整堂。
- KPI：部分補課的 `RemainingSessions` 顯示值與帳面一致；既有「整堂」情境行為 **byte-identical**（golden snapshot 驗證）。

## 3. 範圍
- **In**：分鐘制權威餘額（`StudentClass.RemainingMinutes`/`PurchasedMinutes`、`session_deduction_ledger.minutes`）；`recomputeCounters` 以分鐘為權威、`RemainingSessions` 改為 ROUND_HALF_UP 衍生整數；補課入口傳入覆蓋分鐘；前端/API 附加精確顯示。
- **Out**：不改既有 read-site 的整數語意（`AlertController <=2`、`FinanceController SUM`、家長入口等照舊讀衍生整數）；不改收費（charge）計算；不動 package 以外的計價。

## 4. RACI / 4b. Dependencies
- R：AI；A：使用者（money-critical 最終 merge gate）。
- 依賴：`SessionDeductionService`（核心）、`ClassSessionController::recalculateSessionCounters`（重複 recompute，需收斂）、`PackageDeductionService`（共用池鏡像 `delta=±1`）。

## 5. User Stories + AC
- US1：主任替學生安排「只補 1 小時」的補課（原缺 2 小時）→ 系統扣 0.5 堂；`RemainingMinutes` 反映 60 分，`RemainingSessions` 以 ROUND_HALF_UP 顯示。
- US2（回歸）：一般整堂補課/點名/核課/請假還原 → 與現況完全一致（`RemainingSessions`/`UsedSessions`/ledger net 不變）。
- AC：golden snapshot 矩陣（count 8×120、變動時長、月結、package、makeup cancel、leave reverse）在 `$minutes` 預設下 byte-identical；新增 fractional 案通過。

## 5b. UI/UX
- 兼職薪資/堂數不變語意；課程卡可選顯示「剩餘 X.X 堂（精確）」附加欄位（additive，不取代整數徽章）。空狀態/threshold 沿用。

## 6. FR
1. 新增分鐘權威欄（additive、nullable）。
2. ledger 每筆帶 `minutes`（legacy 預設＝該課每堂分鐘 → 等同整堂）。
3. `recomputeCounters`：`remainingMinutes = purchasedMinutes − netUsedMinutes`（整數）；`RemainingSessions = ROUND_HALF_UP(remainingMinutes / perSessionMinutes)`，保留 `min(SessionCount,…)` cap 語意；以分鐘為 cap 來源，counts 降為 safeguard。
4. 補課部分時數：`type='extra'` 補課覆蓋分鐘 < 原缺堂分鐘時，只還原/扣除覆蓋分鐘。
5. `deductForSession`/`reverseForSession` 接受可選 `?int $minutes`，預設＝該課每堂分鐘（no-op 保證）。

## 7. NFR
- 不得用 float（整數分鐘 / BCMath）；round 只在衍生顯示一次。
- 效能：recompute 仍 O(1) 查詢；migration 用 `chunkById`。

## 8. 技術方向（禁 code）
- 「最小整數單位」＝分鐘（對應 money 的 cents）。權威值存分鐘，顯示值衍生。
- 收斂 `ClassSessionController::recalculateSessionCounters` → 一律委派 `SessionDeductionService`，避免兩套 recompute 在分鐘出現後分歧。
- package 池鏡像同步改為分鐘感知，否則共用池成員會漂移。

## 8b. Decision Log
- 採分鐘制（A1）而非「整數 0.5 堂欄」（A2）或「無條件進位」（B）：使用者選 A1。
- rounding = ROUND_HALF_UP：業界財務預設（laravel-fortress / SaaS proration / tarfin fintech 一致）。
- per-session 分鐘來源：`COALESCE(SessionDuration, 60)`；變動時長課（`duration1..6` 不一）以契約 `SessionDuration` 為準並標記人工複核。

## 9. 資安
- 無新公開端點；扣堂仍經既有 auth/role/require_campus。引擎無 CampusID 但 StudentClass 已天然 campus-scoped；ledger 新欄不引入跨校洩漏。

## 10. QA 驗收
- golden snapshot（整堂路徑不變）＋ fractional 新案＋既有 deduction 測試全綠（`LearningRecordApprovalDeductionTest`、`AttendanceRemainingSessionsRegression`、`CancelMakeupScheduleTest`、`ScheduleLeaveCascadeTest`、package 系列）。

## 11. 上線維運
- migration 為 additive + backfill；deploy.yml 在有 pending migration 時 `migrate --force`（先備份）。回滾：drop 新欄（additive）。
- 引擎變更具 feature 安全性（預設分鐘 = 整堂），可先上 schema、後上引擎。

## 12. 優先級
- P2（產品技術債/體驗），但 money-critical → 流程從嚴。

## 13. 風險
- **最高**：`max()`-of-counts cap 會把整數 count 當 `UsedSessions`，可能抹掉 fractional credit → 必須讓分鐘餘額成為 cap 來源、counts 降 safeguard（行為決策，需 golden 驗證）。
- 重複 recompute（ClassSessionController）若未收斂 → 兩套結果分歧。
- package 池 `delta=±1` 未改 → 共用池漂移。

## 14. DoD（AI 可驗證）
- 整堂路徑 golden snapshot byte-identical；fractional 新案綠；既有 deduction 測試全綠；CI 綠；`RemainingSessions` 對所有 read-site 仍是「剩餘整堂數」語意。

---

## 落地分期（避免 mega-PR / money-critical 分段）
- **PR1（安全地基，本次）**：additive nullable 欄位（`PurchasedMinutes`/`RemainingMinutes`/ledger `minutes`）+ model fillable + `perSessionMinutes()` helper。**無行為變更**。
- **PR2（引擎，需使用者 OK）**：backfill + `recomputeCounters` 分鐘權威 + 收斂重複 recompute + golden snapshot + 預設 no-op。
- **PR3**：補課部分時數 wiring（`type='extra'` 覆蓋分鐘）+ fractional 測試 + package 分鐘鏡像。
- **PR4（選配）**：API/前端精確顯示。

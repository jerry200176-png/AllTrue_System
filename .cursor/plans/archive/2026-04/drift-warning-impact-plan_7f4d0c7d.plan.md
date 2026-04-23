---
name: drift-warning-impact-plan
overview: 釐清非固定星期加課觸發堂次偏移警告的後續影響，並提出避免補課被誤判或被重建流程覆寫的修正方案。
todos:
  - id: schema-exception-flag
    content: 在 ClassSession 增加 IsContractException 欄位並更新 model
    status: completed
  - id: backend-drift-exemption
    content: addSession、index drift、sync/remap 流程加入例外堂次豁免
    status: completed
  - id: frontend-badge-split
    content: CourseManagement 分流顯示偏移警告與補課例外提示
    status: completed
  - id: tests-regression-cover
    content: 新增 Feature 測試覆蓋補課例外與重建不覆寫
    status: completed
isProject: false
---

# 非固定星期加課堂次偏移修正計畫

## 調查結論
- 目前 `⚠ 堂次偏移` 是預期行為：後端會把「契約時段」與「今日起 `scheduled` 堂次」做比對，只要有不一致就標記 `schedule_drift=true`，前端僅顯示警告，不會立刻擋操作。
- 關鍵位置：
  - [CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue)（顯示警告 badge）
  - [StudentClassController.php](/home/admin/backend/app/Http/Controllers/StudentClassController.php)（`index()` 計算 `schedule_drift`、`addSession()`、`syncFutureScheduledSessionTimes()`）
  - [SameDayMultiSlotTest.php](/home/admin/backend/tests/Feature/SameDayMultiSlotTest.php)（已驗證 drift 偵測）

## 實際風險（需修）
- 非固定星期加課/補課（你這次情境）會被當成「契約外堂次」而長期顯示偏移，容易造成營運誤判。
- 後續若編輯課程且觸發「部分重建/同步未來堂次」，目前邏輯可能把這類補課堂次重排回契約星期，導致補課安排被系統吃掉。
- 現況缺少「這堂是合法補課例外」的結構化標記，只能靠 `Note`，無法在 drift/重建邏輯中精準豁免。

## 修正策略（最小風險）
- 新增「堂次例外標記」：在 `ClassSession` 增加布林欄位（如 `IsContractException`），專門標示非固定星期的合法補課/加課。
- `addSession()` 建立或移動堂次時：若日期/時段不在契約內，自動標記 `IsContractException=1`；若回到契約內則清除標記。
- `schedule_drift` 計算：忽略 `IsContractException=1` 的未來堂次，避免對合法補課持續告警。
- `syncFutureScheduledSessionTimes()` / `remapFutureScheduledSessionsToContract()`：跳過 `IsContractException=1` 堂次，避免被自動重排。
- 前端文案升級：若存在例外堂次，改顯示「含補課例外」提示（非警告），與真正異常偏移分流。

## 實作步驟
1. DB 與 Model
- 新增 migration（`ClassSession` 增加 `IsContractException`，預設 0，建議加 index）。
- 更新 `ClassSession` model cast/fillable。

2. 後端邏輯
- 在 [StudentClassController.php](/home/admin/backend/app/Http/Controllers/StudentClassController.php) 新增 helper：判斷堂次是否落在契約槽位。
- 修改 `addSession()`：建立/移動後依契約判斷寫入 `IsContractException`。
- 修改 `index()` 的 drift 比對：排除 `IsContractException=1` 堂次。
- 修改 `syncFutureScheduledSessionTimes()` / `remapFutureScheduledSessionsToContract()`：不調整 `IsContractException=1` 堂次。

3. 前端呈現
- 在 [CourseManagement.vue](/home/admin/frontend/src/pages/CourseManagement.vue) 依 API 回傳新增 badge 分流：
  - 真偏移：`⚠ 堂次偏移`
  - 合法例外：`補課例外`
- tooltip 說明補課例外不會被重建覆寫（僅手動調整時才變更）。

4. 測試補強（QA 新增）
- 新增 Feature test（建議檔案：`backend/tests/Feature/StudentClassScheduleDriftExceptionTest.php`）：
  - 非固定星期 `add-session` 會標記 `IsContractException=1`。
  - 若加課日期/時段回到契約內，會清除 `IsContractException`（避免髒標記）。
  - 有例外堂次時 `schedule_drift=false`，且可回傳 `contract_exception_count` 供前端顯示「補課例外」。
  - 同課程同時存在「例外堂次 + 真偏移堂次」時，`schedule_drift` 仍應為 `true`（不能被例外掩蓋真正異常）。
  - 觸發 `force_partial_rebuild` 後，例外堂次日期/時間不被 remap；非例外堂次仍會按契約同步（雙向驗證）。
  - 若堂次已有鎖定（`StudentSignIn` 或 approved `LearningRecord`），例外標記不應繞過既有鎖定保護（不可修改/搬移）。
- 前端最小驗收（可先手測，後續可補 E2E）：
  - 僅例外堂次：顯示「補課例外」且不顯示 `⚠ 堂次偏移`。
  - 真偏移堂次：顯示 `⚠ 堂次偏移`。
  - 同時存在兩者：以偏移警告為主，並在 tooltip 說明含補課例外。
- 保留並通過既有回歸測試（避免舊問題復發）：
  - [SameDayMultiSlotTest.php](/home/admin/backend/tests/Feature/SameDayMultiSlotTest.php)
  - `StudentClassUpdateScheduleReconcileTest`（改星期後 future 堂次重算）
  - `ClassSessionBatchApiTest`（契約星期驗證仍生效）

5. 上線前驗證
- 以新店分校實資料重現你這個案例：
  - 加課在非固定星期後，不再顯示誤導性警告。
  - 編輯課程儲存/重建後，該補課日期不被改回固定星期。
- 補一個反向驗證：
  - 人為建立一筆「非補課、無例外標記」的契約外 future scheduled 堂次，系統仍需顯示 `⚠ 堂次偏移`（確保沒有放寬到漏報）。
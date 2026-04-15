# 技術問題報告：課程管理「改時段」異常與相關 API 行為

**對象**：後端／前端工程  
**日期**：2026-04-12  
**嚴重度**：功能面—使用者於課程管理編輯時段後，畫面／資料看似未更新；另主控台出現誤導性錯誤請求。

---

## 1. 現象摘要

| 現象 | 說明 |
|------|------|
| 改時段不生效 | 主任於「課程管理」編輯課程、變更星期／時段後儲存，列表或摘要列仍顯示舊時段（或與實際 `ClassSession` 不一致）。 |
| Console：`410 Gone` | 請求 `POST /api/v1/student-classes/sync`。 |
| Console：`422 Unprocessable Content` | 請求 `POST /api/v1/schedules`（與儲存編輯未必同一操作）。 |
| PWA meta 警告 | `apple-mobile-web-app-capable` 棄用提示，與本議題無關。 |

---

## 2. 根因分析（已對照程式碼）

### 2.1 `POST /api/v1/student-classes/sync` 永遠回 410

**位置**：`backend/app/Http/Controllers/StudentClassController.php` → `sync()`

後端**刻意**在方法開頭直接回傳 410，並註明舊排程同步已退役；其後原有同步邏輯為**不可達死碼**。

**仍呼叫端**：`frontend/src/pages/SmartCalendar.vue` 於載入課程資料後，若存在 Supabase 課程清單仍會 `fetch(.../student-classes/sync)`，錯誤僅被 `.catch(() => {})` 吞掉，故 DevTools 仍顯示失敗。

**影響**：不直接阻斷課程管理儲存，但造成**主控台噪音**、誤判「後端壞掉」；舊同步行為實際已不存在。

**建議修正方向**（擇一或併行）：

- 前端：移除或改寫對 `student-classes/sync` 的呼叫，改走後端訊息所建議之流程（例如 `POST /api/v1/class-sessions/batch` 與明確日期）；或改為僅在仍使用 Supabase 課程來源時才呼叫，並處理 410 不再當成靜默失敗。
- 後端：若短期內前端無法全面改完，可評估回傳 **200 + 空結果** 或 **deprecated 註記**，避免監控／使用者誤判（需產品同意是否「假成功」）。

---

### 2.2 課程管理「儲存編輯」與 `POST /api/v1/schedules` 的關係

**課程管理儲存**：`frontend/src/pages/CourseManagement.vue` → `submitEdit` 使用 **`PUT /api/v1/student-classes/{id}`**，**不會**在該流程內直接 `POST /api/v1/schedules`。

`POST /api/v1/schedules` 主要用於請假、調課／補課等（例如 `CourseManagement` 請假、`useSessionEditFlow`、`SmartCalendar` 請假等）。

**結論**：Console 上的 **422 應另案**依該次請求的 **Request Payload + Response body** 追查（驗證失敗、分校／課程不符、請假缺參數等）。**不應**與「僅改時段儲存」混為同一根因。

---

### 2.3 「改時段像沒更新」— 高機率後端邏輯問題

**位置**：`backend/app/Http/Controllers/StudentClassController.php`

`update()` 順序大致為：

1. `mapFrontendPayload` → `$studentClass->update($mapped)`（寫入 `StudentClass` 的 `week` / `time` 等）
2. `maybeRebuildSessionsAfterUpdate(...)`  
   - 若課程已有「不可變歷史」（出缺勤或已核准評量等），走 **`syncFutureScheduledSessionTimes`**：僅更新 **未來** 且 **`ClassSession.Status = scheduled`** 的堂次之起迄時刻。
3. **`reconcileWeekTimeFieldsFromSessions($studentClass)`**：依 **`ClassSession` 實際列出的時間**，再次寫回 `StudentClass` 的 `week` / `time` / `week1`…

**問題機制**：

- 若步驟 2 **未**把未來堂次的時間改成使用者輸入（例如 `updated_future_sessions === 0`：堂次狀態非 `scheduled`、被鎖、slot 解析失敗、或本次 PUT 未被判斷為「排程欄位有變」而直接 `start_date_not_updated` 等），則 DB 裡 **`ClassSession` 仍為舊時間**。
- 步驟 3 仍會執行，並用 **舊的 `ClassSession` 時間** 覆寫剛在步驟 1 寫入的 **`StudentClass` 時段欄位**。

結果：**API 可能 200**，但回傳／下次載入的課程主檔時段與使用者儲存內容不一致，**外观上即「改時段沒更新」**。

**相關程式**（節錄意義，實際行號以 repo 為準）：

- `maybeRebuildSessionsAfterUpdate`：`scheduleUpdated` 為 false 且未帶 `StartDate` 時回傳 `reason: start_date_not_updated`，不進入堂次時間同步。
- `syncFutureScheduledSessionTimes`：`ClassSession` 查詢條件含 `Status = scheduled` 且 `SessionDate >= today`。
- `reconcileWeekTimeFieldsFromSessions`：優先使用未來 `scheduled` 堂次；若無則 fallback 歷史堂次，仍會回寫 `StudentClass`。

---

## 3. 建議修正方向（供技術評估）

以下為**設計層級建議**，實作前請跑回歸測試（堂數制／月結、多時段、請假順延、已點名／已核准評量課程）。

1. **避免「主檔已改、堂次未改、reconcile 洗回舊值」**  
   - 例如：當本次為「使用者明確變更排程時段」且 `syncFutureScheduledSessionTimes` 更新筆數為 0 時，**不要**用舊 `ClassSession` 覆寫本次 `StudentClass` 的時段；或先保證步驟 2 必須成功更新／明確失敗回傳給前端。  
   - 或：`reconcile` 僅在「堂次時間已與主檔一致」或「完成重建」後執行，避免與使用者意圖衝突。

2. **釐清 `ClassSession` 狀態與「改時段」語意**  
   - 若產品要求「未來已標 completed/attended 的堂次也要跟著改時間」，則 `syncFutureScheduledSessionTimes` 的篩選條件需與產品一致；否則應在 API 回傳中明確告知「僅更新 N 堂／其餘因狀態略過」。

3. **前端移除對已退役 `student-classes/sync` 的呼叫**（或改走新 API），消除 410 與誤判。

4. **`POST /api/v1/schedules` 422**  
   - 請依實際失敗請求補 logging（route、user、payload 摘要）或請使用者提供該筆 Network 內容，對照 `ScheduleController::store` 驗證與自訂 422 分支。

---

## 4. 建議驗證步驟（修正後）

1. 建立一堂數制課程，已有至少一堂「已點名或已核准評量」＋多堂未來 `scheduled` 堂次。  
2. 於課程管理變更星期或開始時間後儲存。  
3. 確認：`PUT student-classes` 回應中 `session_sync`（`reason`、`updated_future_sessions`）；DB `ClassSession` 未來堂次之 `StartTime`/`EndTime`；`StudentClass` 之 `week`/`time` 與列表顯示一致。  
4. 切換至智慧排課再回課程管理，確認無 410 或行為符合新設計。  
5. 若有請假／調課流程，重跑現有 Feature tests（`ScheduleController`、`StudentClassController`、請假 cascade 等）。

---

## 5. 涉及檔案索引

| 類型 | 路徑 |
|------|------|
| 後端 | `backend/app/Http/Controllers/StudentClassController.php`（`update`、`sync`、`maybeRebuildSessionsAfterUpdate`、`syncFutureScheduledSessionTimes`、`reconcileWeekTimeFieldsFromSessions`） |
| 後端 | `backend/app/Http/Controllers/ScheduleController.php`（`store` 與 422／409 分支） |
| 前端 | `frontend/src/pages/CourseManagement.vue`（`submitEdit`） |
| 前端 | `frontend/src/pages/SmartCalendar.vue`（`student-classes/sync`） |

---

## 6. 變更紀錄建議

修正合併後請於 `docs/CHANGELOG.md` 簡述：課程編輯時段與 `ClassSession`／`reconcile` 行為、以及智慧排課停用對 `sync` 的呼叫（視實作而定）。

---

**本文件由開發流程中問題盤點產出，供內部追蹤與工單拆分使用。**

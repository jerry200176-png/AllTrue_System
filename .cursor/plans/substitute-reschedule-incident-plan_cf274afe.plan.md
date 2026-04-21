---
name: substitute-reschedule-incident-plan
overview: 針對新店分校呂承澔（數學科）在代課/調課後出現教師顯示不一致與4/20日期大量生成的事故，先提供營運止血指引，再規劃可驗證的後端修復與測試補強，降低再次發生風險。
todos:
  - id: incident-freeze
    content: 發布代課/調課臨時止血SOP，暫停未上課堂次代課批次操作並啟用雙人覆核
    status: completed
  - id: backend-join-fix
    content: 修正ClassSession代課老師查詢join粒度，避免同日多堂錯配
    status: completed
  - id: remap-sync-fix
    content: 補強future remap後ClassSession與schedules日期同步與唯一性守門
    status: completed
  - id: frontend-consistency
    content: 代課成功後立即更新前端local row教師欄位，降低短暫顯示舊老師
    status: completed
  - id: regression-tests
    content: 新增/擴充代課與調課防重複回歸測試，覆蓋4/20類型案例
    status: completed
isProject: false
---

# 代課與調課異常調查改善計畫

## PM 調查回覆（給現場）
- 這次現象不是單一操作錯誤，較像是「代課資料（`schedules`）與堂次回沖（`ClassSession` remap）」在特定流程下失去同步，導致：
  - 代課成功提示後，預覽仍顯示舊老師。
  - 重新編輯/調課後，未來堂次可能被集中映射到同一天（你看到的 `4/20`）。
- 你的臨時作法（等課程【已上】再改代課老師）是合理的風險控管，先維持此作法直到修復完成。

## 根因假設（目前優先順序）
1. `ClassSession` 列表組裝代課老師時，`schedules` 的 join 粒度偏粗（課程+日期），同日多筆時可能配錯。
2. 課程更新觸發的未來堂次重映射（`remapFutureScheduledSessionsToContract`）可能把 anchor snap 到某一日後整批覆寫（例如 4/20）。
3. 代課流程寫入 `schedules` 後，後續回沖若只改 `ClassSession` 日期而未同步 `schedules.schedule_date`，畫面會回退顯示舊老師。
4. 前端代課成功後雖有 reload，但本地資料未立即 patch，短時間可能顯示舊教師名稱。

## 立即止血（不改碼，今天可執行）
- 針對「未上課堂次」先暫停代課批次操作；維持你目前的保守流程。
- 調課後立即雙人覆核：
  - 原日期是否只保留 `rescheduled`。
  - 新日期是否僅建立一筆對應堂次（避免同日膨脹）。
- 每日稽核兩份清單：
  - 契約 weekday 與未來 `scheduled` 堂次 weekday 不一致。
  - 同課程同日期同時段重複堂次（重複數 > 1）。

## 修復實作計畫（工程）
1. **後端查詢一致化**
   - 調整 [ClassSessionController.php](/home/admin/backend/app/Http/Controllers/ClassSessionController.php) `index()` 代課 join 條件，加入時段/對應鍵，避免同日錯配。
2. **回沖同步機制**
   - 檢查並補強 [StudentClassController.php](/home/admin/backend/app/Http/Controllers/StudentClassController.php) 的 `syncFutureScheduledSessionTimes` / `remapFutureScheduledSessionsToContract`，確保 remap 後 `schedules` 與 `ClassSession` 日期一致。
3. **調課防重複守門**
   - 在 [ScheduleController.php](/home/admin/backend/app/Http/Controllers/ScheduleController.php) 與 [LearningRecordController.php](/home/admin/backend/app/Http/Controllers/LearningRecordController.php) 的 reschedule 路徑加上同課程+日期+時段唯一性防護（邏輯或 DB 級）。
4. **前端顯示一致化**
   - 在 [useSessionEditFlow.js](/home/admin/frontend/src/composables/course-management/useSessionEditFlow.js) 代課成功後立即更新 local row teacher 欄位，再做 reload，降低「成功但還顯示舊老師」感知。
5. **交易邊界收斂**
   - 將代課/調課跨 Controller 的寫入收斂到單一 transaction service（例如 `ScheduleMutationService`），避免半套成功狀態。
6. **一致性巡檢與審計**
   - 新增每日巡檢 job：`ClassSession`/`schedules` mismatch、同日同時段重複、weekday 漂移；同時補齊 operation-level audit（操作者、before/after、operation_id）。

## 測試與驗收計畫
- 擴充現有測試：
  - [SubstituteTeacherTest.php](/home/admin/backend/tests/Feature/SubstituteTeacherTest.php)
  - [StudentClassUpdateScheduleReconcileTest.php](/home/admin/backend/tests/Feature/StudentClassUpdateScheduleReconcileTest.php)
  - [ClassSessionBatchApiTest.php](/home/admin/backend/tests/Feature/ClassSessionBatchApiTest.php)
- 新增 4 個回歸測試：
  - `reschedule` 後目標日期不產生重複堂次。
  - 連續調課 A→B→C 不殘留中間副本。
  - 調課前後該課程堂次總數守恆。
  - 代課後多端點 `teacher_id/teacher_name` 一致。
- 驗收基準：重現你的案例資料（新店分校、呂承澔、數學科）時，不再出現 4/20 膨脹，且 hover 與列表教師一致。

## QA 驗收測試卡（補充）
1. **UAT-01 代課後教師即時一致（P0）**
   - 步驟：白名單主任在新店分校對同日多堂中的單堂執行代課，立即檢查列表與 hover，重新整理後再檢查。
   - 預期：`teacher_id/teacher_name` 在列表、hover、重整後一致；3 秒內收斂；不得回退舊老師。
2. **UAT-02 調課後不得產生同日同時段重複（P0）**
   - 步驟：對同一堂連續調課 A→B→C，檢查最終課表與 API。
   - 預期：僅保留 C；A/B 不殘留可上課副本；同課程同日期同時段重複數 = 0。
3. **UAT-03 堂次總數守恆（P0）**
   - 步驟：記錄調課前未來堂次總數，執行代課/調課後再次統計。
   - 預期：除明確新增/刪除外，堂次總數不變；不得出現 4/20 類型膨脹。
4. **UAT-04 權限與白名單阻擋（P1）**
   - 步驟：非白名單或非授權角色嘗試未上課堂次代課/調課。
   - 預期：操作被拒絕並有可理解錯誤訊息；審計日誌完整記錄。
5. **REG-01 多端點一致性回歸（P0）**
   - 步驟：完成代課+調課後，交叉比對 class-sessions、schedules、前端使用端點欄位。
   - 預期：`teacher_id/teacher_name/date/time` 全一致，無新舊資料互覆。
6. **REG-02 唯一性與併發守門（P0）**
   - 步驟：對同課程+同日期+同時段發出重複/併發請求。
   - 預期：最多 1 筆成功；其餘被攔截；DB 無重複落地。
7. **REG-03 資料修復腳本驗證（P1）**
   - 步驟：在 staging 製造錯配樣本後連跑修復腳本兩次，產出前後報表。
   - 預期：可重跑（idempotent）；重複歸零；teacher/date 對齊；堂次守恆。

## CTO 治理補強（更新）
- **變更凍結與白名單**
  - 事故修復期間，未上課堂次代課/調課由白名單帳號操作，所有異動需 ticket 並由 PM + Tech Lead 雙簽。
- **回滾方案**
  - 上線包需包含「程式版回退」與「功能旗標關閉（禁用 remap/批次代課）」兩條路徑，並設定 30 分鐘觀測窗回滾閾值。
- **資料修復計畫**
  - 提供可重跑（idempotent）修復腳本：清理重複堂次、對齊 teacher/date 映射、產出修復前後比對報表。
- **營運溝通**
  - 發布前線 FAQ：可操作/不可操作清單、異常回報入口、人工處理 SLA。

## Go/No-Go Gate（上線審核）
1. **M1 事故凍結生效**
   - Go：SOP 已發、權限白名單生效、雙人覆核開始執行。
2. **M2 修復與回歸完成**
   - Go：新增/既有回歸測試全綠，且覆蓋 4/20 類型案例。
3. **M3 資料修復演練完成**
   - Go：staging 修復報表顯示「重複歸零、教師一致、堂次守恆」。
4. **M4 新店灰度 48h**
   - Go：48 小時無新增同日膨脹與教師不一致事件。
5. **M5 全量放行**
   - Go：營運簽核完成、監控面板可用、回滾演練紀錄齊備。

## 監控與 SLO 指標（架構審查補充）
- `ClassSession` vs `schedules` mismatch rate：目標 < 0.1%/日。
- 同課程同日期同時段重複率：目標 0（>0 直接 P1）。
- 代課/調課端到端成功率（含同步完成）：目標 >= 99.9%。
- 操作後教師顯示一致率（API 與前端）：目標 >= 99.95%，P95 收斂 < 3 秒。

## 灰度期間每日 QA 檢核（D3-D5）
- mismatch rate（`ClassSession` vs `schedules`）需 < 0.1%，超標即開 P1 並停止擴大上線。
- 同課程同日期同時段重複數必須為 0，任一筆即觸發回滾評估。
- 教師顯示一致率需 >= 99.95%，且 P95 收斂 < 3 秒。
- 異常工單（錯老師/日期膨脹）日增需為 0，若 > 0 當日完成 RCA。
- 白名單與審計覆蓋率需 100%，缺漏視同阻擋項。

## 時程建議
- D0（今天）：止血 SOP + 稽核清單上線。
- D1-D2：後端修復與測試補齊。
- D3：資料修復演練（staging）+ 前端顯示一致化 + 新店灰度上線。
- D4-D5：灰度觀察 48h，通過 Gate 後擴大到全分校。
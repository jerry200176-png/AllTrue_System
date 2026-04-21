---
name: 補登堂數邏輯與科目數類型修正
overview: 修正補登／課程管理的堂數語意（只填購買堂數、已上堂數，剩餘由系統計算；支援人工調整），並修正「計入科目數」時一對二顯示成一對一的問題（後端依 class_type 寫入 Laravel StudentClass.by1）。
todos: []
isProject: false
---

# 補登堂數邏輯與科目數類型修正

## 問題整理


| 問題                | 根因                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 補登應只填「買幾堂」、剩餘由系統算 | 目前表單已有「已購／已上／剩餘自動計算」，但寫入時把剩餘平分到每筆（perDayRemaining），且編輯處仍可「直接改剩餘」；需明確為：補登只填購買＋已上，剩餘唯讀；人工調整時再開放校正。                                                                                                                                                                                                                                                                                                                                                       |
| 科目數補登一對二卻顯示一對一    | [BackfillController::registerSubjectUnits](backend/app/Http/Controllers/BackfillController.php) 只收 `courses.*.{student_id,subject,teacher_id,day_of_week,start_time,end_time}`，**未收 class_type**。後端用 `findStudentClass()` 找到的 Laravel [StudentClass](backend/app/Models/StudentClass.php) 其 `by1` 多為預設 1（一對一）。[FinanceController::subjectUnits](backend/app/Http/Controllers/FinanceController.php) 依 `StudentClass.by1` 分類（1=一對一、2=一對二），故補登一對二會算成一對一。 |
| 請假／調課後需人工校正剩餘     | 編輯課程時應可改「已上堂數」或「剩餘堂數」以校正，且若改已上則剩餘由系統重算。                                                                                                                                                                                                                                                                                                                                                                                                                |


---

## 一、科目數正確區分一對一／一對二（後端＋前端）

**目標**：補登時選「一對二」並計入科目數後，科目數統計顯示為一對二。

**後端** [BackfillController.php](backend/app/Http/Controllers/BackfillController.php)

1. **Request**：`courses.`* 新增選填 `class_type`（例如 `one_on_one` | `one_on_two` | `one_on_three` | `tutoring`）。
2. **對應 by1**：在程式中做對應（如 `one_on_one`→1, `one_on_two`→2, `one_on_three`→3, `tutoring`→照現有 isCoaching 邏輯）。
3. **更新 Laravel StudentClass**：在 `findStudentClass()` 回傳後、建立 ClassSession／LearningRecord 前，若 request 有帶該筆的 `class_type`，則更新該筆 `StudentClass` 的 `by1` 與 `ClassType`（Laravel 欄位為 `ClassType`），再 `$sc->save()`。如此 subject-units 查詢時會讀到正確的 by1。

**前端** [CourseManagement.vue](frontend/src/pages/CourseManagement.vue)

1. 組 `lastBackfillCourses` 時，每筆加上 `class_type: backfillForm.class_type`（或該筆的 class_type）；呼叫 `POST /api/v1/backfill/register-subject-units` 時一併傳送，後端即可依此更新 Laravel StudentClass。

---

## 二、補登堂數語意：只填「購買堂數」與「已上堂數」

**目標**：補登時不讓使用者「直接填剩餘堂數」，只填「購買堂數」與「已上堂數」，剩餘 = 購買 − 已上（系統計算）；寫入與顯示一致。

**檔案** [CourseManagement.vue](frontend/src/pages/CourseManagement.vue)

1. **補登表單**
  - 維持「已購買堂數」「已上堂數」兩個輸入。
  - 「剩餘堂數」改為**唯讀顯示**（例如 `Math.max(0, 已購 - 已上)`），不可輸入。
  - 說明文案可寫：「請填寫購買堂數與已上堂數，剩餘堂數由系統計算；日後若有請假／調課可至編輯課程中校正已上堂數或剩餘堂數。」
2. **寫入邏輯**（`submitBackfill`）
  - 維持目前：`totalRemaining = sessions_purchased - sessions_used`，再依固定排課天數平分寫入每筆的 `remaining_sessions`（及堂數制時的 `sessions_purchased` 每筆分配）。
  - 確保 `students.remaining_lessons` 寫入的是**總剩餘**（totalRemaining），與列表合併顯示一致。
3. **列表顯示**
  - 已存在之合併邏輯（同學生／同科目／同時段多筆加總 `remaining_sessions`）維持不變，確保顯示「總剩餘堂數」。

---

## 三、編輯課程：支援人工校正（已上堂數／剩餘堂數）

**目標**：請假、調課後可人工校正，且語意清楚。

**檔案** [CourseManagement.vue](frontend/src/pages/CourseManagement.vue)

1. **編輯表單**
  - 堂數制時顯示三項：
    - **購買堂數**（可選：唯讀或可改，若 DB 有存總購買則可改；若目前只有每筆的 `sessions_purchased` 則可先唯讀或依合併加總顯示）。
    - **已上堂數**（可編輯，用於校正）。
    - **剩餘堂數**：若欄位為「由已上推算」，則 剩餘 = 購買 − 已上（唯讀）；或提供「剩餘堂數」可手動覆寫（用於特殊校正）。
  - 實作擇一即可（二選一）：
    - **方案 A**：編輯可改「已上堂數」與「購買堂數」，剩餘 = 購買 − 已上（唯讀）；儲存時依合併組的總購買、總已上、總剩餘，再按該組天數平分寫回每筆的 `sessions_purchased` / `remaining_sessions`（若 DB 有 `sessions_used` 則一併寫入或沿用現有欄位）。
    - **方案 B**：編輯可改「剩餘堂數」（總數），儲存時將總剩餘平分到同組每筆的 `remaining_sessions`，不強制改已上／購買。
2. **資料來源**
  - 若 Supabase `student-classes` 目前只有 `sessions_purchased`、`remaining_sessions` 而沒有「總已上堂數」欄位，則「已上堂數」可從 已購 − 剩餘 反推（總購買可為同組 `sessions_purchased` 加總或取其一×天數，依現有設計）。之後若新增 `sessions_used` 欄位，再改為直接讀寫該欄位。

---

## 四、智慧排課與補登一致（可選）

- 智慧排課若也會寫入或更新 `student-classes`，需與補登／課程管理一致：堂數制時以「購買／已上／剩餘由系統算」為原則，避免同一課程在兩邊顯示的剩餘堂數不一致。

---

## 五、實作順序建議

1. **後端**：BackfillController 接受 `courses.*.class_type`，對應 by1/ClassType，並在建立 ClassSession/LearningRecord 前更新找到的 Laravel StudentClass。
2. **前端**：補登送「計入科目數」時帶上 `class_type`。
3. **前端**：補登表單將「剩餘堂數」改為唯讀，文案說明只填購買＋已上。
4. **前端**：編輯課程表單支援「已上堂數」與「剩餘堂數」的顯示與校正（依方案 A 或 B），儲存時按組平分寫回各筆。

---

## 六、注意事項

- Laravel `StudentClass` 的 `by1` 與 Supabase 的 `class_type` 需有一致對應（one_on_one→1, one_on_two→2 等），BackfillController 內做一次對應即可。
- 若 Supabase 與 Laravel 為不同 DB，補登仍只寫 Supabase；「計入科目數」時後端依 courses 找到的 Laravel StudentClass 可能為既有資料（例如匯入），更新其 by1/ClassType 後，該課程之後的科目數統計即會正確。
- 不變更「每筆 student-class 一個 day_of_week」的 DB 結構；合併顯示與總剩餘計算仍在前端與寫入邏輯處理。


## 2026-11-13 — feat(exams): 評量表「這堂上完的單元」 (#4970 step 5)
<!-- release-notes: staff_update=staff-2026-11-13-lesson-plan-units -->
- 評量表在學生有使用中的學習計畫時多一區「這堂上完的單元」（該課尚未完成的單元，排在上課日附近的優先、最多先列 8 個、可「顯示全部」，落後的單元標「落後」）。勾選後儲存評量，表單在評量儲存成功之後再呼叫新的 `PUT learning-records/{record}/plan-units`；失敗時評量內容保留並提示再送出一次。
- 新增 `GET learning-plan-units/candidates?class_session_id=`（讀）與 `PUT learning-records/{record}/plan-units`（寫，整組設定這份評量完成的單元；冪等；只取消這份評量勾的單元，別堂課完成的單元不會被動到；完成日 = 該堂課日期）。寫入同時要求：能編輯這份評量（沿用 LearningRecordController::update 的老師歸屬與已核准鎖定規則；主任／超管可改核准後的評量）與學習計畫寫入範圍（主任，或這門課的授課老師，代課老師只讀）；未上課的堂次與作廢評量拒絕。評量的儲存、扣堂與核准程式完全沒動。
- 新增兩個可為空的欄位 `student_plan_units.completed_session_id`、`completed_record_id`（additive，無預設值、無回填）。單元 JSON 多一個 `completed_on`（台北日期），甘特圖單元清單顯示「於 MM/DD 的課完成」。甘特圖手動取消完成會一併清掉來源。
- Refactor: 學習計畫兩個 controller 共用的校區／課程權限判斷移到 `Concerns\ScopesLearningPlanAccess`，行為不變。

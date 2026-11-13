## 2026-11-13 — feat(exams): 學生學習計畫甘特圖 (#4970 step 4b)
<!-- release-notes: staff_update=staff-2026-11-13-student-learning-plan-gantt -->
- 學生管理 › 學習檔案新增「學習計畫」分頁（主任、老師可見，家長看不到）。每門課各一區：還沒有計畫時按「套用教材範本」（只列使用中的範本，優先列出符合這門課科目與年級的；開始日預設今天，結束日不填就排 18 週）。
- 有計畫時，上方顯示完成百分比、落後單元數與距離下一次段考的天數；下方是甘特圖，每個單元一列，橫軸是從計畫開始日起算的每一週，標出每個月、今天與下一次段考。已完成、落後、進行中、未開始都用文字加圖示標示，手動排日期的單元多一個「手動」標籤；日期重疊的單元各自一列、照實畫出。圖只標下一次段考（後端目前只回傳這一筆）。
- 手機上圖可以在自己的框內左右捲動，單元名稱欄固定在左側；圖下方另有單元清單，可以不用捲動圖就勾選完成、調整日期。勾選、調整日期（或「恢復自動排程」）、調整計畫期間、封存計畫都在清單與按鈕上操作；儲存中按鈕暫停，儲存後重新載入。系統拒絕時（例如手動日期落在新的計畫期間外）直接顯示系統的說明。
- 老師對不是自己授課的課，第一次儲存被拒絕後就隱藏所有修改按鈕，只留查看。
- Frontend only: new `StudentLearningPlanGantt.vue` + `lib/learningPlanGantt.js` (date-only day numbers, week columns from `plan_start`); uses the existing `students/{id}/learning-plans`, `learning-plans/{id}`, `curriculum-templates` and `student-classes` endpoints, no API change. Not in this PR: drag-to-reschedule, parent view, evaluation-form hook (step 5), score charts (step 6).

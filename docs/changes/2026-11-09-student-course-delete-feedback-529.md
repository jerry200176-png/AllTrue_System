## 2026-11-09 — fix(students): 刪除課程會顯示處理結果（in-app #529，Refs #5149）
<!-- release-notes: staff_update=staff-2026-11-09-student-course-delete-feedback -->
- 學生頁刪除課程遇到既有規則拒絕時，現在會顯示原因並停止，不再因相容層 fallback 自行重送同一個 DELETE；原有課程與授課、帳務等保護不變。
- 連線中斷會說明結果尚未確認；已成功刪除但清單刷新失敗時，會區分兩者並提醒重新整理，不會再次刪除。
- 回歸包含實際 Vue 頁面、真實 API wrappers 與拒絕／取消／成功／連線／刷新失敗情境。未查詢或修復正式業務資料，實際回報的 422 拒絕原因仍未確認。

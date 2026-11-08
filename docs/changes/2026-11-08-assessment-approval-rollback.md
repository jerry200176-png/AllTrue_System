## 2026-11-08 — fix(learning): 編輯視窗與手機卡片補上退回待審
<!-- release-notes: silent_ship=silent-2026-11-08-assessment-approval-rollback -->
<!-- silent-reason: 本次只交付 draft PR，尚未部署驗證；使用者明示不發通知，公告留待後續發布作業。 -->

- SourceRef: `alltrue:bug_report:523`；Refs #5114。依使用者已核對的匿名證據修復，未取得含個資附件、未讀寫正式資料。
- 根因：列表已有 `canRollbackApproval` / `rollbackApproval`，編輯 footer 與手機卡片未接上同一操作（F1 狀態／入口收尾缺漏；相關歷史 #1621、#3824、#4907）。不是新的角色或帳務政策。
- 三個入口共用 `useLearningRecordRollback`，沿用主任能力判斷、已核准限制、Bearer API 與後端校區／reviewer 守門；不修改後端 rollback、扣堂、出勤、薪資或授權語意。
- 取消與失敗不更新資料；成功僅套用審核欄位，保留表單內未儲存的正文。不重載整張表單。每筆共用提交鎖，並與儲存互斥。
- 非同步回應綁定使用者、校區、角色與視窗 epoch：token 取得後先重查；關閉、重開同筆、改開別筆、換校區或離頁後，舊回應不得修改新上下文或顯示舊錯誤。
- 防再犯：真實頁面元件測試覆蓋桌面／手機、主任／老師、已核准／待審、取消、成功、HTTP／網路失敗、未儲存輸入、重複與過期請求；本機 Playwright 合成頁驗證 footer 可操作。測試不使用正式帳號或資料。既有後端角色／校區回歸測試僅檢視，本次未在本機執行 PHPUnit。
- 回滾邊界：revert 本 PR 的程式 commit 可恢復 UI；UI 回滾不撤銷主任日後透過既有 API 已成功執行的業務操作。
- 交付界線：draft PR，不 merge、不 deploy、不回寫 in-app、不留留言或發通知。正式路徑驗證與回報者驗收均未執行；不得宣稱已上線。

## 2026-10-08 — improved(ux): 課程查找確認視窗與管理課程頁鍵盤操作（C-PR4）
<!-- release-notes: staff_update=staff-2026-10-08-course-dialogs -->
- 刪除課程、暫停／恢復課程改用 AtDialog（role=dialog、Esc、焦點困在視窗內、關閉後回到原按鈕）；刪除、結束課程、補請假、取消補課等破壞性確認預設焦點在「取消」。
- 課程查找內所有原生 confirm() 改為頁內確認視窗（`askConfirm` + `ConfirmDialogHost`）：換師複製、共用方案、放棄未儲存變更、調課／代課／狀態變更預覽、結案、費用偏離、補登過去堂次。
- 管理課程頁（COURSE_MANAGER_V1）：開啟時焦點在標題、Tab 不離開、關閉回到「管理課程」；分頁改為 roving tabindex，←/→/Home/End 切換。
- 只改互動與外觀，不改任何請求內容、排課或金額邏輯。

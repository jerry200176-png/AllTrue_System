## 2026-10-21 — fix(learning): 手機版評量頁不再顯示無效的「列表」按鈕
<!-- release-notes: staff_update=staff-2026-10-21-learning-records-mobile-list-toggle -->

- in-app #417 / GitHub #4267：寬度 ≤640px 時評量頁固定使用卡片檢視，但主任仍看到「列表」按鈕，按下無效。
- 檢視切換只列出在目前寬度選了會生效的模式；手機隱藏「列表」，桌面仍保留列表／卡片兩種檢視，記住的偏好不變。
- 不修改評量狀態、篩選、批次操作、API、權限或既有資料。

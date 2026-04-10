# AllTrue Changelog

此檔記錄「已上線或已合併」的重要變更，讓後續 AI / 工程師可以快速理解最近的系統行為。

## 2026-04-11

### Added
- 新增內部聊天系統（`/api/v1/chat/*`）：
  - 1 對 1 聊天、群組聊天室、訊息列表、已讀標記、未讀統計
  - 資料表：`chat_threads`、`chat_thread_members`、`chat_messages`
  - 前端頁面：`frontend/src/pages/ChatPage.vue`
- 新增 Bug 回報系統（`/api/v1/bugs*`）：
  - 全系統可提交回報、查看自己的回報與處理狀態
  - 資料表：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`
  - 前端頁面：`frontend/src/pages/BugReportsPage.vue`
  - 全域回報入口：`frontend/src/components/BugReportLauncher.vue`

### Changed
- 權限收斂（Bug 回報）：
  - `teacher` / `director`：只能查看自己的回報與留言
  - **僅 `super_admin`**：可更新狀態、指派、內部備註
- UI 修正：
  - 修正 Bug 浮動按鈕與導覽 `?` 按鈕遮擋
  - 修正聊天「選擇對象」顯示空白（統一人員名稱映射 `username/Name/name`）

### Infra / Notes
- Laravel Broadcasting 相關設定與事件已建立：
  - `backend/config/broadcasting.php`
  - `backend/routes/channels.php`
  - `backend/app/Events/ChatMessageCreated.php`
- 前端已完成 deploy 至 `backend/public`（`npm run deploy`）。
- 後端新增測試：
  - `backend/tests/Feature/ChatApiTest.php`
  - `backend/tests/Feature/BugReportApiTest.php`

### Follow-up
- 建議儘快把 WebSocket server（soketi）加入正式常駐程序（systemd/docker）。
- 建議修復 `frontend/node_modules` 權限不一致問題，避免後續 npm install 受阻。

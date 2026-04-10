# Chat + Bug 回報系統交接

本文件是「內部聊天 + Bug 回報」功能的快速交接說明。

## 範圍

- 聊天：校區內 `director/teacher` 的 1 對 1 與群組聊天
- Bug 回報：全系統可提交；一般使用者看自己的回報；僅 `super_admin` 可處理

## 後端檔案

- 路由：`backend/routes/api.php`
- 聊天：
  - `backend/app/Http/Controllers/ChatController.php`
  - `backend/app/Services/ChatService.php`
  - `backend/app/Models/ChatThread.php`
  - `backend/app/Models/ChatThreadMember.php`
  - `backend/app/Models/ChatMessage.php`
- Bug：
  - `backend/app/Http/Controllers/BugReportController.php`
  - `backend/app/Services/BugReportService.php`
  - `backend/app/Models/BugReport.php`
  - `backend/app/Models/BugReportComment.php`
  - `backend/app/Models/BugReportStatusLog.php`
- Broadcasting：
  - `backend/config/broadcasting.php`
  - `backend/routes/channels.php`
  - `backend/app/Events/ChatMessageCreated.php`

## 資料表

- 聊天：
  - `chat_threads`
  - `chat_thread_members`
  - `chat_messages`
- Bug：
  - `bug_reports`
  - `bug_report_comments`
  - `bug_report_status_logs`

## API（摘要）

### Chat
- `GET /api/v1/chat/threads`
- `POST /api/v1/chat/threads/dm`
- `POST /api/v1/chat/threads/group`
- `GET /api/v1/chat/threads/{threadId}/messages`
- `POST /api/v1/chat/threads/{threadId}/messages`
- `POST /api/v1/chat/threads/{threadId}/read`
- `GET /api/v1/chat/unread-count`

### Bug
- `POST /api/v1/bugs`
- `GET /api/v1/bugs`
- `GET /api/v1/bugs/{id}`
- `POST /api/v1/bugs/{id}/comments`
- `POST /api/v1/bugs/{id}/status` (**super_admin only**)
- `POST /api/v1/bugs/{id}/assign` (**super_admin only**)

## 權限規則（目前）

- `teacher`
  - 可：提交 bug、看自己 bug、留言
  - 不可：看他人 bug、狀態流轉、指派、內部備註
- `director`
  - 可：提交 bug、看自己 bug、留言
  - 不可：狀態流轉、指派、內部備註
- `super_admin`
  - 可：看全部 bug、狀態流轉、指派、內部備註

> 注意：所有查詢仍受 `CampusID` / `auth_campus_ids` 隔離。

## 前端檔案

- 聊天頁：`frontend/src/pages/ChatPage.vue`
- Bug 頁：`frontend/src/pages/BugReportsPage.vue`
- Bug 浮動入口：`frontend/src/components/BugReportLauncher.vue`
- API lib：
  - `frontend/src/lib/chatApi.js`
  - `frontend/src/lib/chatRealtime.js`
  - `frontend/src/lib/bugReportsApi.js`
- 整合點：`frontend/src/App.vue`

## 已知注意事項

- `frontend/node_modules` 存在歷史權限不一致（owner 非 `admin`）：
  - 可能導致 `npm install` 失敗（EACCES）
  - 建議維運先統一專案資料夾 owner/group 後再擴充依賴
- WebSocket 已完成程式碼接線，但仍建議設定並啟動正式 soketi 常駐服務。

## 驗收建議

1. 老師提交 bug 後，在「我的 Bug 回報」可看到該單與狀態
2. 老師看不到狀態更新、指派、內部備註 UI
3. `super_admin` 可在 Bug 頁更新狀態與指派
4. Chat 可建立 DM/群組、收發訊息、看到未讀

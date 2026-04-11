# Chat + Bug 回報系統交接（速覽）

> **完整行為、禁止回歸項、頭像 URL 規則**：請讀 **`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（後續 AI 改動前必讀）。

## 範圍

- 聊天：校區內 `director/teacher` 的 1 對 1 與群組聊天
- Bug 回報：全系統可提交；**主任／老師僅能看自己的回報**；**僅 `super_admin` 可更新狀態與內部備註**；**無指派功能**

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
  - `backend/app/Models/BugReportAttachment.php`
- 頭像（全站含聊天）：`backend/app/Support/PublicAvatarUrl.php`
- Broadcasting：
  - `backend/config/broadcasting.php`
  - `backend/routes/channels.php`
  - `backend/app/Events/ChatMessageCreated.php`

## 資料表

- 聊天：`chat_threads`、`chat_thread_members`、`chat_messages`
- Bug：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`、`bug_report_user_reads`（未讀追蹤）

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

- `POST /api/v1/bugs`（JSON 或 multipart + `attachments[]`）
- `GET /api/v1/bugs`
- `GET /api/v1/bugs/unread-badge?branch_id=`（**須排在** `bugs/{id}` **之前**）
- `GET /api/v1/bugs/{id}`
- `POST /api/v1/bugs/{id}/comments`
- `POST /api/v1/bugs/{id}/status`（**middleware `super_admin`**）
- `POST /api/v1/bugs/mark-inbox-seen`（**middleware `super_admin`**）

## 權限規則（目前）

- `teacher` / `director`：提交、**只看自己的** bug、對自己的單留言；不可狀態／內部備註
- `super_admin`：看範圍內全部 bug、狀態、內部備註、mark-inbox-seen

> 查詢仍受 `CampusID` / `auth_campus_ids` 與 `branch_id` 約束。

## 前端檔案

- 聊天頁：`frontend/src/pages/ChatPage.vue`
- Bug 頁：`frontend/src/pages/BugReportsPage.vue`
- Bug 浮動入口：`frontend/src/components/BugReportLauncher.vue`
- API lib：`frontend/src/lib/chatApi.js`、`frontend/src/lib/chatRealtime.js`、`frontend/src/lib/bugReportsApi.js`
- 側欄未讀合併、Bug 紅點、事件：`frontend/src/App.vue`

## 已知注意事項

- `frontend/node_modules` 權限問題：可能需 `frontend/vendor-modules` + Vite alias（見專內說明）
- WebSocket：程式已接線；正式環境建議 soketi 常駐

## 驗收建議

1. 老師／主任只能看到自己的 bug；看不到他人標題出現在列表
2. `super_admin` 可更新狀態、留內部備註；**無指派 UI**
3. 有人回覆（非內部）回報者側欄 Bug 有紅點；開詳情後清除
4. `super_admin` 有新 `new` 單時有紅點；進入 Bug 頁後「新回報」紅點清除
5. 上傳頭像後，聊天與側欄以目前網址可正常載入 `/storage/...` 圖片

# AllTrue Changelog

此檔記錄「已上線或已合併」的重要變更，讓後續 AI / 工程師可以快速理解最近的系統行為。

## 2026-04-11

### Added
- 新增 **`docs/FAQ.md`**：專案常見問題（角色、部署、登入、GitHub 同步、文件索引）；**`docs/DIRECTOR_SCALING_FAQ.md`**：大分校／主任向效能與資料說明
- 新增內部聊天系統（`/api/v1/chat/*`）：
  - 1 對 1 聊天、群組聊天室、訊息列表、已讀標記、未讀統計；訊息／成員帶**頭像 URL**（根相對 `/storage/...`）
  - 資料表：`chat_threads`、`chat_thread_members`、`chat_messages`
  - 前端頁面：`frontend/src/pages/ChatPage.vue`
- 新增 Bug 回報系統（`/api/v1/bugs*`）：
  - 全系統可提交；**主任／老師僅能看自己的回報**；**僅 `super_admin` 可更新狀態與內部備註**
  - **截圖附件**：`bug_report_attachments`，`POST /bugs` 支援 `attachments[]`
  - **側欄紅點**：`GET /bugs/unread-badge`、`POST /bugs/mark-inbox-seen`（super_admin）、`bug_report_user_reads` 與 `User` 收件匣欄位
  - 資料表：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`、`bug_report_user_reads`
  - 前端：`frontend/src/pages/BugReportsPage.vue`、`BugReportLauncher.vue`；`App.vue` 合併 `badgeTypes: ['bugs']` 與 `alltrue-refresh-badges`
- **文件**：**`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`**（後續 AI／工程師改動前必讀，含禁止回歸項）

### Changed
- Bug：**已移除指派**（無 assign API／UI；詳情不再回傳承辦人欄位）
- Bug 狀態：`POST /bugs/{id}/status` 僅 **`middleware super_admin`**（`RequireSuperAdmin`）
- 使用者頭像：`User.AvatarUrl` 上傳後只存 **disk 相對路徑**；API 經 **`App\Support\PublicAvatarUrl`** 輸出，避免 `APP_URL=localhost` 造成聊天／側欄破圖
- UI：Bug 浮動鈕可拖曳；聊天選人顯示名稱正規化

### Infra / Notes
- Laravel Broadcasting：`backend/config/broadcasting.php`、`routes/channels.php`、`ChatMessageCreated`
- 測試：`ChatApiTest.php`、`BugReportApiTest.php`；頭像相關可搭配 `ProfileCenterApiTest.php`

### Follow-up
- 建議把 WebSocket（soketi）納入正式常駐程序。
- 建議修復 `frontend/node_modules` 權限問題（或沿用 vendor-modules alias）。

**完整行為與檢查清單**：`docs/AI_HANDOFF_CHAT_BUG_AVATAR.md`。

# AI 操作手冊：內部聊天、Bug 回報、使用者頭像

**目的**：後續任何 AI／工程師修改下列功能前請讀此檔，避免已修好的行為被改壞。
**關聯**：`docs/CHANGELOG.md`（日期條目）。

---

## 1. 使用者頭像與 `User.AvatarUrl`（高風險）

| 項目 | 規則 |
|------|------|
| **寫入 DB** | `POST /api/v1/me/avatar` 只存 **disk 相對路徑**（如 `avatars/12/avatar.jpg`），不存帶 `APP_URL` 的絕對網址 |
| **回傳前端** | 一律經 `PublicAvatarUrl::forBrowser()`，產出根相對路徑 `/storage/...` |
| **舊資料相容** | DB 內若為 `http(s)://…/storage/…`，`forBrowser` 擷取 `/storage/...` 段 |

**關鍵檔案**：`backend/app/Support/PublicAvatarUrl.php`、`AuthController.php`（uploadAvatar）、`ChatService.php`（publicAvatarUrl）

**禁止**：勿把 `Storage::disk('public')->url()` 結果直接寫入 DB；勿在 API 中把含錯誤 `APP_URL` 的完整 URL 丟給前端。

---

## 2. 內部聊天（Chat）

- 校區內 `director` + `teacher` 可使用（`super_admin` 繞過角色檢查）
- 私訊與群組、訊息分頁、已讀、未讀總數
- 即時廣播 `ChatMessageCreated`（依 soketi 設定）
- 路由：若新增 `GET /api/v1/chat/...` 固定路徑，必須排在參數路由之前
- 測試：`backend/tests/Feature/ChatApiTest.php`

### 後端檔案
`ChatController.php`、`ChatService.php`、`ChatMessageCreated.php`、`channels.php`

### 前端檔案
`ChatPage.vue`、`chatApi.js`、`chatRealtime.js`

---

## 3. Bug 回報

### 3.1 權限（勿回退）

| 角色 | 列表/詳情 | 留言 | 更新狀態 | 內部備註 |
|------|-----------|------|----------|----------|
| teacher/director | **僅自己的**回報 | 僅自己的單 | 不可 | 不可 |
| super_admin | 全校區範圍 | 可 | **可**（專用路由） | **可** |

- **已移除指派功能**：無 `POST /bugs/{id}/assign`
- **更新狀態**僅走 `POST /api/v1/bugs/{id}/status`，middleware 為 `super_admin`

### 3.2 截圖附件
`POST /api/v1/bugs` 支援 multipart，`attachments[]`（最多 5、圖片、≤5MB）

### 3.3 側欄紅點（未讀）

**回報者**：非內部備註的新回覆 or 狀態異動 → 未讀紅點。載入清單即整批標已讀。內部備註不計入。

**super_admin**：`status = new` 且 `id > bug_inbox_last_seen_bug_id` → 紅點。進入 Bug 頁呼叫 `mark-inbox-seen`。

### 3.4 API 路由（注意順序）
- `GET /bugs/unread-badge?branch_id=` — **必須在 `GET /bugs/{id}` 之前**
- `GET /bugs` — 支援 `status`（逗號多值）、`keyword`、`date_from/to`、`sort`、`per_page`（上限 100）
- `POST /bugs/mark-inbox-seen` — super_admin only

### 3.5 Bug 列表 UI（2026-04-15 起）
- 預設快速篩選「待處理」（`new,triaged,in_progress`）
- Quick tabs：待處理 / 全部 / 已關閉
- 進入詳情返回保留查詢狀態

### 關鍵檔案
`BugReportController.php`、`BugReportService.php`、`RequireSuperAdmin.php`、`BugReportsPage.vue`、`BugReportLauncher.vue`、`bugReportsApi.js`

---

## 4. 資料表

- 聊天：`chat_threads`、`chat_thread_members`、`chat_messages`
- Bug：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`、`bug_report_user_reads`

---

## 5. 快速檢查清單（改動前後）

- [ ] 頭像：`AvatarUrl` 不存 `APP_URL` 完整網址；API 頭像欄位經 `PublicAvatarUrl::forBrowser`
- [ ] Bug：主任/老師僅自己的單；狀態僅 `super_admin` + `RequireSuperAdmin`；無指派 API/UI
- [ ] Bug 路由：`bugs/unread-badge` 在 `bugs/{id}` 之前
- [ ] Bug 紅點：內部備註不驅動回報者未讀；super_admin 進入 Bug 頁 mark inbox
- [ ] 前端變更後走 PR → CI → merge → `deploy.yml` 自動部署
- [ ] 測試：GitHub Actions 跑 `ChatApiTest` / `BugReportApiTest` / `ProfileCenterApiTest`

*最後更新：2026-04-27*

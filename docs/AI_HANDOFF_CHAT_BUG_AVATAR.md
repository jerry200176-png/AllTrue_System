# AI 操作手冊：內部聊天、Bug 回報、使用者頭像

**目的**：後續任何 AI／工程師修改下列功能前請讀此檔，避免已修好的行為被改壞。  
**關聯速覽**：`docs/CHAT_BUG_SYSTEM.md`（路徑／檔案索引）、`docs/CHANGELOG.md`（日期條目）。

---

## 1. 使用者頭像與 `User.AvatarUrl`（高風險）

### 曾出問題的狀況

- 上傳頭像後，聊天室／側欄頭像**破圖**。
- 原因：曾把 `Storage::disk('public')->url($path)` 的**完整 URL**（內含 `.env` 的 `APP_URL`，常為 `http://127.0.0.1` 或 `localhost`）寫進資料庫；使用者實際用 **區網 IP、網域或 https** 開網頁時，`<img src="http://localhost/storage/...">` 仍指向錯主機。

### 正確行為（必須維持）

| 項目 | 規則 |
|------|------|
| **寫入 DB** | `POST /api/v1/me/avatar` 成功後，`User.AvatarUrl` 只存 **disk 相對路徑**（例：`avatars/12/avatar.jpg`），**不要**存帶 `APP_URL` 的絕對網址。 |
| **回傳給前端** | 一律經 **`App\Support\PublicAvatarUrl::forBrowser()`**，產出 **根相對路徑** `/storage/...`，讓瀏覽器用「目前網址的主機」載圖。 |
| **舊資料相容** | DB 內若仍是 `http(s)://…/storage/…`，`forBrowser` 會擷取 **`/storage/...`** 一段；若為不含 `/storage/` 的外部圖片 URL 則原樣回傳。 |

### 關鍵檔案

- `backend/app/Support/PublicAvatarUrl.php` — 唯一集中規則。
- `backend/app/Http/Controllers/AuthController.php` — `uploadAvatar()`、`toAvatarUrl()`；刪舊檔用 **`avatarStoredPathForDisk()`**（相容 `/storage/...`、相對路徑、舊完整 URL）。
- `backend/app/Services/ChatService.php` — `publicAvatarUrl()` 委託 `PublicAvatarUrl::forBrowser()`；訊息／成員 JSON 的 `sender_avatar_url`、`thread_avatar_url`、`other_members[].avatar_url` 皆由此產生。

### 禁止事項

- **勿**在新增程式碼裡把 `Storage::disk('public')->url()` 的結果直接寫入 `User.AvatarUrl`。
- **勿**在 API 中把「含錯誤 `APP_URL` 的完整 URL」原樣丟給前端而不經 `PublicAvatarUrl`。

---

## 2. 內部聊天（Chat）

### 行為摘要

- 校區內 `director` + `teacher`（`RequireRole` 含 `super_admin` 繞過）可使用聊天 API。
- 私訊與群組、訊息分頁、已讀、未讀總數；即時廣播 `ChatMessageCreated`（實際 WebSocket 依 `broadcasting`／soketi 設定）。

### 頭像在聊天 UI

- 後端已帶 `sender_avatar_url` 等欄位；前端 `ChatPage.vue` 使用這些 URL；`App.vue` 傳入目前使用者的 `avatarUrl`（與 `/me` 一致）。

### 關鍵檔案

- `backend/app/Http/Controllers/ChatController.php`
- `backend/app/Services/ChatService.php`
- `backend/app/Events/ChatMessageCreated.php`、`backend/routes/channels.php`
- `frontend/src/pages/ChatPage.vue`、`frontend/src/lib/chatApi.js`、`frontend/src/lib/chatRealtime.js`

### 路由順序提醒

- 若有新增 `GET /api/v1/chat/...` 的**固定路徑**，必須排在**參數路由**之前，避免被誤判（與 Bug 的 `bugs/unread-badge` 同理）。

### 測試

- `backend/tests/Feature/ChatApiTest.php`

---

## 3. Bug 回報（Bug Reports）

### 3.1 權限（勿回退）

| 角色 | 列表／詳情 | 留言 | 更新狀態 | 內部備註 |
|------|------------|------|----------|----------|
| `teacher` | **僅自己的**回報 | 僅自己的單 | 不可 | 不可 |
| `director` | **僅自己的**回報（與老師相同，**不能**看全校回報） | 僅自己的單 | 不可 | 不可 |
| `super_admin` | 全校區範圍內（仍受 `branch_id`／`CampusID` 約束） | 可 | **可**（專用路由） | **可** |

- **已移除「指派」功能**：無 `POST /bugs/{id}/assign`、無 `assignBug` API、詳情 JSON **不**回傳 `assigned_to`／`assignee_name`（DB 欄位可仍存在，勿再暴露給前端）。
- **更新狀態**僅能走 **`POST /api/v1/bugs/{id}/status`**，且路由 middleware 為 **`super_admin`**（`RequireSuperAdmin`），**不可**掛在僅 `role:director` 的群組（`RequireRole` 會讓 super_admin 通過 director 群組，造成語意混亂）。

### 3.2 截圖附件

- `POST /api/v1/bugs` 支援 **multipart**：欄位同 JSON，檔案鍵 **`attachments[]`**（最多 5、圖片、單檔 ≤5MB）。
- 資料表：`bug_report_attachments`；檔案在 `storage/app/public/bug-reports/{bug_id}/`。
- 前端：`bugReportsApi.submitBugReport` 有檔案時用 `FormData`；`BugReportLauncher.vue` 可選圖。

### 3.3 側欄紅點（未讀）

**回報者（主任／老師）**

- 條件：**其一**即可計未讀—（1）存在 **非內部備註**、**作者 ≠ 回報者**、且為「新回覆」之留言；（2）**狀態異動紀錄**（`bug_report_status_logs`）中 **`changed_by` ≠ 回報者** 且為新異動（如被改為已解決）。
- 實作：`bug_report_user_reads` 記錄 `(user_id, bug_report_id, read_at)`。回報者 **`GET /api/v1/bugs`（清單）** 時 **`markReporterInboxSeenFromList`**：已進清單頁＝已讀，**側欄未讀應歸零**；`GET /api/v1/bugs/{id}` 時 **`markBugRead`** 再更新該筆。
- SQL 須涵蓋「留言與回報同秒」情境：使用 `c.created_at >= bug_reports.created_at` 與 `c.created_at > COALESCE(read_at, '1970-01-01')`（見 `BugReportService::countReporterReplyUnread`）。
- **內部備註**不計入回報者紅點。

**`super_admin`（新回報）**

- 計算：`status = new` 且 `id > User.bug_inbox_last_seen_bug_id`（僅在 **`bug_inbox_last_seen_at` 非 null** 時套用 id 門檻；從未開過收件匣則顯示範圍內所有 `new`）。
- **`POST /api/v1/bugs/mark-inbox-seen`**：更新 `bug_inbox_last_seen_at` 與 `bug_inbox_last_seen_bug_id = max(bug_reports.id)`（僅 super_admin）。
- 前端：進入 **`active === 'bugs'`** 且角色為 super_admin 時呼叫 mark-inbox-seen，並 `refreshUnreadNotifications()`。

**前端整合**

- `App.vue`：`mergeBugUnreadBadge()` 呼叫 `GET /bugs/unread-badge?branch_id=`，合併進 `badgeByType.bugs`；側欄「Bug 回報」`badgeTypes: ['bugs']`。
- `refreshUnreadNotifications`：主任走通知 API 後再 merge bug；老師僅 merge bug。
- 事件 **`alltrue-refresh-badges`**：`BugReportLauncher` 提交成功、`BugReportsPage` 載入詳情後觸發，避免等 60 秒輪詢。

### 3.4 API 列表（Bug）

- `GET /api/v1/bugs/unread-badge?branch_id=` — 必須註冊在 **`GET /api/v1/bugs/{id}` 之前**。
- `POST /api/v1/bugs/mark-inbox-seen` — `super_admin` middleware。
- `PATCH /api/v1/bugs/{id}/comments/{commentId}/visibility` — `super_admin` 才可切換留言 `is_internal_note`（內部/可見）。

### 3.5 測試

- `backend/tests/Feature/BugReportApiTest.php`（含附件、權限、未讀、mark inbox、內部備註不計入紅點）。

### 關鍵檔案

- `backend/app/Http/Controllers/BugReportController.php`
- `backend/app/Services/BugReportService.php`
- `backend/app/Http/Middleware/RequireSuperAdmin.php`、`backend/app/Http/Kernel.php`（`super_admin` 別名）
- `frontend/src/pages/BugReportsPage.vue`、`frontend/src/components/BugReportLauncher.vue`、`frontend/src/lib/bugReportsApi.js`

---

## 4. 資料庫遷移（與本主題相關）

請以實際 `database/migrations` 為準；下列為功能導向清單：

- 聊天：`chat_threads`、`chat_thread_members`、`chat_messages`
- Bug：`bug_reports`、`bug_report_comments`、`bug_report_status_logs`、`bug_report_attachments`
- 未讀：`bug_report_user_reads`；`User.bug_inbox_last_seen_at`、`User.bug_inbox_last_seen_bug_id`

---

## 5. 修改後建議指令

```bash
# 後端（相關測試）
cd backend && ./vendor/bin/phpunit --filter='ChatApiTest|BugReportApiTest|ProfileCenterApiTest'

# 前端（曾改 frontend/src 等）
cd frontend && npm run deploy
```

---

## 6. 快速檢查清單（改動前後）

- [ ] 頭像：`AvatarUrl` 不存 `APP_URL` 完整網址；API 頭像欄位經 `PublicAvatarUrl::forBrowser`。
- [ ] Bug：主任／老師僅自己的單；狀態僅 `super_admin` + `RequireSuperAdmin` 路由；無指派 API／UI。
- [ ] Bug 路由：`bugs/unread-badge` 在 `bugs/{id}` 之前。
- [ ] Bug 紅點：內部備註不驅動回報者未讀；super_admin 進入 Bug 頁會 mark inbox。
- [ ] 前端變更後執行 `npm run deploy`（依專案規則）。

---

*最後更新：對齊 2026-04-11 起之實作與修復。*

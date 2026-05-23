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

### 3.6 ⛔ AI 處理 in-app bug 回報的 SOP（2026-05-17 起，違反 = 重複問使用者）

```
1. 一定要先撈 attachments：
   SELECT id, stored_path, original_name FROM bug_report_attachments
    WHERE bug_report_id = ?;
   有附件 → SCP 到 /tmp 看完再決定根因。
   /home/admin/backend/storage/app/public/<stored_path>

2. 一定要看 status_logs / comments 全部歷史：
   - 該 bug 是不是之前另一個人已經回過了？
   - 是不是有同主題的舊單已 resolved（同性質回歸）？
     SELECT * FROM bug_reports
      WHERE reporter_user_id = ? ORDER BY id DESC;

3. 一定要先看 reporter 的 CampusID 與當時提交分校：
   - 不同分校的舊單會被分校過濾擋住，不是資料遺失（見 §R51）

4. 留言／開 GitHub issue 之前自己跑一次 SQL 驗證假設，
   不要只用「程式碼推論」就通知使用者。

5. 開 GitHub issue 時把 attachment id 一起寫進去
   （image 的描述 / 觀察 / 對應的程式碼行），
   讓未來的 AI 不必重撈也能看懂。
```

**反面範例（2026-05-17 已發生）**：AI 處理 in-app bug #107 時只看 `description`，沒查 `bug_report_attachments`，回覆裡又叫使用者「請補一張截圖」（其實兩張早就附好了）。

### 3.7 In-app Bug 完整生命週期（分診 → 修 code → 回寫系統）

**權威流程**：本節 + §3.6。修程式仍走 `.cursor/rules/bug-fix-plan.mdc`（B1 根因 → Bug Fix Plan → CI → merge）。

**口訣**：**開 GitHub issue 時回系統一次；merge 上線後一定要再回系統一次**。不能只關 GitHub。

#### 狀態機（`BugReportService::VALID_TRANSITIONS`）

| 目前狀態 | 可轉到 |
|----------|--------|
| `new` | `triaged`, `in_progress`, `closed` |
| `triaged` | `in_progress`, `closed` |
| `in_progress` | `resolved`, `closed` |
| `resolved` | `in_progress`, `closed` |
| `closed` | `in_progress` |

- **回報者驗收**（2026-05-16 起）：`resolved` → `closed` 需回報者呼叫 `POST /api/v1/bugs/{id}/reporter-verify`。AI 標 `resolved` 後要請老師在 App 按「確認已修好／問題仍存在」。
- **內部備註**（`is_internal_note=1`）：不驅動回報者未讀紅點；分診給工程師用，不要當成給老師的回覆。

#### Phase A — 分診（收到「看 bug 回報／開 issue」；不改 production 程式碼）

| 步驟 | 動作 |
|------|------|
| A1 | 讀 §3.6：附件、reporter 歷史、跨分校、comments／status_logs |
| A2 | 必要時查業務表驗證假設（`StudentClass`、`ClassSession`…）；高風險帳務先對 `DIRECTOR_PAYMENT_ALERT_RULES.md` |
| A3 | `gh issue create`：title 含現象；body 必含 **in-app #**、**附件 id**、分校、B1 發現、預期 vs 實際 |
| A4 | **回寫 in-app**：`new` → `triaged`；**公開留言**（非 internal）含 GitHub URL |
| A5 | 回報 CEO：in-app # ↔ GitHub # 對照表 |

**分診留言範本（公開）**：已收到 #___、已看附件 #___（若有）、已建 GitHub #___ 追蹤；勿叫補截圖若附件已存在。

#### Phase B — 修復（改 `backend/`／`frontend/`）

| 步驟 | 動作 |
|------|------|
| B1 | [BUG] 根因確認 → 使用者批准（見 `bug-fix-plan.mdc` §0） |
| B2 | Bug Fix Plan → 批准 → `fix/<slug>` branch → 測試 RED → 改 code → CI 綠 → PR |
| B3 | PR body：`Closes #<github>`（或 Epic 用 `Refs`，見 PR 模板） |
| B4 | merge → `deploy.yml` → `GET /api/v1/health`（前端有改再查 `version.json`） |

#### Phase C — 上線後回寫 in-app（與 B4 綁定）

| 步驟 | 動作 |
|------|------|
| C1 | `in_progress` → `resolved`（若仍在 `triaged`，先轉 `in_progress` 再轉 `resolved`） |
| C2 | **公開留言**：已上線、請至 Bug 回報頁按「確認已修好」或「問題仍存在」 |
| C3 | 回報者 `reporter-verify` 通過後系統才會 `closed`；若「仍存在」→ 回到 B1，重開或新開 GitHub issue |
| C4 | `docs/CHANGELOG.md` 一行（有 deployable 修復時） |

**上線留言範本（公開）**：#___ 已於 YYYY-MM-DD 上線（PR #___）。請您更新頁面後按「確認已修好」；若仍有問題請按「問題仍存在」並簡述，我們會再追。

#### 系統回覆（寫入 `bug_report_comments`／`bug_report_status_logs`）

優先順序：

1. **super_admin UI**（`BugReportsPage`，`User.type=S`）— 留言 + 更新狀態。
2. **API**（Bearer token）：`POST /api/v1/bugs/{id}/comments`、`POST /api/v1/bugs/{id}/status`（status 僅 super_admin）。
3. **維運分診**（僅 Phase A／C 留言與狀態，禁止跑測試）：Pi 上可用 `php artisan tinker --execute="App\Services\BugReportService::changeStatus(...); App\Services\BugReportService::addComment(...);"`；`changed_by`／`author_user_id` 用 super_admin。⛔ 不可在 Pi 跑 `php artisan test` 或 `config:clear`（見 P0）。

#### Definition of Done（in-app bug 任務）

- [ ] §3.6 資料已撈（含附件 id 寫進 GitHub issue）
- [ ] GitHub issue 已開；in-app 已 `triaged` + 公開回覆含連結
- [ ]（若修 code）CI 綠、已 merge、health OK
- [ ]（若修 code）in-app 已 `resolved` + 公開回覆請回報者驗收
- [ ]（可選）CHANGELOG 已記

#### 雙軌對照（避免只做一半）

| 軌道 | 分診後 | 修完上線後 |
|------|--------|------------|
| GitHub | issue 開著，`status:ready` 或 `status:needs-decision` | PR `Closes #nnn`；必要時補 comment |
| In-app | `triaged` + 公開回覆 | `resolved` + 公開回覆 → 等驗收 → `closed` |

**相關防再犯**：`docs/AI_REGRESSION_LESSONS.md` §R51（分診前必查附件）、§R53（上線後必回 in-app）。

### 3.8 公開留言寫作：白話優先（給老師／主任）

**權威規則**：`.cursor/rules/user-facing-communication.mdc`（always-applied，本節是同步副本之導引）。

**核心原則**：寫 `is_internal_note=false` 的留言（會通知回報者），一律白話。技術術語留給 GitHub issue 或內部備註。

**禁止關鍵字**（漏在公開留言裡 = 違規）：
- 欄位／表名：`rate_unit`、`VoidedAt`、`ClassSession.Status`、`StudentClass`、`schedules`
- 程式路徑：`XxxController::method()`、`XxxService`
- SQL / 工具：`SELECT`、`Laravel`、`migration`、`CI`、`deploy.yml`、`Phase A/B/C`、`R51`
- 縮寫：`B1`、`P0`、`PR #nnn`（純內部）

**送出前自我檢查**（30 秒）：
1. 老師／主任看得懂每一句嗎？
2. 我們會做什麼，有講清楚嗎？
3. 要他回覆的話，有列清楚問題嗎？
4. 有沒有欄位名 / SQL / class 名漏出去？

**對照範例**：

❌ 反例 → `蔡羽絜 SC#182 的 rate_unit 欄位被存成 hour，Charge 計算為 1100 × (8 × 2) = 17600...已建 GitHub #509 P1`

✅ 正例 → `已看您的截圖。系統把「1100」當成「每小時」的費用，所以 8 堂 × 2 小時 = 17,600 元。如果原意是「每一堂 1100」，正確應是 8,800 元才對。我們發現有 12 個學生課程是一樣的情況...麻煩確認三件事：1) ... 2) ... 3) ...`

完整對照表與更多範例見 `.cursor/rules/user-facing-communication.mdc`。

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
- [ ] AI 處理 bug 前：先撈 `bug_report_attachments` + reporter 全部歷史 + reporter 跨分校紀錄（§3.6）
- [ ] 分診：§3.7 Phase A（開 issue + in-app `triaged` + 公開回覆）
- [ ] 修完上線：§3.7 Phase C（`resolved` + 公開回覆 + 等回報者驗收）（§R53）
- [ ] 公開留言：§3.8 白話檢查（無欄位名 / SQL / class 名漏出）

*最後更新：2026-05-24*

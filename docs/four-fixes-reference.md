# 四項修正對照（Student / Billing / Branches / Modal）

方便在專案裡快速找到對應程式碼位置。

---

## 1. Student 建立與列表（欄位對應）

**檔案：** `backend/app/Http/Controllers/StudentController.php`

| 項目 | 位置 | 說明 |
|------|------|------|
| **Store：前端 → 後端** | `mapFrontendPayload()` 約 22–52 行 | `school`→SchoolName、`grade`→ClassID（P1/J1 等對照表）、`phone`→Phone、`parent_phone`→Phone 且→TelegramID1 |
| **Index：後端 → 前端** | `index()` 內 `getCollection()->transform()` 約 107–134 行 | SchoolName→`school`、ClassID→`grade`（$gradeMap）、Phone→`phone`、TelegramID1→`parent_phone` |

列表顯示的 school / grade / phone 即由上述 index 的 transform 設定。

---

## 2. 月繳計費（學習紀錄核准時的堂數）

**檔案：** `backend/app/Http/Controllers/LearningRecordController.php`

| 項目 | 位置 | 說明 |
|------|------|------|
| **核准時扣課邏輯** | 私有方法 `deductRemainingSessions()` 約 215–246 行 | 一律增加 **UsedSessions**；僅當 `ScheduleMode === 'count'` 時才扣 **RemainingSessions**，月繳生不扣剩餘堂數 |

---

## 3. 主任註冊 / 分校列表（公開 API）

| 項目 | 檔案 | 位置 |
|------|------|------|
| **公開 branches 路由** | `backend/routes/api.php` | 約 30–31 行：`Route::get('branches', [CampusController::class, 'listPublic']);`（無 auth） |
| **實作** | `backend/app/Http/Controllers/CampusController.php` | 方法 `listPublic()` |
| **前端取用** | `frontend/src/lib/useBranches.js` | `loadBranches()` 會請求 `${API_BASE}/branches`（即 `/api/v1/branches`） |

後端 `listPublic` 回傳的校區列表會顯示在主任註冊等 UI。

---

## 4. Modal 可捲動（Save 按鈕可觸及）

**檔案：** `frontend/src/styles.css`

| 項目 | 位置 | 說明 |
|------|------|------|
| **Modal 樣式** | 約 558–567 行 `.modal` | `max-height: 90vh;`、`overflow-y: auto;`，長表單可捲動，Save 不會被擋住 |

---

若部署後仍看不到變更，請確認：  
- 後端：已執行 `reset_cache.php` 或 `php artisan optimize:clear`  
- 前端：已重新 build 並部署到 `backend/public`，必要時強制重新整理（Ctrl+Shift+R）。

# ARCH：資安強化 HIGH（SEC-002～006）— 技術設計文件

**版本**：v1.0  
**日期**：2026-04-23  
**關聯 PRD**：`phase3_security_hardening_high_prd_2026-04-23.md`

---

## 1. 實作確認項（來自 ARCH 探索）

| 問題 | 結論 |
|------|------|
| Apache `mod_headers` 是否啟用？ | ✅ `headers_module (shared)` 已啟用，.htaccess 標頭立即生效 |
| `ProfileController::resetPassword` 有無 min 限制？ | N/A — 密碼由 `generateInitialPassword()` 系統隨機生成，無使用者輸入，FR-010 不適用 |
| `ProfileController::store` 有 min:4？ | ✅ 是，line 371：`'password' => 'required|string|min:4|max:128'` → 需升為 min:8 |
| `ProfileController::update` 有 min:4？ | ✅ 是，line 715：`'password' => 'nullable|string|min:4|max:128'` → 需升為 min:8 |
| `auth/register` 現有 throttle？ | ❌ 無 |
| `directors/register` 現有 throttle？ | ❌ 無 |
| `auth/forgot-password` 現有 throttle？ | ❌ 無 |
| `swipe-rfid` 現有 throttle？ | ❌ 無 |
| `parent/login` 已有 throttle？ | ✅ `throttle:5,10`（已存在，不動） |

---

## 2. 修改清單（完整 diff 邊界）

### 2.1 `routes/api.php`

**修改 4 處，全部為新增 `->middleware('throttle:N,M')`：**

| 行（現況） | 端點 | 新增 throttle |
|-----------|------|--------------|
| 111 | `Route::post('auth/forgot-password', ...)` | `->middleware('throttle:5,60')` |
| 164 | `Route::post('swipe-rfid', ...)` | `->middleware('throttle:30,1')` |
| 165 | `Route::post('auth/register', ...)` | `->middleware('throttle:10,10')` |
| 168 | `Route::post('directors/register', ...)` | `->middleware('throttle:10,10')` |

**throttle 參數說明：**
- `throttle:N,M` = N 次請求 / M 分鐘 / per IP
- Laravel 內建 `throttle` middleware 自動附加 `X-RateLimit-Limit`、`X-RateLimit-Remaining`、`Retry-After` header

### 2.2 `app/Http/Controllers/AuthController.php`

**修改 2 處：**

| 行（現況） | 說明 | 修改內容 |
|-----------|------|---------|
| 134 | `register()` validation | `min:4` → `min:8` |
| 317 | `updateMe()` validation（change password） | `min:6` → `min:8` |

### 2.3 `app/Http/Controllers/DirectorAccountController.php`

**修改 1 處：**

| 行（現況） | 說明 | 修改內容 |
|-----------|------|---------|
| 10（register validation） | `'password' => 'required|string|min:4'` | `min:4` → `min:8` |

### 2.4 `app/Http/Controllers/ProfileController.php`

**修改 2 處（PRD 未列但 ARCH 探索發現）：**

| 行（現況） | 說明 | 修改內容 |
|-----------|------|---------|
| 371 | `store()` teacher password | `min:4` → `min:8` |
| 715 | `update()` teacher password | `min:4` → `min:8` |

### 2.5 `public/.htaccess`

**新增 6 項 HTTP 安全標頭**，使用 `mod_headers` 的 `always set`（確保所有 status code 均加入，包含 4xx/5xx）：

| 標頭 | 值（依 OWASP 2026 建議） |
|------|------------------------|
| Strict-Transport-Security | `max-age=31536000; includeSubDomains` |
| X-Frame-Options | `DENY` |
| X-Content-Type-Options | `nosniff` |
| Content-Security-Policy | `frame-ancestors 'none'; default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests` |
| Referrer-Policy | `strict-origin-when-cross-origin` |
| Permissions-Policy | `camera=(), microphone=(), geolocation=(), payment=()` |

> `X-XSS-Protection` 不加入——已於 Chrome 78+ 廢除，OWASP 2026 明確建議移除。

---

## 3. 無異動項目確認

| 項目 | 說明 |
|------|------|
| DB Migration | 無，零 schema 變更 |
| 前端 Vue 元件 | 無，密碼長度 422 錯誤由現有前端錯誤處理邏輯顯示 |
| `parent/login`（throttle:5,10） | 已存在，不動 |
| `parent/login-line`（throttle:30,10） | 已存在，不動 |
| `auth/login` RateLimiter（controller 層） | 已存在 5次/15分，不動 |
| `ProfileController::resetPassword` | 系統隨機密碼，不涉及 min 限制，不動 |

---

## 4. API Contract（異動端點）

所有端點的 Request / Response 結構**完全向後相容**，只有新增 throttle 回應：

### 新增：HTTP 429 Too Many Requests（所有被 throttle 端點）

```
HTTP/1.1 429 Too Many Requests
Retry-After: 543
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 0
Content-Type: application/json

{ "message": "Too Many Requests" }
```

### 修改：HTTP 422（密碼 min:8 違反時）

舊回應（min:4）：
```json
{ "errors": { "password": ["The password must be at least 4 characters."] } }
```

新回應（min:8）：
```json
{ "errors": { "password": ["The password must be at least 8 characters."] } }
```

---

## 5. 執行順序（DEV 必須依序）

```
Step 1  public/.htaccess         加入 6 項安全標頭（最安全，改錯可立即 revert）
Step 2  routes/api.php           4 條路由加 throttle
Step 3  AuthController.php       register min:4→8, updateMe min:6→8
Step 4  DirectorAccountController.php  register min:4→8
Step 5  ProfileController.php    store + update min:4→8
Step 6  本機驗證 curl -I localhost/api/v1/health | grep Strict
```

---

## 6. 測試策略（TEST phase 實作）

### 新測試檔案

`tests/Feature/SecurityHardeningTest.php`（新建，使用 RefreshDatabase）

### 測試情境清單

| 測試名稱 | 覆蓋 FR |
|---------|---------|
| `register_throttle_blocks_after_10_requests` | FR-001 |
| `directors_register_throttle_blocks_after_10_requests` | FR-002 |
| `forgot_password_throttle_blocks_after_5_requests` | FR-003 |
| `swipe_rfid_throttle_blocks_after_30_requests` | FR-004 |
| `throttle_response_contains_retry_after_header` | FR-005 |
| `register_rejects_password_shorter_than_8` | FR-007 |
| `register_accepts_password_exactly_8` | FR-007 |
| `directors_register_rejects_password_shorter_than_8` | FR-008 |
| `update_me_rejects_password_shorter_than_8` | FR-009 |
| `profile_store_rejects_password_shorter_than_8` | ProfileController store |
| `profile_update_rejects_password_shorter_than_8` | ProfileController update |
| `existing_short_password_account_can_still_login` | FR-011（regression） |
| `security_headers_present_in_api_response` | FR-012～017（.htaccess 在測試環境可能不生效，改驗 header 存在性） |

> **注意**：.htaccess 安全標頭在 `php artisan serve` / PHPUnit HTTP client 環境下可能不生效（需 Apache）。
> `security_headers_present_in_api_response` 使用 `markTestSkipped` 並在 CI 的 `[OPS]` 用 `curl` 驗證。

### Throttle 測試技巧

Laravel 的 `throttle` middleware 在測試中需清除 cache 狀態，使用：
- `RateLimiter::clear()` 在每個 test 的 `setUp`
- 或 `Cache::flush()` 確保測試隔離

---

## 7. 風險與回滾邊界

| 風險 | 應對 |
|------|------|
| .htaccess 語法錯誤導致 Apache 500 | `<IfModule mod_headers.c>` 包裹標頭，缺模組時靜默略過；部署後立即 `curl -I` 驗證 |
| 密碼 min:8 影響現有使用流程 | 只改 validation rule，`password_verify()` 不受影響；login 路徑無任何改動 |
| throttle 計數誤計（cache 問題） | throttle 是防御性措施，誤計最多讓合法請求多等；不會造成資料損失 |

**回滾方式：**
```bash
git revert HEAD  # 一次 revert 所有改動
# 無需 migration rollback
```

---

## 8. DEV 實作規格摘要

| # | 檔案 | 動作 | 數量 |
|---|------|------|------|
| 1 | `public/.htaccess` | 新增 6 行 Header 設定 | +6 行 |
| 2 | `routes/api.php` | 新增 throttle middleware | +4 處 |
| 3 | `AuthController.php` | min:4→8（register）、min:6→8（updateMe） | +2 處 |
| 4 | `DirectorAccountController.php` | min:4→8（register） | +1 處 |
| 5 | `ProfileController.php` | min:4→8（store + update） | +2 處 |
| 6 | `tests/Feature/SecurityHardeningTest.php` | 新建，13 個測試 | 新增 |

**異動規模**：5 個檔案，約 25 行修改，1 個新測試檔案

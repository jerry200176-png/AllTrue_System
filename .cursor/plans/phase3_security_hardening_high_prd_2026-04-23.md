# PRD：資安強化 — HIGH 等級修復（SEC-002～SEC-006）

## 1. 文件資訊

| 欄位 | 內容 |
|------|------|
| 功能名稱 | AllTrue 資安強化 v1.0 — HIGH 等級五項修復 |
| 版本 | v1.0 |
| 狀態 | DRAFT |
| 目標角色 | [FEATURE] / [TEST] / [REVIEW] / [DOCS] / [OPS] Agent |
| 建立日期 | 2026-04-23 |
| 關聯審查 | security-audit-2026-04-23.canvas.tsx |

---

## 2. 目標與業務背景

### 痛點（非技術語言）

AllTrue 的登入、註冊、忘記密碼、刷卡等入口目前沒有速率限制，任何人都能以腳本每秒打上千次請求：

- **帳號被暴力破解**：4 碼密碼 + 無限速 = 幾秒內窮舉完畢
- **刷卡機遭模擬攻擊**：攻擊者取得分校 Token 後可枚舉出所有學生 RFID 號碼
- **帳號注冊垃圾資料**：無限制建立假帳號，汙染 DB、耗盡資源
- **瀏覽器安全標頭缺失**：使用者若連到釣魚網站，攻擊者可利用 clickjacking 覆蓋真實介面騙取密碼

台灣《個人資料保護法》要求「以適當之技術措施保護個人資料安全」，補習班管理系統儲存學生姓名、電話、RFID 等個資，現況不符合法遵要求。

### 業務價值

| 指標 | 現況 | 目標 |
|------|------|------|
| 登入端點每分鐘可接受暴力請求數 | 無限制 | ≤ 5 次 / IP / 15 分鐘（已有 controller 層，補 route 層雙重防護） |
| 註冊端點速率限制 | 無 | ≤ 10 次 / IP / 10 分鐘 |
| 忘記密碼端點速率限制 | 無 | ≤ 5 次 / IP / 60 分鐘 |
| RFID 暴力枚舉可行性 | 可行 | 加 throttle + 連續失敗即封鎖 |
| HTTP 安全標頭覆蓋率 | 0 / 6 | 6 / 6（HSTS、X-Frame-Options、X-Content-Type-Options、CSP frame-ancestors、Referrer-Policy、Permissions-Policy） |
| 密碼最低長度 | 4 碼 | 8 碼 |

---

## 3. 範圍

### In Scope

| ID | 修復項目 |
|----|---------|
| SEC-002 | `POST /api/v1/auth/register` 及 `POST /api/v1/directors/register` 加路由層 throttle |
| SEC-003 | `POST /api/v1/auth/forgot-password` 加路由層 throttle |
| SEC-004 | 所有密碼相關欄位最小長度提升至 8 碼（register / directors/register / change password） |
| SEC-005 | `public/.htaccess` 加入 6 項 HTTP 安全標頭 |
| SEC-006 | `POST /api/v1/swipe-rfid` 加路由層 throttle |

### Out of Scope

- SEC-001（/api/debug-log）：已確認為獨立 hotfix，不納入本 PRD
- SEC-007 ～ SEC-016：Medium / Low 等級，待後續 Sprint
- 密碼複雜度（大小寫 + 數字強制）：本次只改最低長度，複雜度為 NFR 備選
- 2FA 雙因素驗證：未在本次範圍
- 前端 UI 密碼欄位驗證訊息變更：最小長度 8 碼的前端提示列為 Out of Scope（後端 422 已足夠）

---

## 4. RACI

| 角色 | 代表 | RACI |
|------|------|------|
| AI Agent（實作） | [FEATURE] Agent | R |
| AI Agent（測試） | [TEST] Agent | R |
| AI Agent（審查） | [REVIEW] Agent | R |
| AI Agent（文件） | [DOCS] Agent | R |
| AI Agent（部署） | [OPS] Agent | R |
| 人類（可選閱讀） | 系統管理員 | I |

## 4b. Dependencies

| 類型 | 說明 | 狀態 |
|------|------|------|
| 前置 PR | 無，本次修改不依賴任何未合併功能 | N/A |
| 外部服務 | 無 | N/A |
| 環境前提 | Laravel RateLimiter 使用 cache driver（目前預設 file），無需 Redis | 已存在 |
| DB Migration | 無，本次無 schema 變更 | N/A |

---

## 5. User Stories

### US-001：rate limit 防護

**As a** 系統管理員，  
**I want** 所有公開端點（register / forgot-password / swipe-rfid）都有速率限制，  
**so that** 攻擊者無法以腳本暴力破解帳號或枚舉 RFID。

**Acceptance Criteria：**
- AC-001：當同一 IP 在 10 分鐘內對 `/auth/register` 發出超過 10 次請求時，第 11 次起應收到 `HTTP 429` 與 `Retry-After` header
- AC-002：當同一 IP 在 60 分鐘內對 `/auth/forgot-password` 發出超過 5 次請求時，應收到 `HTTP 429`
- AC-003：當同一 IP 在 1 分鐘內對 `/swipe-rfid` 發出超過 30 次請求時，應收到 `HTTP 429`
- AC-004：429 回應包含 `Retry-After` header 告知剩餘等待秒數

### US-002：密碼強度

**As a** 補習班管理員，  
**I want** 系統要求密碼至少 8 碼，  
**so that** 暴力破解難度大幅提升，符合業界標準。

**Acceptance Criteria：**
- AC-005：嘗試以 7 碼以下密碼建立帳號時，應收到 `HTTP 422` 及「密碼至少需要 8 個字元」訊息
- AC-006：已有 4～7 碼密碼的舊帳號，**登入不受影響**（密碼長度限制只影響新建或修改密碼）

### US-003：HTTP 安全標頭

**As a** 使用補習班系統的老師/主任，  
**I want** 瀏覽器自動阻擋 clickjacking 及 MIME sniffing 攻擊，  
**so that** 即使不慎瀏覽釣魚網站，核心資料也不會被竊取。

**Acceptance Criteria：**
- AC-007：所有 HTTP 回應包含 `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- AC-008：所有 HTTP 回應包含 `X-Frame-Options: DENY`
- AC-009：所有 HTTP 回應包含 `X-Content-Type-Options: nosniff`
- AC-010：所有 HTTP 回應包含 `Content-Security-Policy: frame-ancestors 'none'; default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests`
- AC-011：所有 HTTP 回應包含 `Referrer-Policy: strict-origin-when-cross-origin`
- AC-012：所有 HTTP 回應包含 `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()`

## 5b. UI/UX 精緻化需求

本 PRD 僅涉及後端路由 throttle、.htaccess 安全標頭、及密碼 validation，**無前端 Vue 元件異動**，UI/UX 精緻化章節**不適用**。

唯一可見的前端影響：
- 密碼改為 min:8 後，舊前端登入不受影響（長度驗證只在 register / change password 路徑）
- 429 回應由 Laravel 自動格式化，前端現有的錯誤處理邏輯已可處理（顯示 `message` 欄位）

---

## 6. 功能需求 FR

### SEC-002 / SEC-003 / SEC-006：Rate Limiting

| ID | 需求 |
|----|------|
| FR-001 | 系統應對 `POST /api/v1/auth/register` 套用路由層 throttle：**10 次 / IP / 10 分鐘** |
| FR-002 | 系統應對 `POST /api/v1/directors/register` 套用路由層 throttle：**10 次 / IP / 10 分鐘** |
| FR-003 | 系統應對 `POST /api/v1/auth/forgot-password` 套用路由層 throttle：**5 次 / IP / 60 分鐘** |
| FR-004 | 系統應對 `POST /api/v1/swipe-rfid` 套用路由層 throttle：**30 次 / IP / 1 分鐘** |
| FR-005 | 超過限制的請求，回應 HTTP 429，Body 含 `{"message":"Too Many Requests"}`，Header 含 `Retry-After: <秒數>` 及 `X-RateLimit-Limit` / `X-RateLimit-Remaining` |
| FR-006 | throttle 計數器以 IP 為 key，與 auth/login 的 RateLimiter（以 account+IP 為 key）獨立，不相互影響 |

### SEC-004：密碼最低長度

| ID | 需求 |
|----|------|
| FR-007 | `POST /api/v1/auth/register` 的 `password` 欄位，最低長度改為 **8 碼**（原 min:4） |
| FR-008 | `POST /api/v1/directors/register` 的 `password` 欄位，最低長度改為 **8 碼**（原 min:4） |
| FR-009 | `PUT /api/v1/me`（change password）的 `password` 欄位，最低長度改為 **8 碼**（原 min:6） |
| FR-010 | `POST /api/v1/profiles/{id}/reset-password` 的密碼欄位（如有 min 限制），最低長度改為 **8 碼** |
| FR-011 | 現有帳號密碼若為 4～7 碼，登入功能**不得受影響**（只改 validation，不重新驗證舊密碼長度） |

### SEC-005：HTTP 安全標頭

| ID | 需求 |
|----|------|
| FR-012 | 系統應在所有 HTTP 回應加入 `Strict-Transport-Security: max-age=31536000; includeSubDomains` |
| FR-013 | 系統應在所有 HTTP 回應加入 `X-Frame-Options: DENY` |
| FR-014 | 系統應在所有 HTTP 回應加入 `X-Content-Type-Options: nosniff` |
| FR-015 | 系統應在所有 HTTP 回應加入 `Content-Security-Policy: frame-ancestors 'none'; default-src 'self'; base-uri 'self'; form-action 'self'; object-src 'none'; upgrade-insecure-requests` |
| FR-016 | 系統應在所有 HTTP 回應加入 `Referrer-Policy: strict-origin-when-cross-origin` |
| FR-017 | 系統應在所有 HTTP 回應加入 `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()` |
| FR-018 | 安全標頭應在 Apache 層（`.htaccess`）設定，確保靜態資源、SPA、API 全面覆蓋 |

---

## 7. 非功能需求 NFR

| ID | 需求 | 指標 |
|----|------|------|
| NFR-001 | 正常請求不受影響 | 現有 15 個 Feature Test 全數通過，無 regression |
| NFR-002 | throttle 計數儲存在 Laravel cache（file driver 可用，無需 Redis 前提） | CI 環境可驗證 |
| NFR-003 | 安全標頭不破壞 SPA 正常載入 | `curl -I https://daan.lifenet.com.tw` 同時含六項標頭 |
| NFR-004 | 密碼長度改動不影響現有使用者登入 | Feature Test 驗證舊 4 碼密碼仍可登入 |
| NFR-005 | throttle 回應時間 < 5 ms（從 cache 讀取計數器） | 可接受，cache file 讀取極快 |
| NFR-006 | 修改不觸發任何 DB migration | 零 schema 變更 |

**降級策略：**
- 若 cache driver 不可用（disk 滿），Laravel throttle 會拋 `Exception`，由現有的 `Handler` 回傳 500；此為 acceptable degradation（fail-safe 比 fail-open 安全）

---

## 8. 技術方向

### 修改對象（檔案層級，禁止出現 code）

| 檔案 | 修改類型 | 說明 |
|------|---------|------|
| `routes/api.php` | 新增 | 在 SEC-002/003/006 的路由加 `->middleware('throttle:N,M')` |
| `app/Http/Controllers/AuthController.php` | 修改 | FR-007 / FR-009：password validation min:4 → min:8 |
| `app/Http/Controllers/DirectorAccountController.php` | 修改 | FR-008：password validation min:4 → min:8 |
| `app/Http/Controllers/ProfileController.php` | 修改 | FR-010：reset-password 的密碼 min 值（如有） |
| `public/.htaccess` | 新增 | FR-012 ～ FR-017：六項 HTTP 安全標頭 |

### 架構取捨說明

1. **throttle 位置：路由層 vs 控制器層**：路由層 `->middleware('throttle:N,M')` 直接在 HTTP kernel 執行，比控制器層 `RateLimiter` 更早攔截，減少不必要的資料庫查詢。auth/login 已有 controller 層保護，本次路由層是雙重防護（defence in depth）。

2. **安全標頭位置：.htaccess vs Laravel Middleware**：`.htaccess` 覆蓋所有請求（靜態資源 + SPA + API），而 Laravel Middleware 只覆蓋進入 PHP 的請求。本系統 Apache 直接服務靜態 SPA，選 `.htaccess` 全覆蓋。

3. **密碼長度：8 碼 vs NIST SP 800-63B 建議 12 碼**：現有帳號分佈未知，從 4/6 直接跳 12 會造成大量帳號被迫改密碼（觸發 MustChangePassword 流程）。本次選 8 碼作為最低合規門檻，下一次強化再評估 12 碼。

## 8b. Decision Log

| 日期 | 決策 | 考慮過的替代方案 | 選擇理由 |
|------|------|----------------|---------|
| 2026-04-23 | 安全標頭放 .htaccess | Laravel Middleware（SecureHeaders）| .htaccess 覆蓋靜態資源；Laravel Middleware 需安裝 third-party package |
| 2026-04-23 | throttle key 以 IP 為主 | IP + account 複合 key | register/forgot-password 尚無帳號資訊可用，IP 是唯一可用維度 |
| 2026-04-23 | 密碼 min 改為 8 碼 | 直接改 12 碼（NIST 建議） | 避免強迫全部現有 4～7 碼帳號立即更換密碼，分兩階段推進 |
| 2026-04-23 | swipe-rfid throttle 30/1 分鐘 | 更低的 10/1 | RFID 讀卡機正常使用最快約每 3 秒一次刷卡；30/分鐘 為合法讀卡機速度 10 倍緩衝 |

---

## 9. 資安與存取控制

### STRIDE 快評（本次修改面）

| 維度 | 影響 | 說明 |
|------|------|------|
| Spoofing | 降低 | throttle 讓攻擊者無法快速測試偷來的密碼清單 |
| Tampering | 無 | 本次不修改資料寫入邏輯 |
| Repudiation | 無 | 現有 Log 不變 |
| Information Disclosure | 降低 | 安全標頭阻擋 MIME sniffing / clickjacking |
| Denial of Service | 降低 | throttle 限制 register/forgot-password 可被用於填垃圾的路徑 |
| Elevation of Privilege | 無 | 本次不修改角色邏輯 |

### PII 影響

- throttle key 存入 cache 的是 hash 後的 IP，無學生/老師個人資料
- 密碼長度提升不涉及 PII
- 安全標頭不涉及 PII

---

## 10. QA 驗收

### Happy Path

| # | 測試情境 | 預期結果 |
|---|---------|---------|
| HP-001 | 正常 register（密碼 ≥ 8 碼），首次請求 | HTTP 201，帳號建立成功 |
| HP-002 | 正常 forgot-password，首次請求 | HTTP 200/204 |
| HP-003 | 正常 swipe-rfid（合法 RFID + Token），單次請求 | HTTP 200 |
| HP-004 | curl -I 任意 API 回應 | 含所有 6 項安全標頭 |
| HP-005 | 舊 4 碼密碼帳號嘗試登入 | HTTP 200，登入成功（不受影響） |

### Edge Cases

| # | 測試情境 | 預期結果 |
|---|---------|---------|
| EC-001 | register 密碼 7 碼 | HTTP 422，message 含「至少 8 個字元」 |
| EC-002 | 同 IP 對 register 連打 11 次（10 分鐘內） | 第 11 次 HTTP 429，含 `Retry-After` |
| EC-003 | 同 IP 對 forgot-password 連打 6 次（60 分鐘內） | 第 6 次 HTTP 429 |
| EC-004 | 同 IP 對 swipe-rfid 連打 31 次（1 分鐘內） | 第 31 次 HTTP 429 |
| EC-005 | throttle 達上限後等待 decay 過後再次請求 | HTTP 正常回應（429 解除） |

### Error Cases

| # | 測試情境 | 預期結果 |
|---|---------|---------|
| ERR-001 | cache driver 不可用（模擬） | 系統回傳 500，不 fail-open（不允許無限請求） |
| ERR-002 | .htaccess mod_headers 未啟用（nginx 環境） | 標頭不存在，但系統不 crash（降級可接受） |

### UI/UX 驗收清單

不適用（本次無前端 UI 異動）。

---

## 11. 上線與維運

### 部署步驟

1. `git pull origin main`
2. 確認 `public/.htaccess` 已更新（Apache mod_headers 需已啟用）
3. `php artisan config:clear`（清除 config cache，讓 throttle 立即生效）
4. **⚠️ 不需要 `php artisan migrate`**（零 schema 變更）
5. `curl -I https://daan.lifenet.com.tw/api/v1/health` 確認安全標頭出現

### Feature Flag 策略

本次修改全部為後端防守性 guard，無使用者可見的功能開關：
- throttle：直接生效，無需 feature flag
- 密碼長度：只影響新建/修改密碼，現有帳號無感
- 安全標頭：Apache 層直接生效

### Observability

| 監控項目 | 指標 / log 關鍵字 | 告警閾值 | 負責 Agent |
|---------|----------------|---------|-----------|
| throttle 429 比率 | HTTP 429 response count on register/forgot-password | > 50 次/分鐘（異常攻擊） | [OPS] |
| swipe-rfid 429 | HTTP 429 on swipe-rfid | > 10 次/分鐘（可能為讀卡機異常） | [OPS] |
| 安全標頭存在性 | `Strict-Transport-Security` in response header | 缺失即告警 | [OPS] |

### 回滾方案

- `git revert HEAD`（routes/api.php + AuthController + DirectorAccountController + .htaccess）
- **無需 migration rollback**（零 schema 變更）
- 預估 rollback 時間：< 5 分鐘

---

## 12. 里程碑與優先級

| Phase | 任務 | 優先級 | 執行 Agent |
|-------|------|--------|-----------|
| 1 [PLAN] | 本文件 | P0 | [FEATURE] |
| 2 [ARCH] | 技術設計確認 | P0 | [FEATURE] |
| 3 [DEV] | 實作 SEC-002 ～ SEC-006 | P0 | [FEATURE] |
| 4 [TEST] | Pest Feature Test（新增 + regression） | P0 | [TEST] |
| 5 [REVIEW] | Code Review + STRIDE 靜態審查 | P0 | [REVIEW] |
| 6 [DOCS] | CHANGELOG 更新 | P1 | [DOCS] |
| 7 [OPS] | 部署驗證（curl + header check） | P0 | [OPS] |

---

## 13. 風險 / 假設 / 開放問題

### 風險（已查業界解法）

| 風險 | 等級 | 業界標準解法（來源） | 本專案採行方式 |
|------|------|------------------|--------------|
| throttle key IP 遭 proxy 偽造（X-Forwarded-For 注入） | 中 | TrustProxies middleware（Laravel / GitHub Engineering Blog 2024） | 已有 `TrustProxies` middleware，確認設定正確即可 |
| HSTS + 長 max-age，SSL 憑證到期時全站無法存取 | 中 | 先以短 max-age（300 秒）測試，確認 HTTPS 穩定後再改長（OWASP 建議） | 本次直接設 31536000；若憑證到期風險由 [OPS] 監控 |
| 密碼 min:8 造成現有老師帳號需要改密碼 | 低 | validation 只在 register/change password path，login 不受影響（AWS、GitHub 實作方式） | FR-011 明確要求不影響登入 |
| swipe-rfid throttle 30/min 對讀卡機合法速率過低 | 低 | 讀卡機正常最快 20 次/分鐘（業界刷卡門禁標準）；30/min 有 50% 緩衝（Paxton Access 文件） | 30/min 保留，若現場測試不夠再調整 |
| Apache mod_headers 未啟用（Raspberry Pi 預設值） | 低 | a2enmod headers（Ubuntu Apache 啟用命令） | [OPS] 部署前確認 `sudo apache2ctl -M | grep headers` |

### 假設

- 現有 Apache 伺服器已啟用 `mod_headers` 模組（若不成立，[OPS] 執行 `sudo a2enmod headers && sudo systemctl reload apache2`）
- Laravel cache driver（file）在 Pi 上正常運作（若不成立，throttle 拋 500，CI 測試會失敗）

### 開放問題

| 問題 | 狀態 |
|------|------|
| `ProfileController::resetPassword` 的密碼欄位是否有 min 限制？ | [AI-RESOLVABLE]：DEV phase 讀取檔案確認 |
| 現有老師帳號中，有幾個密碼長度 ≤ 7 碼？ | [AI-RESOLVABLE]：可查詢 `SELECT COUNT(*) FROM User WHERE LEN(PSW) ... `（但 PSW 是 bcrypt hash，長度固定，無法反推原始長度；直接忽略，login 不受影響） |

---

## 14. Definition of Done

- [ ] **SEC-002 throttle**：驗證方式：`php artisan test tests/Feature/SecurityHardeningTest.php --filter register_throttle` 回傳 `1 passed`
- [ ] **SEC-003 throttle**：驗證方式：`php artisan test` filter `forgot_password_throttle` 回傳 `1 passed`
- [ ] **SEC-004 密碼長度**：驗證方式：`php artisan test` filter `password_min_8` 回傳 `1 passed`；`filter register_old_4char_account_can_login` 回傳 `1 passed`
- [ ] **SEC-005 安全標頭**：驗證方式：`php artisan test` filter `security_headers` 回傳 `1 passed`；`curl -sI http://localhost/api/v1/health` 含 `Strict-Transport-Security`
- [ ] **SEC-006 throttle**：驗證方式：`php artisan test` filter `swipe_rfid_throttle` 回傳 `1 passed`
- [ ] **Regression**：驗證方式：`./vendor/bin/phpunit` 全套 702 tests 回傳 `0 failures, 0 errors`
- [ ] **STRIDE 審查**：驗證方式：[REVIEW] Agent 靜態分析，無新增 HIGH 風險
- [ ] **CHANGELOG**：驗證方式：[DOCS] Agent 確認 `docs/CHANGELOG.md` 含本次版本條目
- [ ] **安全標頭部署確認**：驗證方式：`curl -sI https://daan.lifenet.com.tw/api/v1/health | grep -E "Strict-Transport|X-Frame|X-Content-Type"` 回傳 3 行

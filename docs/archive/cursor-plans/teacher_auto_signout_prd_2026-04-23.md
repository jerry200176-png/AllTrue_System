# PRD：老師自動補登簽退（Teacher Auto Sign-Out）

## 1. 文件資訊

| 欄位 | 內容 |
|---|---|
| 功能名稱 | 老師自動補登簽退 |
| 版本 | v1.0 |
| 狀態 | Draft |
| 目標角色 | 系統排程（無 UI） |
| 嚴重度 | P2（功能異常但有 workaround — 手動補登） |
| 關聯事件 | 黃芝琳大安分校 2026-04-23 未簽退（手動補登） |

## 2. 目標與業務背景

**痛點**：老師忘記刷退卡是日常發生（大安分校每日都有 1-3 筆），每次需要人工介入補登 SignOutDT。

**業務價值**：每日凌晨自動關閉前一日未簽退記錄，消除人工作業，確保老師出勤報表完整。

**修復後預期行為**：每天 00:05 自動把「前一日 SignOutDT=NULL」的 TeacherSingIn 補上 23:59，Status 設 `adjusted`，Memo 記錄為系統補登。

## 3. 範圍

**In Scope**
- 新增 Artisan command：`teacher-signin:close-orphans`
- 加入 Kernel.php 每日排程（00:05，nightly reconcile 之後）
- 補登條件：`SignOutDT IS NULL AND DATE(SignInDT) < CURDATE()`

**Out of Scope**
- LINE 退卡提醒通知（另立需求）
- 當日即時提醒（未來 iteration）
- 前端 UI 異動（無）
- Migration（複用現有欄位，不新增欄位）

## 4. RACI

| 工作 | R | A |
|---|---|---|
| 全部實作 | AI Agent | AI Agent |

**Dependencies（4b）**：無前置 PR；現有 `TeacherSingIn` 表欄位已足夠（`SignOutDT`、`Status`、`Memo`）。

## 5. Acceptance Criteria

### AC-001：正常補登
- AC-001-a：前一日 SignOutDT=NULL 的 TeacherSingIn，執行 command 後 SignOutDT = 當日 23:59:00
- AC-001-b：Status 更新為 `adjusted`，Memo 更新為 `系統自動補登簽退`

### AC-002：防呆 — 不動當日記錄
- AC-002-a：當日（DATE(SignInDT) = CURDATE()）的 NULL 記錄不被觸動（老師可能還在校內）

### AC-003：防呆 — 已有 SignOutDT 不重複處理
- AC-003-a：SignOutDT 已有值的記錄不被修改

### AC-004：排程
- AC-004-a：Kernel.php 中每日 00:05 自動執行

### AC-005：Log
- AC-005-a：每筆補登寫入 laravel.log（teacher_signin_autoclosed）

## 6. 功能需求 FR

| 編號 | 描述 |
|---|---|
| FR-001 | Command 查詢 `SignOutDT IS NULL AND DATE(SignInDT) < CURDATE()` |
| FR-002 | 補登 `SignOutDT = DATE(SignInDT) + 23:59:00` |
| FR-003 | 若補登後 SignOutDT ≤ SignInDT（異常），改設 SignInDT + 1 小時 |
| FR-004 | Status 更新為 `adjusted` |
| FR-005 | Memo 更新為 `系統自動補登簽退` |
| FR-006 | 每筆寫 Log：teacher_signin_id、teacher_id、campus_id、sign_in_dt、sign_out_dt |
| FR-007 | Kernel.php dailyAt('00:05') 加入排程 |

## 7. 非功能需求 NFR

不適用。純邏輯補登，資料量小（每日全校未簽退老師 < 20 筆），效能無疑慮。

## 8. 技術方向

- **新增檔案**：`backend/app/Console/Commands/CloseOrphanTeacherSignIns.php`
- **修改檔案**：`backend/app/Console/Kernel.php`（加一行排程）
- **參考**：`CloseOrphanStudentSignIns.php`（相同模式，照抄調整）
- **Model**：`TeacherSignIn`（確認 `protected $table = 'TeacherSingIn'`）
- **無 Migration**（使用現有欄位）
- **無前端異動**

**8b Decision Log**

| 決策 | 替代方案 | 選擇理由 |
|---|---|---|
| 固定補 23:59 | 查老師最後一堂課 EndTime | 老師不一定有排課，且老師簽退時間與學生不同，固定 23:59 更保守 |
| Memo 欄位標記 | 新增 auto_signout 欄位 | 避免 migration，Memo 足夠識別 |

## 9. 資安與存取控制

不適用。純後端排程，不涉及 API / auth / PII 異動（只寫 SignOutDT 欄位）。

## 10. QA 驗收

| 場景 | 操作 | 預期結果 |
|---|---|---|
| Happy Path | 前日有 NULL SignOutDT | 補登 23:59，Status=adjusted |
| Edge：當日記錄 | 當日 NULL | 不動 |
| Edge：已有 SignOutDT | 已補登的記錄 | 不重複修改 |
| Edge：SignInDT=23:58 | 補登後 < SignInDT | 改設 SignInDT+1hr |
| Regression | 執行後 Sign-In > Sign-Out 不存在 | 全表無 SignOutDT < SignInDT |

**Revert-proof 驗證**：git stash 後跑測試應有至少 1 case failure。

## 11. 上線與維運

- **無 Migration**
- **無前端 deploy**
- 排程加入後：下一個 00:05 自動生效
- 回滾：移除 Kernel.php 該行排程，不需要 migrate:rollback

## 12. 優先級

P2，`[DEV]` → `[TEST]` → `[REVIEW]` → `[DOCS]` → `[OPS]`

## 13. 風險 / 假設 / 開放問題

- **假設**：TeacherSingIn.Memo 欄位已存在（已確認 DB schema 有此欄）
- **風險**：補登時間固定 23:59，可能與實際離開時間差距大（但這是 P2 已知取捨）
- **開放問題**：日後若需精確時間，可改查最後一堂課時間（留待 v1.1）

## 14. Definition of Done

- [ ] FR-001~007 全部實作：驗證方式：`php artisan teacher-signin:close-orphans` 在測試環境回傳 `Closed N record(s).`
- [ ] AC-002 當日不動：驗證方式：測試資料當日 NULL → 執行後仍為 NULL
- [ ] Pest test PASS：驗證方式：CI GitHub Actions 全綠
- [ ] Revert-proof：驗證方式：`git stash` 後跑測試至少 1 case failure
- [ ] Kernel 排程已加：驗證方式：`grep 'close-orphans' backend/app/Console/Kernel.php` 有輸出
- [ ] Log 有寫：驗證方式：執行後 `grep teacher_signin_autoclosed backend/storage/logs/laravel.log` 有紀錄

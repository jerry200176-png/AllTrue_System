# Execution Package — #1378 StudentClass Memo utf8mb4

> **狀態**：Draft — **Founder GO 前禁止 production migrate execute**  
> **PCR / REP ID**：REP-2026-07-22-1378-MEMO-UTF8MB4  
> **權威模板**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md)  
> **Issue**：[#1378](https://github.com/jerry200176-png/AllTrue_System/issues/1378)  
> **Sentry**：PHP-LARAVEL-24（`Incorrect string value` on `StudentClass.Memo` for 📅）

---

## 2.1 Scope

| 項目 | 內容 |
|------|------|
| In scope | `StudentClass.Memo` / `PackageName` / `closed_reason` → `utf8mb4_unicode_ci`；table DEFAULT charset；enrollment 422 mapping（不刪 emoji） |
| Out of scope | 全庫 utf8mb4 轉換、`Student.name` 等他表（F6 搜尋 sanitizer 仍保留）、歷史 Memo 資料改寫、Course Continuity |
| Code PR | `cursor/fix-studentclass-memo-utf8mb4-36a2`（本 REP 對應） |
| Migration | `2026_07_22_130000_convert_student_class_free_text_to_utf8mb4.php` |

**根因（調查結論）**

| 層 | 現況 |
|----|------|
| Laravel connection | `charset=utf8mb4` / `collation=utf8mb4_unicode_ci`（`config/database.php`） |
| CI test DB | `CREATE DATABASE ... CHARACTER SET utf8mb4` → 測試預設不會重現 1366 |
| Production `StudentClass.Memo` | **utf8mb3（utf8）** — 4-byte emoji 寫入失敗（Sentry 證據） |
| `EnrollmentService::createStudentClassResilient` | 僅對 `Unknown column` retry；**不會**刪除 emoji；charset 錯誤原樣拋出 → 500 |

---

## 2.2 Risk Assessment

| 維度 | 評級 | 說明 |
|------|------|------|
| 資料完整性 | LOW | Column MODIFY 不改寫既有 BMP 文字；down() 刻意 no-op（避免降級破壞 emoji） |
| 可用性 | MED | InnoDB 可能 brief metadata lock；建議低峰執行 |
| 回滾難度 | MED | 無法安全 rollback 到 utf8mb3（會截斷 4-byte）；程式可 revert，欄位保留 utf8mb4 |
| 多校區隔離 | PASS | Schema-only，無跨校 query |

---

## 2.3 Preflight（production 唯讀 — 執行前必跑）

```sql
-- DB / table / Memo charset
SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'AllTrue';

SELECT T.TABLE_NAME, CCSA.CHARACTER_SET_NAME AS table_charset, T.TABLE_COLLATION
FROM information_schema.TABLES T
JOIN information_schema.COLLATION_CHARACTER_SET_APPLICABILITY CCSA
  ON CCSA.COLLATION_NAME = T.TABLE_COLLATION
WHERE T.TABLE_SCHEMA = 'AllTrue' AND T.TABLE_NAME = 'StudentClass';

SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'AllTrue' AND TABLE_NAME = 'StudentClass'
  AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','tinytext')
ORDER BY COLUMN_NAME;

-- Affected rows (no PII)
SELECT COUNT(*) AS student_class_rows FROM StudentClass;
SELECT COUNT(*) AS memo_nonnull FROM StudentClass WHERE Memo IS NOT NULL AND Memo <> '';

-- Other free-text tables that may still be utf8mb3 (inventory only — not altered by this migration)
SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'AllTrue'
  AND CHARACTER_SET_NAME IN ('utf8', 'utf8mb3')
  AND DATA_TYPE IN ('varchar','char','text','mediumtext','longtext','tinytext')
ORDER BY TABLE_NAME, COLUMN_NAME;
```

**預期**：`StudentClass.Memo` 的 `CHARACTER_SET_NAME` ∈ {`utf8`,`utf8mb3`} 才需要本 migration；若已是 `utf8mb4` 則 migration 為 no-op。

---

## 2.4 Lock / downtime estimate

| 項目 | 估計 |
|------|------|
| Algorithm | InnoDB online DDL（MySQL 8：`ALGORITHM=INPLACE` 多數 VARCHAR charset 變更仍可能 copy） |
| Rows | `COUNT(*)` from preflight（典型數百～數千） |
| Lock | brief exclusive metadata lock per MODIFY；建議離峰 |
| Downtime | 目標 0 使用者可見中斷；最壞短連線錯誤可重試 |

---

## 2.5 Rollback Plan

1. **程式**：`git revert` merge commit → PR → deploy（422 mapping 消失；若欄位已 utf8mb4 仍可寫 emoji）
2. **Schema**：❌ **禁止** `MODIFY ... CHARACTER SET utf8`（會破壞已寫入 emoji）
3. **資料**：本 migration **不** UPDATE 列內容；無需 snapshot restore（仍建議 mysqldump）

---

## 2.6 Execution Commands（Founder GO 後）

```bash
# 1) Backup
TS=$(date '+%Y-%m-%d_%H%M%S')
cd /home/admin/backend
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD .env | cut -d= -f2)" \
  --single-transaction AllTrue StudentClass \
  | gzip > /home/admin/backups/emergency/db_pre_1378_memo_${TS}.sql.gz

# 2) Prefer normal path: PR merge → deploy.yml migrate --force
#    Manual path only if deploy skipped:
php artisan migrate --path=database/migrations/2026_07_22_130000_convert_student_class_free_text_to_utf8mb4.php --force
```

---

## 2.7 Success Criteria

```sql
SELECT CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='AllTrue' AND TABLE_NAME='StudentClass' AND COLUMN_NAME='Memo';
-- expect: utf8mb4 / utf8mb4_unicode_ci
```

Smoke（非 production 寫入指令 — 用主任帳在 staging/低風險校區建試聽備註含 📅）：

- HTTP 201，Memo round-trip 含 emoji／換行／中文標點
- 失敗路徑不得留下半成品 `StudentClass` / `ClassSession`
- `curl -sk https://daan.lifenet.com.tw/api/v1/health` → `status=ok`

---

## 2.8 Founder GO checklist

```
[ ] Preflight SQL 結果已貼到 private ops channel（無 PII）
[ ] mysqldump 完成
[ ] CI 全綠（含 StudentClassMemoUtf8mb4Test）
[ ] 本 REP 已審閱
[ ] Founder 單次書面 GO
[ ] Merge PR → 確認 deploy.yml migrate success
[ ] Post-verify charset + health
```

**⛔ 本檔不等於核准。未勾選 Founder GO 前禁止 execute。**

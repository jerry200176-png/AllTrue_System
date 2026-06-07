# RULE_MIGRATION_COMPAT — Migration 向後相容守則

> **P2 規則（#734）**：確保任一版本程式碼能在新舊 schema 上運行，rollback 不卡死。
>
> **唯一真相出處**：本文件。`.cursor/rules/module-migration.mdc` 指向本文件 §快查段落。

---

## 為什麼需要向後相容

生產環境 rollback 順序是：**先 rollback code → 再 rollback migration**（若需要）。  
若 code 和 schema 緊耦合在同一個 PR 合併，rollback code 時舊 code 面對新 schema，會引發 5xx。  
Expand/Contract 模式解耦這個依賴，讓任意版本的 code 都能面對新舊 schema 正常運行。

---

## 向後相容三步驟（Expand/Contract）

```
Phase 1（本 PR）— Expand：
  ┌─────────────────────────────────────────┐
  │ migration: ADD COLUMN new_col (nullable) │
  │ code: 同時讀 old_col 和 new_col         │
  │       寫時雙寫 old_col + new_col        │
  └─────────────────────────────────────────┘

Phase 2（下一 PR）— Backfill：
  ┌─────────────────────────────────────────┐
  │ migration: backfill old→new (chunkById) │
  │ code: 讀 new_col（優先），fallback old  │
  └─────────────────────────────────────────┘

Phase 3（再下一 PR）— Contract：
  ┌─────────────────────────────────────────┐
  │ migration: DROP COLUMN old_col          │
  │ code: 只讀 new_col                      │
  └─────────────────────────────────────────┘
```

**黃金規則**：`DROP` / `RENAME COLUMN` 永遠在獨立 PR，不和任何 code 變更放一起。

---

## ✅ 允許的「破壞性操作」vs ❌ 禁止的破壞性操作

| 操作 | 允許？ | 說明 |
|------|--------|------|
| `ADD COLUMN ... NOT NULL DEFAULT 'x'` | ✅ | MySQL 自動回填，對舊 code 透明 |
| `ADD COLUMN ... nullable()` | ✅ | 完全向後相容 |
| `DROP COLUMN` | ⚠️ 需獨立 PR | Phase 3 才做，確認舊 code 已下線 |
| `RENAME COLUMN` | ❌ 直接 | 拆 ADD → 雙寫 → DROP（三步） |
| `CHANGE COLUMN type` | ⚠️ 視情況 | 縮窄型別需三步；擴寬（INT→BIGINT）通常可一步 |
| `ADD INDEX` | ✅ | Online，不鎖表（MariaDB 10.11 預設 INPLACE） |
| `DROP INDEX` | ✅ | 向後相容，不影響舊 code |
| `ADD FOREIGN KEY` | ⚠️ 需評估 | 確認現有資料不違反約束 |
| 大表新增欄位（>100K rows） | ⚠️ | 用 `ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE` |

---

## down() 可逆性要求

每個 migration `down()` 必須可逆執行。CI `migration-dryrun.yml` 會跑 `up→rollback→up` 迴圈驗證。

**免除 down() 的情況（需在 PR 描述說明）**：
- `chunkById` backfill migration：down() 反向太昂貴，允許寫 `// irreversible backfill`
- `DROP COLUMN`（Phase 3）：資料已遷移完成，down() 只需 ADD 回空欄位即可

---

## PR 描述必填欄位（有 migration 時）

在 PR 描述 Migration 區塊填寫：

```markdown
## Migration Compatibility

- **Phase**: Expand / Backfill / Contract / Simple Add
- **Reversibility**: down() 可逆 ✅ / 不可逆（原因：___）
- **Big table risk**: 表名 + 預估 row 數（> 10K 需評估）
- **Rollback impact**: code rollback 後舊 schema 是否仍可運行？
```

---

## 快查：常見 migration 樣板

### 新增 nullable 欄位（最安全）
```php
public function up(): void
{
    Schema::table('student_classes', function (Blueprint $table) {
        $table->string('new_field')->nullable()->after('existing_field');
    });
}

public function down(): void
{
    Schema::table('student_classes', function (Blueprint $table) {
        $table->dropColumn('new_field');
    });
}
```

### 大表新增欄位（inplace）
```php
// 對 1M+ 行的表，使用 DB statement 確保 INPLACE，避免表鎖
public function up(): void
{
    DB::statement('ALTER TABLE `class_sessions`
        ADD COLUMN `audit_data` JSON NULL
        ALGORITHM=INPLACE, LOCK=NONE');
}
```

### Backfill（chunkById 必用）
```php
public function up(): void
{
    DB::table('student_singins')
        ->whereNull('new_status')
        ->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('student_singins')
                    ->where('id', $row->id)
                    ->update(['new_status' => $row->old_status]);
            }
        });
}

public function down(): void
{
    // irreversible backfill — data originated from old_status column
}
```

---

## 相關文件

- `.cursor/rules/module-migration.mdc`（M1/M2/M3 速查卡）
- `OPERATIONS_RUNBOOK.md §B`（deploy + migration 上線流程）
- `docs/SUPER_ADMIN_AND_MIGRATIONS.md`（super_admin migrate 操作）

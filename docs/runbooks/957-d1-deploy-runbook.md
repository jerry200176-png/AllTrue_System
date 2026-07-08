# #957 D1 — Deploy Runbook & Migration Checklist

> **狀態**：實作完成 — **production migration 待最終核准**  
> **REP 模板**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md)  
> **設計**：[`refactor/957-d1-sprint-design.md`](../refactor/957-d1-sprint-design.md)

---

## 1. Scope

| 項目 | 內容 |
|------|------|
| Epic | GitHub #957 Sprint D1 |
| 交付 | `classsession:cleanup-intra-duplicates` + migration `uq_class_session_slot` |
| Out of scope | 跨 SC 72 組修復、#190 帳務、D2–D5 |

---

## 2. Risk Assessment

| 維度 | 評級 | 說明 |
|------|------|------|
| 資料完整性 | MED | cleanup **刪除**冗餘 session 列（snapshot 可還原） |
| 可用性 | LOW | 無 API downtime；migration 秒級 |
| 回滾難度 | LOW | `migrate:rollback` 可移除 index；cancelled 列可從 snapshot 還原 |
| 多校區隔離 | PASS | 僅影響重複列，query 仍帶 Campus 既有邏輯 |

---

## 3. Rollback Plan

### 3.1 移除 unique index

```bash
cd /home/admin/backend
php artisan migrate:rollback --path=database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php --force
```

### 3.2 還原 cleanup 取消的 session（若有 snapshot）

```bash
# 從 storage/app/repair-snapshots/d1-intra-*.json 取 rows_before
# 手動 UPDATE ClassSession SET Status=?, Note=? WHERE id=?
```

### 3.3 程式回滾

```bash
git revert <merge-commit> --no-commit && git commit -m "revert: #957 D1 unique index"
# 走 PR → CI → deploy.yml
```

---

## 4. Validation Procedure

### 4.1 CI（merge 前）

```bash
php artisan test --filter ClassSessionD1UniqueIndexTest
php artisan test --filter ClassSessionMaterializationServiceTest
```

### 4.2 Production baseline（唯讀，執行前）

```bash
cd /home/admin/backend
php artisan classsession:audit-duplicates | jq '.intra_course_duplicates | length'
# 預期：21（2026-07-09 稽核值；執行後應 → 0）
```

---

## 5. Production Checklist

```
[ ] mysqldump 備份（見 DANGEROUS_OPERATIONS.md）
[ ] PR merge + deploy.yml success（程式上線）
[ ] audit intra duplicates 基線已記錄
[ ] cleanup dry-run 輸出已存檔
[ ] CEO 最終核准 migration 執行
[ ] ALLOW_PROD_REPAIR=1 暫時寫入 .env
[ ] cleanup --execute
[ ] audit intra = 0
[ ] migrate --force（單檔或 deploy.yml 自動）
[ ] audit intra = 0 + upsert 煙測
[ ] 移除 ALLOW_PROD_REPAIR
[ ] snapshot 歸檔至 backups/
```

---

## 6. Execution Commands

> ⛔ **勿在未核准時執行 §6.2–6.4**

### 6.1 Deploy 程式（PR merge 後自動或手動 pull）

由 `deploy.yml` 處理；**此階段不跑 migration**（若 deploy 偵測 pending migration，可先暫停或確認 cleanup 已完成）。

### 6.2 Dry-run cleanup

```bash
cd /home/admin/backend
php artisan classsession:cleanup-intra-duplicates
```

### 6.3 Execute cleanup（核准後）

```bash
cd /home/admin/backend
# 暫時：echo 'ALLOW_PROD_REPAIR=1' >> .env  # 或 export ALLOW_PROD_REPAIR=1
php artisan classsession:cleanup-intra-duplicates --execute --force \
  --snapshot=storage/app/repair-snapshots/d1-intra-$(date +%Y%m%d%H%M%S).json
```

### 6.4 Migration（cleanup 成功 + audit=0 後）

```bash
cd /home/admin/backend
php artisan migrate --path=database/migrations/2026_07_09_100000_add_unique_class_session_slot_index.php --force
```

若 migration 失敗並提示 duplicate groups remain → **停止**，回到 §6.3。

---

## 7. Success Criteria

| # | 條件 | 驗證 |
|---|------|------|
| S1 | `intra_course_duplicates` = 0 | `classsession:audit-duplicates` |
| S2 | Index 存在 | `SHOW INDEX FROM ClassSession WHERE Key_name='uq_class_session_slot'` |
| S3 | 重複 insert 被拒 | 應用層 `upsertSlot` idempotent（CI 已測） |
| S4 | Health OK | `curl /api/v1/health` → `status: ok` |

---

## 8. Post-Deployment Verification

```bash
curl -sk https://daan.lifenet.com.tw/api/v1/health | python3 -m json.tool
php artisan classsession:audit-duplicates --student_class_id=2264  # 煙測指令可及
```

---

## 9. Time & Impact

| 項目 | 估計 |
|------|------|
| cleanup execute | 1–3 分鐘（~21 組） |
| migration | < 30 秒 |
| Downtime | 0 |
| 使用者影響 | 無（除非冗餘列曾顯示於行事曆） |

---

## 10. 獨立技術審查備註（2026-07-09）

| 主題 | 結論 | 緩解 |
|------|------|------|
| **Merge 後 auto-migrate** | `deploy.yml` 會在 pending migration 時跑 `migrate --force`；**21 組 duplicate 存在時 migration 會拋錯失敗** | 符合 production freeze：index 不會意外建立；merge 僅部署程式。執行 cleanup 前勿期待 migration 成功 |
| **Rollback** | `migrate:rollback --path=...` 可移除 index；cleanup snapshot 含刪除前列 | §3 已覆蓋 |
| **Concurrency** | `upsertSlot` 有 `lockForUpdate`；index 後 DB 層阻擋競態 insert | `CoursePackageController::ClassSession::insert` 仍為 bulk 路徑 — 僅在無既有列時安全 |
| **Duplicate 偵測** | audit 用 `SUBSTRING(StartTime,1,5)`；index 用完整 `TIME` | cleanup 與 migration 預檢皆用 HH:MM 分組；`normalizeTimeForStorage` 統一為 `:00` 秒 |
| **Keeper 選擇** | 有 `LearningRecord` 綁定者 +10 優先保留 | 避免刪除評量綁定列 |
| **刪除 vs 作廢** | unique index 不允許同 slot 雙列，冗餘列必須 **DELETE** | snapshot 必填；FK 多為 nullable / 無硬 FK |

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | D1 實作 + runbook（待 production 核准） |

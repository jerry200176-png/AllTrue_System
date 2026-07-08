# Epic #957 — Sprint 設計：D1 Unique Slot Index

> **狀態**：實作中（PR `feat/957-d1-unique-slot-index`）— **production migration 待最終核准**  
> **Epic**：[GitHub #957](https://github.com/jerry200176-png/AllTrue_System/issues/957)  
> **Sprint 目標**：在 production 資料清理後，加上 `(StudentClassID, SessionDate, StartTime)` unique index，杜絕新增重複物化  
> **前置**：歷史資料修復（#189/#191 P0/P1）或至少 audit 清單核准

---

## 1. Sprint 範圍

### In Scope（D1）

| 項目 | 交付物 |
|------|--------|
| D1a | Production audit 報告歸檔（`classsession:audit-duplicates` + 手動清單） |
| D1b | 資料清理腳本（dry-run 預設）— 課程內 duplicate 21 組；**刪除**非 keeper 列（unique index 不允許同 slot 雙列含 cancelled） |
| D1c | Migration：`unique_class_session_slot` on `ClassSession` |
| D1d | 回歸測試：並行 `upsertSlot`、migration 後 audit=0 |
| D1e | Deploy runbook + rollback 演練文件 |

### Out of Scope（後續 Sprint）

| 項目 | Epic 章節 |
|------|-----------|
| D2 ApprovalSessionSync resolver | #957 D2 |
| D3 Payment truth / AlertController | #959 |
| D4 Calendar dedupe key | #961（部分已做 #1105） |
| D5 Schedule destroy orphans | #963 |
| 跨 SC 72 組批次修復 | `189-191-dryrun-report.md` Batch 2 |

---

## 2. 技術設計

### 2.1 現況

- **寫入權威**：`ClassSessionMaterializationService::upsertSlot()` 已為 `app/` 內唯一 `ClassSession::create`
- **Idempotent 邏輯**：應用層 `(StudentClassID, SessionDate, StartTime HH:MM)` 查重 + `lockForUpdate`
- **DB 缺口**：無 unique index → 競態 / 歷史路徑仍可產生 duplicate
- **Audit 指令**：`php artisan classsession:audit-duplicates`（唯讀）

### 2.2 目標 Schema

```sql
-- 提案名稱：class_session_slot_unique
ALTER TABLE ClassSession
  ADD UNIQUE INDEX uq_class_session_slot (
    StudentClassID,
    SessionDate,
    StartTime
  );
```

**設計決策**

| 決策 | 選擇 | 理由 |
|------|------|------|
| Index 欄位 | `StudentClassID + SessionDate + StartTime` | 與 `upsertSlot` 一致；不跨 SC（跨 SC 重複需業務規則） |
| `StartTime` 精度 | 完整 `TIME`（非 HH:MM） | 與現有 storage 一致；audit 用 `SUBSTRING(StartTime,1,5)` |
| `cancelled` 列 | **納入 unique** | 同一 slot 不可有兩列（含 cancelled）；清理時作廢而非保留雙列 |
| 跨 SC 重複 | **不加** DB unique | 不同 StudentClass 可同日同時段（一對二）；靠 D2 + 業務修復 |

### 2.3 Migration（草稿）

```php
// database/migrations/2026_07_xx_000001_add_class_session_slot_unique_index.php

public function up(): void
{
    // 前置：必須先跑 cleanup，否則 migration 失敗
    Schema::table('ClassSession', function (Blueprint $table) {
        $table->unique(
            ['StudentClassID', 'SessionDate', 'StartTime'],
            'uq_class_session_slot'
        );
    });
}

public function down(): void
{
    Schema::table('ClassSession', function (Blueprint $table) {
        $table->dropUnique('uq_class_session_slot');
    });
}
```

### 2.4 資料清理策略（D1b）

**課程內 duplicate（21 組）— migration 前必須為 0**

| 步驟 | 動作 |
|------|------|
| 1 | `classsession:audit-duplicates` → 匯出 JSON |
| 2 | 每組保留 `id` 最小或 `attended` 優先列；其餘 → `cancelled` |
| 3 | 重跑 audit 直到 `intra_course_duplicate_groups = 0` |
| 4 | 才執行 migration |

**不併入 D1 migration 的清理**：跨 SC 72 組（`189-191-dryrun-report`）— 需獨立 CEO 核准批次。

### 2.5 驗證方式

| 階段 | 驗證 | 通過標準 |
|------|------|----------|
| Pre-cleanup | `classsession:audit-duplicates` | 清單已人工核准 |
| Post-cleanup | 同上 | `intra_course_duplicate_groups = 0` |
| Post-migration | PHPUnit `ClassSessionMaterializationServiceTest` | 並行 upsert → 1 row |
| Post-migration | 嘗試 insert duplicate | DB error 1062 |
| Post-deploy | Pi audit + health | audit 0 + `/api/v1/health` 200 |
| Smoke | 刷卡 + 今日排課 + 課程詳情 | 無 500 |

### 2.6 部署策略

```
Week 1  DESIGN + cleanup dry-run PR（無 migration）
Week 2  Cleanup execute PR（需 CEO + backup）— 仍無 unique index
Week 3  Migration PR + CI + merge → deploy.yml → migrate --force on Pi
```

| 步驟 | 動作 |
|------|------|
| 1 | `mysqldump` 全庫備份（`DANGEROUS_OPERATIONS.md`） |
| 2 | Maintenance：無需全站 downtime；migration 線上執行 |
| 3 | `php artisan migrate --force`（deploy.yml 既有） |
| 4 | `php artisan classsession:audit-duplicates` on Pi |
| 5 | OPS smoke checklist |

**FinOps**：單一 PR 含 migration；不拆多個 deploy。

### 2.7 Rollback

| 情境 | 動作 |
|------|------|
| Migration 失敗（duplicate 未清完） | `migrate:rollback` 一步；修 cleanup 後重跑 |
| Deploy 後發現 500 | `git revert` merge commit → deploy；必要時 `dropUnique` |
| 資料清理錯誤 | 從 backup SQL 還原 `ClassSession` 受影響列 — **禁止部分還原** |

```bash
# 緊急 drop index（事故恢復用，需 CEO）
php artisan migrate:rollback --step=1
```

---

## 3. 風險分析

| 風險 | 嚴重度 | 緩解 |
|------|--------|------|
| Migration 因殘留 duplicate 失敗 | High | 強制 pre-flight audit=0 gate in CI |
| 清理腳本作廢錯誤列 | High | dry-run 預設 + snapshot JSON + 僅 Batch 0/1 |
| 線上寫入競態在 index 建立瞬間 | Medium | deploy 低峰 + migration 快速 |
| `cancelled` 列擋住合法 reschedule | Medium | 清理時合併到單列；reschedule 改 update 而非 insert |
| 跨 SC 重複未解但 index 已上 | Low | D1 不解跨 SC；#189 家族需獨立修復 |
| Pi 跑 PHPUnit | **P0** | 只在 GitHub Actions 跑測試 |

---

## 4. 完成 D1 後 — Issue 解決矩陣

| Issue | D1 後預期 | 仍需額外工作 |
|-------|-----------|--------------|
| **#189** 陳品承加課重複 | ⚠️ **部分** — 不再*新增*課程內 dup；既有跨 SC 2264 列仍在 | ✅ Batch 0 資料修復 + Stop 幽靈殼 |
| **#191** 吳夏妍跨約 | ❌ **未根本解** — 跨 SC、不同 StartTime | ✅ 手動 cancel SC395 5/14 + 收據人工 |
| **#173** 王光熙三端不一致 | ⚠️ **部分** — 減少新 duplicate；SC114/2076 既有列在 | ✅ P2 個案修復 + 視圖一致性 |
| **#175** 評量無故未填 | ⚠️ **部分** — LR 綁錯 session 若因 dup | ✅ D2 resolver + 評量 rebind 工具 |
| **#195** 預購 B 期重疊 | ❌ **未解** — 產品邏輯（順延不推 B 期） | ✅ 獨立 feature：renew 連動下游 StartDate |
| **#190** 週日 0 元 | ❌ **無關** — 已 code fix | ✅ 歷史帳務 `190-reconciliation-report` |
| **#196** 幽靈投影 | ❌ **無關** — 已 code fix | — |

**圖例**

- ✅ **根本解決（D1 單獨）**：無 — D1 是必要基礎建設，非萬靈丹
- ⚠️ **防止惡化 + 部分減量**：#189/#173/#175 的新增 duplicate 路徑
- ❌ **需其他 Epic / 產品規格**：#191、#195

### 4.1 建議 Sprint 順序（CEO 視角）

```
Sprint N   ← 本文件：D1 audit + cleanup + unique index
Sprint N+1 資料修復 Batch 0（#189/#191）+ Batch 1（P1-ghost 8 組）
Sprint N+2 D2 ApprovalSessionSync + #195 產品規格
Sprint N+3 #190 歷史帳務 amend（與帳務協作）
```

---

## 5. Sprint 驗收標準（Definition of Done）

- [ ] `classsession:audit-duplicates` → `intra_course_duplicate_groups = 0` on production
- [ ] Unique index migration merged + deployed
- [ ] `down()` 已在 staging / CI 驗證
- [ ] 並行 upsert 測試 CI 綠
- [ ] `docs/CHANGELOG.md` + `AI_REGRESSION_LESSONS` 新增「migration 前必須 audit=0」
- [ ] 無 production 寫入在未核准情況下執行

---

## 6. 相關文件

- [189-191-dryrun-report.md](../incidents/189-191-dryrun-report.md)
- [190-reconciliation-report.md](../incidents/190-reconciliation-report.md)
- `backend/app/Console/Commands/AuditClassSessionDuplicates.php`
- `backend/app/Services/ClassSessionMaterializationService.php`

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | 初版：D1 Sprint 技術設計 + issue 解決矩陣 |

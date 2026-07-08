# #957 D1 — Production Readiness Report

> **用途**：CEO production approval 唯一依據文件  
> **日期**：2026-07-09  
> **PR**：[#1115](https://github.com/jerry200176-png/AllTrue_System/pull/1115)（程式）+ [#1116](https://github.com/jerry200176-png/AllTrue_System/pull/1116)（文件）  
> **Production freeze**：維持 — 本報告 **不** 授權任何 production 寫入

---

## 1. PR Status

| 項目 | 狀態 |
|------|------|
| #1115 `feat/957-d1-unique-slot-index` | **OPEN — CI 全綠** |
| #1116 `chore/reliability-rep-docs` | OPEN — REP / execution packages / runbook |
| Merge 建議順序 | #1116（docs）→ #1115（code），或並行 merge |

---

## 2. CI Status

| Check | #1115 |
|-------|-------|
| PHPUnit Feature & Unit Tests | ✅ pass |
| PHPStan Advisory | ✅ pass |
| Presubmit (≤700 lines) | ✅ pass（656 lines） |
| Migration dry-run | ✅ pass |
| Rollback readiness | ✅ pass |
| Docs integrity | ✅ pass |

Run ID（最新）：`28978698503`

---

## 3. Files Changed (#1115)

| 檔案 | 用途 |
|------|------|
| `CleanupClassSessionIntraDuplicates.php` | D1 課程內 duplicate 清理（dry-run 預設） |
| `RepairDuplicateSessionSlots.php` | #189/#191 Batch 0 修復（dry-run 預設） |
| `2026_07_09_100000_add_unique_class_session_slot_index.php` | Unique index（**僅 production** 套用） |
| `ClassSessionD1UniqueIndexTest.php` | D1 整合測試 |
| `ClassSessionRepairBatch0Test.php` | Batch 0 dry-run 測試 |
| `957-d1-sprint-design.md` | 設計狀態更新 |
| `INDEX.md` | 導航（runbook → #1116） |

---

## 4. Migration Summary

```sql
ALTER TABLE ClassSession
  ADD UNIQUE INDEX uq_class_session_slot (StudentClassID, SessionDate, StartTime);
```

| 屬性 | 說明 |
|------|------|
| 前置條件 | `classsession:cleanup-intra-duplicates --execute` 後 audit intra = 0 |
| Fail-fast | duplicate 組 > 0 時 `up()` 拋 `RuntimeException` |
| CI / test DB | **跳過** index 建立（`APP_ENV≠production`） |
| Production | `APP_ENV=production` 時 deploy.yml 自動 `migrate --force`；**21 組 duplicate 存在時會失敗**（預期） |

---

## 5. Rollback Verification

| 情境 | 指令 | 驗證 |
|------|------|------|
| 移除 index | `migrate:rollback --path=...add_unique_class_session_slot_index.php --force` | `SHOW INDEX` 無 `uq_class_session_slot` |
| 還原 cleanup 刪除列 | 從 `storage/app/repair-snapshots/d1-intra-*.json` 還原 | 列數與 Status 對照 snapshot |
| 程式回滾 | `git revert <merge-sha>` → PR → deploy | health + smoke |

Rollback readiness CI：✅ pass

---

## 6. Independent Technical Review（2026-07-09）

| 主題 | 評估 | 緩解 / 殘餘風險 |
|------|------|----------------|
| Migration safety | ✅ | Fail-fast + production-only gate；merge 後 migration 在 duplicate 存在時失敗屬預期 |
| Rollback completeness | ✅ | Index 可 rollback；cleanup 靠 snapshot（DELETE 非 cancel） |
| Concurrency | ⚠️ LOW | `upsertSlot` 有 `lockForUpdate`；index 後 DB 阻擋競態；`CoursePackageController::ClassSession::insert` bulk 路徑仍繞過 service |
| Duplicate detection | ✅ | `classsession:audit-duplicates` + cleanup 用 HH:MM 分組；keeper 優先保留 LR 綁定列 |
| Index compatibility | ⚠️ MED | Index 用完整 `TIME`；audit/cleanup 用 `SUBSTRING(StartTime,1,5)` — 與 `normalizeTimeForStorage` 一致，秒級漂移風險極低 |
| App logic impact | ⚠️ LOW | 現有 `upsertSlot` 已 idempotent；index 後 duplicate insert 會 500 — 優於 silent duplicate |
| Merge ≠ migration 成功 | ✅ | Production 有 21 組 intra duplicate → migration 拒絕 → 符合 freeze |

**審查結論**：無 Critical 弱點；Minor 項已記錄於 runbook §10（#1116）。

---

## 7. Estimated Execution Time（核准後）

| 步驟 | 時間 |
|------|------|
| DB 備份 | 2–5 min |
| cleanup execute | 1–3 min |
| migration | < 30 sec |
| 驗證 audit + health | 5 min |
| **合計** | **~15 min** |

---

## 8. Risk Level

| 層級 | 評級 |
|------|------|
| Merge PR（程式部署） | **LOW** |
| Production cleanup + migration | **MEDIUM** |
| #189/#191 Batch 0 repair | **MEDIUM** |
| #190 帳務 | **HIGH** — 待業務決策，**不在範圍** |

---

## 9. Go / No-Go Recommendation

| 決策點 | 建議 |
|--------|------|
| **Merge #1115 + #1116** | ✅ **GO** — CI 綠、rollback 可驗、freeze 相容 |
| **Production cleanup** | ⛔ **NO-GO** — 待 CEO 單次核准 |
| **Production migration** | ⛔ **NO-GO** — 須 cleanup 成功後 |
| **repair:duplicate-sessions** | ⛔ **NO-GO** — 見 `189-191-execution-package.md` |
| **#190 billing** | ⛔ **NO-GO** — 待財務政策（analysis only） |

---

## 10. Post-Merge Expected State

- Production 程式含 cleanup / repair 指令，但 **unique index 尚未建立**（migration 因 duplicate 失敗或 skip）
- 系統行為與 merge 前相同，無資料變更
- 執行套件維持 **READY**，等待 CEO **Go**

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | Release Candidate — CI 綠燈後初版 |

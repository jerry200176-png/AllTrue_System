# #189 / #191 — Final Execution Package（Batch 0）

> **狀態**：待 CEO 單次核准 — **禁止 production 寫入**  
> **REP 標準**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md)  
> **調查依據**：[`189-191-dryrun-report.md`](189-191-dryrun-report.md)

---

## 1. Scope

| 項目 | 內容 |
|------|------|
| In-app | #189 陳品承、#191 吳夏妍 |
| Batch | **Batch 0 only**（3 session cancel + 1 StudentClass Stop） |
| Out of scope | P1-ghost 8 組、P2 63 組、收據重開（#191 R-000200） |
| 程式 | `repair:duplicate-sessions --case=batch0`（PR feat/957-d1-unique-slot-index） |

---

## 2. Risk Assessment

| 維度 | 評級 | 說明 |
|------|------|------|
| 資料完整性 | MED | 出席紀錄改為 cancelled；SC2264 Stop |
| 可用性 | LOW | 無 downtime |
| 回滾難度 | LOW | snapshot JSON + Status 還原 |
| 多校區 | PASS | 單一學生、單一分校課程 |

---

## 3. Exact Before / After Record List

### 3.1 #189 — 陳品承

| 實體 | id | Before | After |
|------|-----|--------|-------|
| ClassSession | **18569** | SC2264, 2026-06-13 17:00, `attended` | `cancelled` + Note |
| ClassSession | **18602** | SC2264, 2026-06-20 17:00, `attended` | `cancelled` + Note |
| StudentClass | **2264** | Stop=0, SessionCount=0, UsedSessions=2 | **Stop=1** |
| ClassSession | 15636 | SC1946, 6/13 17:00 attended | **不變（保留）** |
| ClassSession | 15633 | SC1946, 6/20 15:00 attended | **不變（保留）** |

### 3.2 #191 — 吳夏妍

| 實體 | id | Before | After |
|------|-----|--------|-------|
| ClassSession | **3215** | SC395, 2026-05-14 16:00–18:00, `attended` | `cancelled` + Note |
| ClassSession | 13302 | SC1655, 5/14 18:00–20:00 `completed` | **不變（保留）** |
| StudentClass | 395 | SessionCount=9, consumed=10 | consumed 應降至 **9**（唯讀驗證，非本腳本寫入） |

> **人工後續（Out of scope）**：#191 收據 R-000200 已 void，需帳務決定是否重開。

---

## 4. Execution Commands

> ⛔ 需 CEO 核准後執行

### 4.1 備份

```bash
TS=$(date '+%Y-%m-%d_%H%M%S')
mysqldump -h 127.0.0.1 -u admin -p"$(grep DB_PASSWORD /home/admin/backend/.env | cut -d= -f2)" \
  --single-transaction AllTrue ClassSession StudentClass \
  | gzip > /home/admin/backups/emergency/189-191-batch0_pre_${TS}.sql.gz
```

### 4.2 Dry-run（必做）

```bash
cd /home/admin/backend
php artisan repair:duplicate-sessions --case=batch0
```

預期輸出含：
- `WOULD cancel ClassSession id=18569`
- `WOULD cancel ClassSession id=18602`
- `WOULD StudentClass id=2264 Stop=1`
- `WOULD cancel ClassSession id=3215`

### 4.3 Execute（核准後）

```bash
cd /home/admin/backend
export ALLOW_PROD_REPAIR=1
php artisan repair:duplicate-sessions --case=batch0 --execute --force \
  --snapshot=storage/app/repair-snapshots/189-191-batch0-$(date +%Y%m%d%H%M%S).json
unset ALLOW_PROD_REPAIR
```

---

## 5. Rollback Commands

```bash
# 從 snapshot 還原（範例 — 以實際 snapshot 為準）
# ClassSession 18569, 18602, 3215 → Status 還原為 attended
# StudentClass 2264 → Stop=0

mysql -u admin -p AllTrue <<'SQL'
UPDATE ClassSession SET Status='attended', Note=TRIM(REPLACE(Note, '資料修復 #189-191 — 跨約重複', '')) WHERE id IN (18569, 18602, 3215);
UPDATE StudentClass SET Stop=0 WHERE ID=2264;
SQL
```

或從 §4.1 mysqldump 完整還原（最後手段）。

---

## 6. Validation Checklist

```
[ ] Dry-run 輸出與 §3 一致
[ ] Execute 後 ClassSession 18569, 18602, 3215 → Status=cancelled
[ ] StudentClass 2264 → Stop=1
[ ] ClassSession 15636, 15633, 13302 仍為 attended/completed
[ ] SC395 consumed 計數 = 9（唯讀查詢）
[ ] 陳品承 / 吳夏妍 行事曆無雙重出席格
[ ] in-app #189 #191 → resolved + 公開回覆（白話）
```

### 6.1 唯讀驗證查詢

```bash
cd /home/admin/backend && php artisan tinker --execute="
\$ids = [18569,18602,3215,15636,15633,13302];
foreach (DB::table('ClassSession')->whereIn('id', \$ids)->get(['id','Status','StudentClassID']) as \$r) {
  echo \$r->id.' SC'.\$r->StudentClassID.' '.\$r->Status.PHP_EOL;
}
echo 'SC2264 Stop='.DB::table('StudentClass')->where('ID',2264)->value('Stop').PHP_EOL;
"
```

---

## 7. Success Criteria

- Batch 0 四項變更全部符合 §3 After 欄
- 無額外列被修改（snapshot `class_sessions_before` 列數 = 3）
- `curl /api/v1/health` → ok

---

## 8. Time & User Impact

| 項目 | 估計 |
|------|------|
| 執行時間 | < 2 分鐘 |
| Downtime | 0 |
| 使用者影響 | 陳品承、吳夏妍 歷史出席檢視可能少 1 筆重複列；主任儀表板堂數統計更正 |

---

## 9. Production Checklist（摘要）

```
[ ] §4.1 備份完成
[ ] repair:duplicate-sessions PR 已 deploy
[ ] §4.2 dry-run 存檔
[ ] CEO 單次核准
[ ] §4.3 execute + snapshot
[ ] §6 驗證全 PASS
[ ] in-app 公開回覆 + resolved
[ ] CHANGELOG 一行
```

---

## 變更紀錄

| 日期 | 說明 |
|------|------|
| 2026-07-09 | Batch 0 Final Execution Package（待核准） |

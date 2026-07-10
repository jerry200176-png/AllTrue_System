# PCR-2026-07-10-1130-P1-GHOST

> **狀態**：DRAFT — 待 code merge + production dry-run 證據 + CEO GO  
> **Epic**：[#1130](https://github.com/jerry200176-png/AllTrue_System/issues/1130) · in-app #173/#920 部分解  
> **模板**：[`GUIDE_RELEASE_EXECUTION_PACKAGE.md`](../GUIDE_RELEASE_EXECUTION_PACKAGE.md) · 前例：[`957-d1-r2-execution-record.md`](957-d1-r2-execution-record.md)

## Scope

**In**：`repair:duplicate-sessions --case=p1-ghost` — 跨 SC 同日同時段雙 attended/completed 且**恰一側為幽靈殼**（`SessionCount=0`）的組：取消幽靈側堂次、幽靈殼 Stop=1。語意與已核准執行的 #189（SC2264）完全一致。  
**Out**：P2-review 63 組（雙側皆真約，主任逐案決策 — 用 `--case=p2-list` 產出審核清單）、#190 帳務、forward generation（#1062）。

## 內建守衛（規劃器自動排除，無需人工預篩）

1. 群組涉及 >2 個 SC → SKIP（人工）。
2. 有效評量掛在幽靈側而保留側沒有 → SKIP（`評量掛在幽靈側`，需人工移轉評量）。
3. 幽靈殼還有計畫外的未取消堂次 → 取消重複堂次但 **不下 Stop**（避免隱藏其他課）。

## Preconditions

```
[ ] 本 PR merge + deploy 完成
[ ] production dry-run 輸出存檔（預期 ≈7 組；SC2264 已由 batch0 處理）
[ ] dry-run 中每個 cancel 目標的 SC 皆 SessionCount=0（腳本檢查）
[ ] mysqldump ClassSession+StudentClass 備份
[ ] CEO：GO PCR-2026-07-10-1130-P1-GHOST
```

## Execution

```bash
cd /home/admin/backend
php artisan repair:duplicate-sessions --case=p1-ghost              # dry-run 存證
ALLOW_PROD_REPAIR=1 php artisan repair:duplicate-sessions --case=p1-ghost --execute --force \
  --snapshot=storage/app/repair-snapshots/1130-p1-ghost-$(date +%Y%m%d%H%M%S).json
php artisan bugs:verify-reproductions   # cross_sc_duplicate_attended_slot 應下降
```

## Rollback

Snapshot `rows_before` 還原（同 D1 §3）；Stop=1 反向 `UPDATE StudentClass SET Stop=0`。

## Success criteria

| ID | 條件 |
|----|------|
| S1 | 幽靈側堂次 → cancelled；保留側不變（snapshot 對照） |
| S2 | `cross_sc_duplicate_attended_slot` 指標下降 ≈ 組數 |
| S3 | 無 SKIP 組被誤執行（dry-run 與 execute 行數一致） |
| S4 | health ok |

## P2 後續（不在本 PCR）

`php artisan repair:duplicate-sessions --case=p2-list` 產出 63 組審核清單（唯讀）→ 主任逐案標記保留側 → 匯回為下一批 PCR 的輸入。

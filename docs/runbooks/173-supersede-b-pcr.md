# PCR-2026-07-16-173-SUPERSEDE-B

> **狀態**：CEO 已選 **B**（2026-07-16）— 等 code merge + deploy + production dry-run 存證後執行  
> **識別**：in-app #173（campus_id=9）· Compare／CEO 回報**勿寫學生姓名**  
> **分流**：本 PCR **不**改 E-OPS-TRUST 實驗面、**不**重設 Day0；與 Measure 唯一下一步分開

## Scope

**In**
- 保留 session **#16951**／SC#2076（續報新課）
- 將 session **#11292**／SC#114（舊課重疊堂）標為被續報取代、`Status=cancelled`，不重複計費
- 寫入 `session_corrections`：`replaced_by_session_id=16951`、`correction_reason=duplicate_after_renewal`、`decision_reference=in-app #173`、`decided_at`／執行者
- Snapshot + dry-run → execute；可 `--rollback`

**Out**
- 不作廢／搬移 LearningRecord（LR#8883 留在 #11292；LR#9959 留在 #16951；僅 correction 建追溯連結）
- 不改 `UsedSessions`／`RemainingSessions`／Invoice／Payment／reconciled
- 不實體 DELETE
- 不改 Trust CTA／入口／Score／Day0

## Command

```bash
cd /home/admin/backend

# 1) dry-run（預設）
php artisan repair:supersede-renewal-session --case=173

# 2) execute（需 ALLOW_PROD_REPAIR）
export ALLOW_PROD_REPAIR=1
php artisan repair:supersede-renewal-session --case=173 --execute --force \
  --actor='ops:173-b' \
  --snapshot=storage/app/repair-snapshots/173-supersede-$(date +%Y%m%d%H%M%S).json
unset ALLOW_PROD_REPAIR
# 並從 .env 移除 ALLOW_PROD_REPAIR（若曾寫入）
```

## Rollback

```bash
export ALLOW_PROD_REPAIR=1
php artisan repair:supersede-renewal-session --case=173 --rollback --execute --force
unset ALLOW_PROD_REPAIR
```

還原 `ClassSession#11292.Status` 為 correction.`previous_status`；correction 列保留並設 `rolled_back_at`（不 DELETE）。

## Preconditions

```
[ ] PR merge + deploy.yml success（含 migration session_corrections）
[ ] health 200
[ ] production dry-run 輸出已存檔（WILL CHANGE / WILL NOT CHANGE）
[ ] mysqldump 相關列或表級備份（ClassSession / LearningRecord / StudentClass / Invoice / session_corrections）
[ ] CEO 已選 B（本檔）
```

## Success criteria

| ID | 條件 |
|----|------|
| S1 | #11292 → cancelled；#16951 仍 completed／attended |
| S2 | `session_corrections` 一筆 open：replaced_by=16951、reason=duplicate_after_renewal、ref=in-app #173 |
| S3 | LR#8883／#9959 VoidedAt 仍 null；ClassSessionID 未搬移 |
| S4 | SC#114／#2076 Used／Remaining 與執行前相同 |
| S5 | Invoice #137／#936 PaidAmount／reconciled 不變 |
| S6 | 同日 19:00 僅一筆有效 attended／completed（Trust cross_sc_dup 不再含此組） |
| S7 | `reconcile:nightly --dry-run` 不因本案新增 SC#2076 mismatch |

## Post-prod：in-app #173

公開留言（白話、無欄位名／無學生姓名）：修正範圍、驗證結果、版本、回滾方式 → 標 `resolved` → 請主任確認。

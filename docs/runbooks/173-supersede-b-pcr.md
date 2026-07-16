# PCR-2026-07-16-173-SUPERSEDE-B

> **狀態**：CEO 已選 **B** — merge + deploy 後 dry-run → execute  
> **識別**：in-app #173 · 勿寫學生姓名 · **不**改 Trust／Day0

## Scope

- Keep session **#16951**／SC#2076；supersede **#11292**／SC#114 → `cancelled` + `session_corrections`
- Metadata：`replaced_by_session_id=16951`、`correction_reason=duplicate_after_renewal`、`decision_reference=in-app #173`、`decided_at`／actor
- **Out**：no DELETE；no LR void/move；no Used／Remaining／Invoice writes

## Commands

```bash
cd /home/admin/backend
php artisan repair:supersede-renewal-session --case=173
export ALLOW_PROD_REPAIR=1
php artisan repair:supersede-renewal-session --case=173 --execute --force \
  --actor='ops:173-b' \
  --snapshot=storage/app/repair-snapshots/173-supersede-$(date +%Y%m%d%H%M%S).json
unset ALLOW_PROD_REPAIR
# rollback: ... --rollback --execute --force
```

## Success

| ID | Check |
|----|-------|
| S1 | #11292 cancelled；#16951 unchanged |
| S2 | open `session_corrections` with required metadata |
| S3 | LR#8883／#9959 VoidedAt null；IDs not moved |
| S4 | SC Used／Remaining unchanged |
| S5 | Invoice #137／#936 unchanged |
| S6 | 同日 19:00 僅一筆有效 attended／completed |
| S7 | nightly reconcile 不因本案新增 SC#2076 mismatch |

Post-prod：in-app #173 公開留言（白話）→ `resolved` → 請主任確認。

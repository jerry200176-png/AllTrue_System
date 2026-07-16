# in-app #173 — 決策包（2026-07-16）

> **狀態**：主任／CEO 已選 **B**（2026-07-16）。執行見 [`docs/runbooks/173-supersede-b-pcr.md`](../runbooks/173-supersede-b-pcr.md)。  
> **識別**：in-app #173（campus_id=9）。Compare／CEO 回報勿寫學生姓名。  
> **分流**：與 E-OPS-TRUST Measure **分開**；不重設 Day0、不改 Trust 實驗面。

## 選定方案 B

- **保留**：session #16951／SC#2076（續報新課）
- **取代**：session #11292／SC#114（舊課重疊堂）→ `Status=cancelled` + `session_corrections` 稽核列
- **metadata**：`replaced_by_session_id=16951`、`correction_reason=duplicate_after_renewal`、`decision_reference=in-app #173`、`decided_at`／執行者
- **禁止**：實體 DELETE、作廢／搬移評量、改 Used／Remaining／Invoice

## 事實摘要（選定前蒐證，仍有效）

| | 舊課 SC#114 | 續報新課 SC#2076 |
|---|---|---|
| 合約區間 | 2026-04-08 → 2026-06-02（Stop=1） | 2026-06-03 → 2026-08-12（進行中） |
| 堂數帳 | Used 8／Remaining 0 | Used 7／Remaining 1 |
| 帳單 | Invoice #137 paid＋reconciled | Invoice #936 paid＋reconciled |
| 6/10 19:00–21:00 | session #11292 attended＋LR#8883 approved | session #16951 completed＋LR#9959 approved |

## 回滾

`php artisan repair:supersede-renewal-session --case=173 --rollback --execute --force`（需 `ALLOW_PROD_REPAIR=1`）  
還原 #11292 狀態；correction 列保留並標記 `rolled_back_at`。

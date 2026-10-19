## 2026-10-17 — chore(ops): read-only contract_4131_stale_teacher case dump
<!-- release-notes: silent_ship=silent-2026-10-17-contract-4131-stale-teacher-case-dump -->
<!-- silent-reason: 只新增內部 production-case-dump 唯讀個案選項（#401/#402 驗收用），教職員產品面無可見差異 -->
- `production-case-dump.yml`：新增固定合約 `4131`（campus 16）唯讀個案，依 #4245 H1 規則標出 leftover `schedules.teacher_id` 是否應佔用；IDs/enums/dates only。不授權 Cursor 自行 dispatch。

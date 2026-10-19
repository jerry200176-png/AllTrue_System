## 2026-10-17 — chore(ops): read-only contract_4131_stale_teacher case dump
<!-- release-notes: silent_ship=silent-2026-10-17-contract-4131-stale-teacher-case-dump -->
<!-- silent-reason: 只新增內部 production-case-dump 唯讀個案選項（#401/#402 驗收用），教職員產品面無可見差異 -->
- `production-case-dump.yml`：新增固定合約 `4131`（campus 16）唯讀個案，依 #4245 H1 規則標出 leftover `schedules.teacher_id` 是否應佔用；IDs/enums/dates only。不授權 Cursor 自行 dispatch。
- Fail closed before database reads unless production HEAD is a valid SHA containing the prerequisite fix; include the verified source SHA in the encrypted evidence.
- Retain only scheduled rows that pass the canonical StaleScheduleExceptionFilter before H1 classification; leave/rescheduled, inactive and moved occurrences do not enter candidate counts. This remains classifier evidence, not proof of complete product acceptance.
- Runtime fixture regressions execute the rendered PHP branch and actual canonical filter: original source RED2/2, corrected complete probe suite18tests GREEN. No production dispatch or in-app status change performed.
- Refuse the complete probe if any fixed-course schedule row disagrees with the required campus; inconsistent rows never enter campus-bound classifier evidence. Synthetic foreign-campus regression RED1 then complete19tests GREEN.

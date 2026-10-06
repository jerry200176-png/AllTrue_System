## 2026-10-06 — chore(privacy): production dump workflows log IDs only; report text moves to an encrypted artifact (#3605)
<!-- release-notes: silent_ship=silent-2026-10-06-dumps-ids-only -->
<!-- silent-reason: 只改內部診斷工作流程的輸出（log 不再含姓名／回報文字），教職員看不到差異 -->
- `bug-queue-dump` / `bug-detail-dump`：log 與明文 artifact 只留 ID、狀態、時間、數量；完整 JSON 以 `DUMP_ARTIFACT_KEY` 加密（缺 secret 即 fail closed）。`production-case-dump` 以 `student_id` 取代 `student_name`。

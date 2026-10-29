## 2026-10-29 — chore(docs): 交接狀態 RUNBOOK_HANDOFF + SessionStart 印出 + INDEX Takeover recall
<!-- release-notes: silent_ship=silent-2026-10-29-memory-handoff-recall -->
<!-- silent-reason: agent-facing docs, SessionStart hook output and an opt-in ingest flag; no product behavior -->
- 新增 `docs/RUNBOOK_HANDOFF.md`（日期＋SHA、7 天 STALE 規則、live state 與 deploy/POP 坑的指標），取代 SOP_MATURITY 過期的「進行中狀態」。
- `.claude/hooks/session-start.sh` 在 cloud 與本機都印出 handoff（最多 6 KB，超過 7 天印 STALE；檔案缺失 fail-open），不動既有安裝流程。
- `docs/INDEX.md` 新增「Takeover recall」問題→文件對照；`scripts/mempalace-ingest.sh --prune` 為明確 opt-in 才會 `sync --apply`。

## 2026-11-09 — chore(agents): verify task context before continuation
<!-- release-notes: silent_ship=silent-2026-11-09-codex-task-continuity -->
<!-- silent-reason: development-only skill and read-only observations; no product behavior, permissions or production activation -->
- Add an on-demand Codex skill using the existing source/hash reader and handoff.
- Batch read-only workspace, PR and production identity observations; preserve private context atomically without replaying actions or replacing the existing harness store.
- Record actual local discovery, recovery-boundary tests, tokenized retrieval measurements and client-specific limits in `docs/engineering/CODEX_TASK_CONTINUITY.md`.

## 2026-11-11 — chore(docs): retire Cursor editor rule files
<!-- release-notes: silent_ship=silent-2026-11-11-retire-cursor-rules -->
<!-- silent-reason: internal agent-instruction and CI tooling cleanup; no staff-visible product change -->
- Remove `.cursorrules`, `.cursor/rules`, `.cursor/skills`, `.cursor/skills-cursor`, `.cursorignore` and `.cursorindexingignore` (Founder 2026-11-11: only Claude Code and Codex remain). Plans move to `docs/archive/cursor-plans`; the eight live Claude skills that were symlinked into `.cursor/skills` now live in `.claude/skills`.
- Port the still-valid, previously unstated Cursor rules into `CLAUDE.md` yellow lines Y5–Y14; repoint docs, presubmit, docs-integrity, instruction-invariants, autonomy gate and docs-section tooling away from the removed paths. The alwaysApply-size presubmit step is removed with its only input.

## 2026-10-31 — docs(agents): move the gotcha detail out of CLAUDE.md (−39% always-loaded text)
<!-- release-notes: silent_ship=silent-2026-10-31-claude-md-gotchas-split -->
<!-- silent-reason: agent instructions only; no product change -->
- Every Claude session and subagent loads `CLAUDE.md`, but most tasks touch at most one gotcha. G-001 and G-006 to G-012 move verbatim to `docs/agents/GOTCHAS.md`. `CLAUDE.md` keeps a one-line index per ID, so references like `CLAUDE.md §G-007` still resolve. `CLAUDE.md` drops from 24.4 KB to 15.0 KB. No rule text is changed or dropped; this is progressive disclosure, as in Anthropic's CLAUDE.md guidance. Governance smoothing item 3 (`docs/plans/2026-10-28-governance-smoothing.md`), tracker #4659.

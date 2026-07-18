# Instruction precedence

When adapters conflict, resolve in this order (see also [COMPANY_CONSTITUTION.md](./COMPANY_CONSTITUTION.md)):

1. Production safety invariants  
2. Company Constitution  
3. AllTrue `CONTROL_PLANE_CONTRACT` + `CONTRADICTION_REGISTRY` (deploy / incident authority)  
4. Product SOP / runbook for the repo being edited  
5. `AGENTS.md` (universal agent entry)  
6. `CLAUDE.md` (Claude Code adapter — **must not claim absolute override**)  
7. `.cursorrules` / `.cursor/rules/*.mdc` (Cursor adapter)  
8. Skills / Hooks (optional helpers)  
9. Plans, transcripts, MemPalace recall (non-authoritative)

**Rule:** Adapters cite Constitution; they do not redefine it.

# Instruction precedence

When adapters conflict, resolve in this order (see also [COMPANY_CONSTITUTION.md](./COMPANY_CONSTITUTION.md)):

1. Production safety invariants  
2. Company Constitution  
3. Fleet operator table (`portfolio-ops` `AUTONOMY_POLICY`) — product may add checks/P0, not a Founder rubber-stamp  
4. AllTrue `CONTROL_PLANE_CONTRACT` + `CONTRADICTION_REGISTRY` (deploy / incident authority)  
5. Product SOP / runbook for the repo being edited  
6. `AGENTS.md` (universal agent entry)  
7. `CLAUDE.md` (Claude Code adapter — **must not claim absolute override**)  
8. `.cursorrules` / `.cursor/rules/*.mdc` (Cursor adapter)  
9. Skills / Hooks (optional helpers)  
10. Plans, transcripts, MemPalace recall (non-authoritative)

**Rule:** Adapters cite Constitution; they do not redefine it.

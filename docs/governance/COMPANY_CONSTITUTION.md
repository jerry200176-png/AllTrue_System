# Company Constitution

**Version:** 0.1.0  
**Effective:** 2026-07-18  
**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System + sunrise-cafe (portfolio)  
**Canonical:** This file is tool-neutral. Cursor Rules / CLAUDE.md / Skills are adapters only.

## Purpose

Define non-negotiable decision order, safety floors, and success definitions so any engineer or coding agent can operate without relying on chat history.

## Decision precedence (highest wins)

1. **Production safety & data integrity** (billing, attendance, auth, PII)
2. **This Constitution**
3. [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) / [`CONTRADICTION_REGISTRY.md`](../CONTRADICTION_REGISTRY.md) (AllTrue deploy authority)
4. Product overlay SOPs (AllTrue / sunrise-cafe)
5. `AGENTS.md` → `CLAUDE.md` → `.cursorrules` / `.cursor/rules` (adapters)
6. Chat / Plan / transcript (never canonical)

If Claude or Cursor text says it “overrides everything,” **Constitution + Control Plane still win**.

## Products

| Product | Repo | Production verify |
|---------|------|-------------------|
| AllTrue System | `jerry200176-png/AllTrue_System` | `https://daan.lifenet.com.tw/version.json` SHA |
| sunrise-cafe | `jerry200176-png/sunrise-cafe` | `GET /api/version` commit == deploy SHA |

## Hard bans

- Do not edit dirty diverged worktrees (see [`WORKTREE_POLICY.md`](./WORKTREE_POLICY.md)).
- Do not mix AllTrue and sunrise-cafe changes in one PR / worktree.
- Do not treat Plan / CI green / issue close count as production success.
- Do not mark in-app bug `resolved` without public reporter-facing comment ([`CHAT_BUG_SYSTEM.md`](../CHAT_BUG_SYSTEM.md) §3.7 / R53).
- Do not mark in-app bug `closed` until reporter-verify **or** timeout policy in [`EVIDENCE_CONTRACT.md`](./EVIDENCE_CONTRACT.md).
- Do not assume permissions (PR merge, deploy, in-app write) without Capability Registry evidence.
- Do not re-enable sunrise `autonomous-loop` without fixing self-dispatch probes.

## Agent deploy / reply conditions

Allowed only when Capability Registry lists the capability as **Proven** for this environment, and Evidence Contract fields are filled.

## Change process

PR that updates this file must bump Version and append [`GOVERNANCE_CHANGELOG.md`](./GOVERNANCE_CHANGELOG.md).

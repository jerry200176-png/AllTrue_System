# Company Constitution

**Version:** 0.2.0
**Effective:** 2026-07-18  
**Revised:** 2026-08-29 (Founder T0–T3 autonomy decision)
**Owner:** Founder / CTO Agent  
**Scope:** AllTrue System + sunrise-cafe (portfolio)  
**Canonical:** This file is tool-neutral. Cursor Rules / CLAUDE.md / Skills are adapters only.

## Purpose

Define non-negotiable decision order, safety floors, and success definitions so any engineer or coding agent can operate without relying on chat history.

## Decision precedence (highest wins)

1. **Production safety & data integrity** (billing, attendance, auth, PII)
2. **This Constitution**
3. **Fleet operator table** — `jerry200176-png/portfolio-ops` [`governance/AUTONOMY_POLICY.md`](https://github.com/jerry200176-png/portfolio-ops/blob/main/governance/AUTONOMY_POLICY.md) + [`docs/fleet-merge-policy.md`](https://github.com/jerry200176-png/portfolio-ops/blob/main/docs/fleet-merge-policy.md). Portfolio-ops is the control plane. Product overlays add required checks and domain P0; they **must not** add a Founder rubber-stamp.
4. [`CONTROL_PLANE_CONTRACT.md`](../CONTROL_PLANE_CONTRACT.md) / [`CONTRADICTION_REGISTRY.md`](../CONTRADICTION_REGISTRY.md) (AllTrue **deploy** authority: `deploy.yml` on `main` only)
5. Product overlay SOPs (AllTrue / sunrise-cafe)
6. `AGENTS.md` → `CLAUDE.md` → `.cursorrules` / `.cursor/rules` (adapters)
7. Chat / Plan / transcript (never canonical)

If Claude or Cursor text says it “overrides everything,” **Constitution + fleet AUTONOMY_POLICY + Control Plane still win**.

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
- Do not require Founder approval on every PR — follow [`RISK_BASED_MERGE_POLICY.md`](./RISK_BASED_MERGE_POLICY.md) (R0–R3).
- Do not re-ban Agent squash-merge after **required** GitHub checks for T0–T2. T3/protected work stops before the protected action when a Founder decision is required. Machine bans stay: force-push, `--admin`, production SSH / artisan / phpunit, secret print, Gmail trash/delete.
- Do not execute production data repair without an immutable Repair Manifest + Data Repair Gate (R3) and the Founder approval required by the protected-action boundary.

## Agent deploy / reply conditions

Allowed only when Capability Registry lists the capability as **Proven** for this environment, and Evidence Contract fields are filled.

## Change process

PR that updates this file must bump Version and append [`GOVERNANCE_CHANGELOG.md`](./GOVERNANCE_CHANGELOG.md).

**24-hour cool-off, no same-day feature merge**: a PR that changes this file, `CONTROL_PLANE_CONTRACT.md`, or `AUTONOMY_POLICY.md` must not merge on the same calendar day (Asia/Taipei) as any non-governance feature/fix PR, and must sit at least 24 hours between merge and first use of any capability it grants. This applies especially to any change that expands what an Agent may do without a human click (e.g. self-merge authority) — an expansion that takes effect the same day it's written has no observation window before it's exercised. Reason: the 2026-08-15 external review found a same-day case of exactly this (#1792/#1793 self-merge authority, used same day).

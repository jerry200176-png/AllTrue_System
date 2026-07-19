# Backlog Portfolio — 2026-07-19 (WIP snapshot)

**Method:** Evidence → classify → prioritize (not issue age / shout volume).  
**WIP caps:** incident≤1 · impl PR≤2 · investigation≤2 · data repair≤1 · Founder-wait separate.

## A. Incident closeout (done this round)

| Item | Class | Evidence | Disposition |
|------|-------|----------|-------------|
| Leave/makeup production closeout | Already fixed (forward path) | Probes ok; HEAD evidence run `29680051696` | Closed via PR #1340 + teacher wording |
| Follow-up Issues | Governance | Actions created **#1342**, **#1343** (run `29684840469`) | Links in closeout + TECH_DEBT; PR #1344 merged |
| Historical 96 leave-slot candidates | Reliability / data correctness | Dry-run 96; HC **19** | Active → #1342 CSV-first; **no auto execute** |
| TD-059 package minutes | Technical debt → investigate | Code path confirmed; prod volume TBD | Active investigation → #1343; schema blocked |

## B. Open engineering surface (pre-audit)

| Source | Note |
|--------|------|
| Open PRs (`gh pr list`) | **0** open at snapshot time |
| Phase-2 picks (#1292, #1200) | No longer open PRs — treat as superseded/merged/closed; re-verify after Issues dump |
| TECH_DEBT Open (high signal) | TD-059 (#1343), TD-060 dead code, TD-061 OSV residual, TD-014 Laravel major |
| In-app bugs | Dump via `bug-queue-dump.yml` / portfolio workflow artifact |
| GitHub Issues | Agent App **cannot list** (403); Actions dump via `ops-portfolio-td059-leave-audit.yml` artifact `open-issues-dump` |

## C. This-round selection (WIP)

| Slot | Work | Exit gate |
|------|------|-----------|
| Investigation #1 | TD-059 read-only prod audit | `td059-audit.json` → go/no-go on #1343 |
| Parallel low-risk | Director HC CSV pack + runbook (#1342) | Redacted HC artifact + SOP; selected=0; no execute |
| Impl PR | None until audit says implement | — |

## D. Priority model (reminder)

P0: data corruption, wrong charge/deduct, security, prod down, silent failure unmonitored.  
P1: core domain invariant gaps, proven drift, unreliable critical jobs.  
P2: UX friction / ops cost.  
P3: cosmetic / speculative refactor.

## E. After artifact lands

Update this file with: issue classifications, top ROI active item, Founder gates only if required.

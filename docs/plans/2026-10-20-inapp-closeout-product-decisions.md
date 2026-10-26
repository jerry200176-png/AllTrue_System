# In-app closeout — product decisions still blocking D/E

**Date:** 2026-10-20  
**Scope:** Non-overlap remaining-18 IDs that cannot finish Goal D/E without a product/Founder choice.  
**Out of scope:** Tip activation, production-write GO, Dot Track B PASS, Xinzhuang fee apply (separate owners).  
**Rule:** Cloud Agent will **not** implement competing designs for protected leases `#4161`/`#4163`/`#4215`/`#4216`/`#4199`.

## Summary table

| in-app | GH | Blocker type | Decision needed | Engineering path if GO |
|--------|-----|--------------|-----------------|------------------------|
| 398 | #4013 | Product | KEEP_LEDGER_HOP vs DIRECT_ENTRY vs HOLD | DIRECT_ENTRY = T2 CourseManagement entry → PaymentEntryModal |
| 410 | #4152 | Flag / protected PRs | No new product invent — Jerry proposal already encoded | Drafts `#4215`/`#4216` + checklist `#4210`; flag `LEAVE_SUCCESSOR_SHIFT_APPLY_ENABLED` OFF until checklist |
| 412 | #4153 | Founder T3 | Amount rule A/B1 vs B2/B3 vs C (design notes list 10 questions) | Sibling remaining-lesson credit; billing table needs Founder approval |
| 413 | #4154 | Product enhancement | Which inbox lanes auto-clear; what “handled” means | Count-derived clear already exists for some dashboard tasks; no blanket auto-resolve |

## 398 — entry hop after「登記繳費回報」

**Evidence:** Dot view confirmed extra hop: 課程查找 → 學生帳務 → 登記這筆. `#4091` only fixed confirm→receipt.

| Option | Meaning | Closeout effect |
|--------|---------|-----------------|
| KEEP_LEDGER_HOP | Accept residual hop | Send residual draft; do not claim hop removed |
| DIRECT_ENTRY | Open payment entry from course lookup | New T2 PR + tests; then rewrite draft |
| HOLD | No change | Keep ready-for-human |

**Agent:** HOLD implement until Jerry chooses. Draft already states hop still exists.

## 410 — leave × renewal overlap

**Evidence:** Jerry 2026-10-15 proposal = shift renewal one slot + preview. `CourseLeaveCascadeSuccessorShiftService` encodes Founder design. Protected drafts `#4215`/`#4216` + `#4210` pre-enable checklist.

**Agent:** Do not fork. Closeout only after flag-on evidence + write GO + public reply.

## 412 — sibling remaining-lesson transfer

**Evidence:** Jerry triage = new feature; design update ready; **amount rule** still needs Founder. Recommended A/B1 pro-rata on actual received value; not C.

**Agent:** No code until Founder answers amount rule (+ remaining design questions). Draft = feature recorded only.

## 413 — director inbox auto-clear

**Evidence:** Some dashboard tasks already disappear when counts hit 0. Notifications mark-read is intentional (R19). Blanket auto-resolve is enhancement, not confirmed regression.

**Agent:** No implement until product defines lanes + “handled” predicates.

## What this document does / does not do

- **Does:** Make D/E blockers precise so Founder/Dot can answer without re-research.
- **Does not:** Claim Goal Done, send in-app replies, approve trains, tip-activate, or invent fee IDs/amounts.

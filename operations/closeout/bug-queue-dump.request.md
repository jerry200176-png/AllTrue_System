# Request: in-app bug queue dump (read-only)

Trigger: `bug-queue-dump.yml`  
Purpose: weekly triage sweep — pull current `new`/`triaged`/`in_progress` queue to check for reports submitted since the 2026-07-22 closeout (#207/#205/#198) and since #208/#210 (this PR's Phase C).  
**No writes.**

# kickoff 2026-07-30T04:05:00Z — weekly triage sweep, check for new reports since 2026-07-22 / #210

# kickoff 2026-07-30T05:24:00Z — final re-dump after #211 resolved (PR #1528/#1529); confirm zero remaining new/triaged/in_progress items besides the still-open, Founder-stop-blocked #208

# kickoff 2026-07-30T12:06:00Z — final authoritative re-dump after #208 resolved (PR #1535, Phase-C write-back run 30541032938); confirm queue is fully empty (new=0, triaged=0, in_progress=0)

# kickoff 2026-08-07T11:45:03Z — new in-app bug report reported by user this session; pull current open queue for Phase A triage

# kickoff 2026-08-19T02:30:53Z — new in-app bug report, user asked to process it this session; pull current open queue for Phase A triage

# kickoff 2026-08-19T03:05:01Z — re-dump immediately alongside bug-detail-dump for bug_id 239 in the same merge, to keep both within the 15-min freshness window for §3.6 evidence validation

# kickoff 2026-08-19T03:17:25Z — merge conflict from PR #1895 squash-merge required resolving; re-kicking with a fresh timestamp so this run is the one actually paired with the bug-detail-dump for bug_id 239

# kickoff 2026-08-20T05:54:37Z — user asked "isn't there still an in-app bug" this session; pull current open queue to check for anything outstanding

# kickoff 2026-08-28T04:39:59Z — user explicitly asked to process all outstanding in-app bug reports through root-cause fix, CI, production deploy, and in-app follow-up; pull a fresh authoritative queue before triage

# kickoff 2026-08-31T07:34:00+08:00 — refresh the paired queue/detail evidence for in-app bug #247 before deciding whether the reported calendar capacity failure is actionable

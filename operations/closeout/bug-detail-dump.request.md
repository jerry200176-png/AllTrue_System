# Request: single bug detail dump (read-only)

bug_id: 247

Purpose: Phase A triage for in-app bug #247 linked to GitHub #2179. Obtain the current description, attachments, comments, status logs, and reporter's complete history/cross-campus record per CHAT_BUG_SYSTEM.md §3.6 before proposing a root cause.
**No writes.**

# kickoff 2026-08-31T08:09:30+08:00 — re-trigger the paired queue/detail evidence after restoring the main CI→deploy event chain; target in-app bug #247

# kickoff 2026-09-04T09:41:56Z — Founder-requested full in-app bug triage; refresh paired queue/detail evidence for the current open queue

# kickoff 2026-08-28T04:45:30Z — fresh queue identifies open in-app #245; obtain complete description, attachments, comments, status logs, and reporter history for Phase A triage

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)

# kickoff 2026-07-30T04:56:00Z — retry after fixing StudentClass updated_at (StudentClass has no created_at/updated_at timestamps; only `MDate` — query previously selected the nonexistent `sc.updated_at`)

# kickoff 2026-07-30T05:02:00Z — #208 evidence captured; re-pointing at bug_id 211 for its detail read

# kickoff 2026-07-30T11:52:00Z — post-repair verification for #208, re-pointing bug_id back to 208

# kickoff 2026-08-07T11:52:30Z — Phase A triage for new bug #224 (student class cannot be moved/deleted), re-pointing bug_id to 224; must land within 15 min of bug-queue-dump run 31175636740 (generated 2026-08-07T11:49:48Z) for freshness gate

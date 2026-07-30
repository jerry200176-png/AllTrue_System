# Request: single bug detail dump (read-only)

bug_id: 211

Purpose: #208 evidence is now confirmed (run 30515306009, 2026-07-30T04:58:30Z) — course 2825's RemainingSessions is still stale (96), package-level total/remaining is 56/55, and the ledger shows only StudentClass 2824 (not 2825) received an attendance-driven correction. #208 stays open pending read-only investigation of the recompute endpoint (Founder-stop; no write). This request now re-points the same detail-dump probe at **#211** (page_key `students`, campus_id 3, title mentions "固定日期") to read its description/comments/attachments before starting root-cause investigation. Note: the workflow's `probe_208_package_recompute` section is still hardcoded to package 124/course 2825 — that part of the output is stale/irrelevant for #211 and will be ignored; the top-level `bug`/`attachments`/`comments`/`status_logs` fields correctly follow `bug_id`.
**No writes.**

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)

# kickoff 2026-07-30T04:56:00Z — retry after fixing StudentClass updated_at (StudentClass has no created_at/updated_at timestamps; only `MDate` — query previously selected the nonexistent `sc.updated_at`)

# kickoff 2026-07-30T05:02:00Z — #208 evidence captured; re-pointing at bug_id 211 for its detail read

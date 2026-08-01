# Request: single bug detail dump (read-only)

bug_id: 208

Purpose: Post-repair verification for #208. `package-124-guarded-repair.yml` run 30540075390 (2026-07-30T11:50:08Z) reported `ok:true` and applied `PackageDeductionService::fullRecompute(124)` after all 10 preconditions passed, including `security_fix_deployed` (PR #1531, prod_head confirmed equal to the fix commit). Reported result: package 124 unchanged (56/1/55), course 2824 unchanged (RemainingSessions=55, UsedSessions=1), course 2825 RemainingSessions 96→55 (UsedSessions unchanged at 0), ledger unchanged (1 row, net -1). This dump re-reads the same package 124 / course 2825 / ledger probe directly from a fresh production read to independently confirm that result before any Phase-C write-back on #208.
**No writes.**

# kickoff 2026-07-30T04:48:00Z — retry after fixing StudentClass campus lookup (StudentClass has no CampusID column; campus lives on Student, joined via StudentID — query previously selected the nonexistent `sc.CampusID`)

# kickoff 2026-07-30T04:56:00Z — retry after fixing StudentClass updated_at (StudentClass has no created_at/updated_at timestamps; only `MDate` — query previously selected the nonexistent `sc.updated_at`)

# kickoff 2026-07-30T05:02:00Z — #208 evidence captured; re-pointing at bug_id 211 for its detail read

# kickoff 2026-07-30T11:52:00Z — post-repair verification for #208, re-pointing bug_id back to 208

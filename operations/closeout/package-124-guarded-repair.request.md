# Request: Package 124 guarded repair (single target, atomic precondition-gated)

Trigger: `package-124-guarded-repair.yml`

**Scope: package_id 124 ONLY, members 2824 and 2825 ONLY.** No other package or member may be touched.

## Authorization
User has explicitly approved this exact repair for in-app bug #208, contingent on:
1. The cross-campus authorization fix (PR #1531, merge `9adc3633d41126263f0025cdadd7a99cea939f4e`) being deployed to production — confirmed via deploy run https://github.com/jerry200176-png/AllTrue_System/actions/runs/30518848492 (success, 2026-07-30T06:12:41Z).
2. All mandatory preconditions holding at execution time (checked atomically in the same script run as the mutation — see below).

## Mandatory preconditions (all must hold; any failure aborts with NO write)
- package 124 exists
- total_sessions = 56
- used_sessions = 1
- remaining_sessions = 55
- package member IDs exactly {2824, 2825}
- course 2824 RemainingSessions = 55
- course 2825 RemainingSessions = 96
- course 2825 UsedSessions = 0
- production revision is a descendant of the security fix commit `9adc3633d41126263f0025cdadd7a99cea939f4e`
- package_session_ledger for package 124 has exactly 1 row, net delta -1, all rows attributed to student_class_id 2824 (consistent with prior evidence from bug-detail-dump.yml runs 30515306009/30515460466)

## Authorized mutation (only if ALL preconditions above pass)
`\App\Services\PackageDeductionService::fullRecompute(124)` — the existing, unmodified repair method (now that its HTTP entrypoint is properly campus-gated). No ledger rebuild, no manual SQL, no changes to `total_sessions`, no changes to any other package.

## Expected result
- package 124: total_sessions=56, used_sessions=1, remaining_sessions=55 (unchanged — already correct at the package level)
- course 2824: RemainingSessions=55, UsedSessions=1 (unchanged)
- course 2825: RemainingSessions=55 (was 96), UsedSessions=0 (unchanged — no ledger rows reference 2825)
- package_session_ledger: unchanged (1 row, net -1) — `fullRecompute` only reads the ledger, never writes to it

## Artifact
Full before/after snapshot, precondition check results, service result, execution identity, production revision, timestamp, and workflow run ID are captured and uploaded as `out/repair-result.json`.

**No writes if preconditions fail.**

# kickoff 2026-07-30T06:14:00Z — guarded repair for #208, security fix confirmed deployed

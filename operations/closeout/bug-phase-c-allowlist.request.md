# Request: Phase-C allowlist resolve

Trigger: `bug-phase-c-allowlist.yml`  
Allowlist: in-app **#210** (PR #1482 / merge `90584f0` / deploy run `30325356011`) — code merged to `main` and confirmed deployed (`version.json` hash matches current `origin/main` HEAD `e202e4d8`, merge commit is an ancestor); public reply + resolved.  
Also idempotent: **#207**, **#205**, **#198** (skip if already resolved/closed).

**#208 is intentionally NOT in this allowlist.** Code (PR #1511 / merge `990a614` / deploy run `30440456321`) is confirmed deployed, but that only proves the *forward-looking* fix (future edits auto-recompute) is live — it does not prove the specific already-affected record (package 124 / course 2825) was corrected, since that requires a separate one-time `POST /course-packages/124/recompute` call that nothing has confirmed ran. Do not mark #208 resolved or claim "already corrected" until `bug-detail-dump.yml` (see `bug-detail-dump.request.md`, bug_id 208) confirms course 2825's `RemainingSessions` is no longer stale.

Evidence:
- #210: issue https://github.com/jerry200176-png/AllTrue_System/issues/1476, merge https://github.com/jerry200176-png/AllTrue_System/pull/1482, deploy https://github.com/jerry200176-png/AllTrue_System/actions/runs/30325356011 (success, 2026-07-28T03:13:56Z)
- Health: `{"status":"ok"}` 2026-07-30

# kickoff 2026-07-30T04:20:00Z — Phase C write-back for #210 only; #208 deferred pending recompute evidence

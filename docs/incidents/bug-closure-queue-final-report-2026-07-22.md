# Bug Closure Queue — Final Report (2026-07-22)

## Executive Summary

| Item | Result |
|------|--------|
| Live inventory | Pre-fix dump (#1368): new=1 → **Post-closure dump `29891315005`: new=0, triaged=0, in_progress=0, open=[], resolved=108, closed=99**, max_id=207 |
| Only actionable open bug | **in-app #207** (course teacher rewrite of past sessions) |
| Engineering WIP | **#207 closed end-to-end**: fix PR [#1374](https://github.com/jerry200176-png/AllTrue_System/pull/1374) → deploy `29890459105` (Pi HEAD=`7acb5803`) → Phase C PR [#1375](https://github.com/jerry200176-png/AllTrue_System/pull/1375) / run `29891109925` → **status `resolved`** + public reply |
| #205 Phase C | Already **closed** before this run (allowlist skip) |
| Production | Health `{"status":"ok"}`; backend HEAD `7acb5803` (frontend `version.json` hash lag expected for backend-only deploy) |
| Parked (Founder) | [#173](#173), [#1062](#1062), [#1342](#1342), [#189/#191/#190](#189191190) — packet: [`bug-closure-founder-decisions-2026-07-22.md`](bug-closure-founder-decisions-2026-07-22.md) |
| A/D backlog | ~23 reporter-verify + ~86 admin-close candidates — **not** mass-closed (Closure Policy: no open/reopened active work; ≤10/batch) |
| Overlapping abandoned code PRs | None for this queue (dependabot only at start; #1374/#1375 merged) |

**Verdict:** Active engineering queue cleared. Remaining items are Founder/director/channel gates or Closure Policy A/D timeout batches — not code WIP.

---

## Per-Bug Status

### Closed this run

| Bug | Severity | Outcome | Evidence |
|-----|----------|---------|----------|
| **#207** 陳宇斯改正班老師後，已上課堂次老師被改寫 | P1 UX / schedule truth | **resolved** | RCA: class-sessions display falls through to new `StudentClass.TeacherID` without substitute pin. Fix: pin past/attended to former teacher before future schedule sync. PR #1374, deploy `29890459105`, Phase C `29891109925` (`from=new` → `final=resolved`). Public reply asks reporter to refresh calendar; residual historical wrong display → reply for one-shot pin. |
| **#205** 代課 picker | P1 UX | Already **closed** | Allowlist skip; prior code #1325 on prod |
| **#198** 兼職費率生效日 | — | Already **closed** | Allowlist skip |

### Parked — Founder / director / channel (no code WIP)

| ID | Why parked | Next owner action |
|----|------------|-------------------|
| **#173** | Owner Execute workflow never run successfully | GO phrase + backup for `173-supersede-repair.yml` |
| **#1062** | Stranded prepaid forward-gen (~1.5k+ sessions; G-010) | GO active-slice dry-run only; no blank-cheque bulk |
| **#1130** | Cross-SC data repair | Founder GO per package |
| **#1342** | Leave-HC CSV `awaiting_delivery` / `skipped_no_line` | Ops channel (LINE group or alternate); directors approve IDs |
| **#189 / #191 / #190** | Historical billing row repair | Case-by-case CEO GO on manifests; do not reopen without new evidence |
| **#1343** TD-059 | Latent billing risk | Monitor only (`ops-td059-monitor.yml`); no schema |

### Closure Policy backlog (not started)

| Bucket | Approx | Rule |
|--------|--------|------|
| Reporter verify (A) | ~23 incl. #158/#159/#189–191/#194/#196/#200 | Timeout only via `BUG_REPORTER_TIMEOUT.md` + evidence; no reopen without new evidence |
| Admin close (D) | ~86 stale resolved | Only when no open/reopened; ≤10/batch |

---

## Systemic Findings

1. **Contract teacher vs session display (G-class lesson from #207)**  
   Calendar/session teacher is **not** stored on `ClassSession` as the display source of truth; it is substitute schedule pin **or** live `StudentClass.TeacherID`. Changing the contract teacher without pinning history rewrites the past. Fix is pin-on-change; optional follow-up: store effective teacher on session at attendance time.

2. **Cloud-agent inventory path**  
   Issues API 403 + no local Pi SSH + `workflow_dispatch` 403 → inventory/Phase C must use **push-triggered** allowlist/dump workflows. Billing spend limit previously blocked dump; Deploy/Pi Health OK as of 2026-07-22.

3. **PHPStan baseline brittleness**  
   Ignored `StudentClass::$ID` count is exact. `($x->ID ?? 0)` does **not** count toward the pattern; bare `(int) $x->ID` does. Prefer matching existing access form over "cleaner" getKey()/null-coalesce when touching baselined files.

4. **Backend-only deploy vs `version.json`**  
   Deploy success can leave `version.json` hash behind Pi git HEAD. Trust deploy log `Pi git HEAD=` + health, not frontend hash alone, for backend fixes.

5. **Founder gates remain the bottleneck for money/sessions integrity**  
   #1062 / #173 / #1342 / historical billing cannot be closed by engineering alone without irreversible-write GO.

---

## Definition of Done checklist

- [x] Full inventory from production dump authority  
- [x] Duplicates / already-closed probed (#205/#198/#200… closed)  
- [x] Fixable bug (#207) through prod verify + reporter reply  
- [x] Parked items documented for Founder/permission/channel  
- [x] No overlapping abandoned bug-fix PRs  
- [x] Production healthy after deploy  
- [x] This final report  

**Residual (explicit):** one-shot historical pin for 陳宇斯 past sessions if reporter confirms still wrong after refresh; A/D timeout batches; Founder packets awaiting GO.

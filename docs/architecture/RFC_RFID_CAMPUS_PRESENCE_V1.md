# RFC: RFID Campus Presence v1

**Status:** Accepted (Founder policy locked 2026-09-16)  
**Epic:** [#2809](https://github.com/jerry200176-png/AllTrue_System/issues/2809) (in-app #293)  
**Tier:** T3 product direction (docs/contracts here = RFID-0; code slices RFID-1+)  
**Supersedes product intent of:** Phase 2 presence-window PRD auto-attend + auto-deduct on door swipe

---

## 1. Locked product policy (v1)

1. RFID proves **campus presence** only.
2. Campus presence is **not** course attendance.
3. Teacher/manual attendance remains authoritative for course attendance.
4. A raw RFID swipe must **never** directly:
   - mark a `ClassSession` attended/late
   - deduct sessions
   - mutate billing/session counters
   - create attendance backfill with billing effects
5. RFID may produce **candidate / suggested** `ClassSession` evidence for the teacher.
6. The existing canonical attendance path remains responsible for course attendance, `AttendanceEffectsService`, `SessionDeductionService`, and billing/session counters.
7. Do **not** remove teacher manual attendance.

**Mechanical invariant:** a presence event cannot directly cause session deduction.

---

## 2. Current runtime vs target (do not confuse)

| Layer | Current `origin/main` behavior | Target under `FEATURE_RFID_PRESENCE_ONLY` |
|---|---|---|
| `POST /api/v1/swipe-rfid` student match | Creates course-bound `StudentSingIn`, may `applySessionStatus`, **deducts** | Writes **presence only** |
| Sign-out `backfillPresenceWindow` | Invents `Memo=presence-window` SignIns + deduct | **Disabled** |
| Teacher swipe | `TeacherSingIn` toggle | Unchanged (ops clock; not course attendance) |
| Unknown card | `TempRfid` 5 min | Unchanged bind buffer |
| Manual `POST /attendance` | Canonical mark + effects + deduct | **Sole** normal billing-authoritative path for door-originated evidence |

Until RFID-2 ships and the flag is on for a campus, **runtime still matches the left column**. Agents must not assume target behavior is live.

Authoritative runtime for today’s door reader: `SwipeRfidController` + routes — not stale narrative in older API docs.

---

## 3. Architecture decision

### 3.1 Why `StudentSignIn` cannot remain dual-purpose

`StudentSingIn` is overloaded today (open `SignOutDT` = on campus; `Status=present` + `ClassSessionID` = course attendance). Downstream **does not honor Memo**:

- Payroll payable codes include `present` / `late`
- Parent portal maps SignIns to subject + 「到班」
- Teacher attendance index filters `TeacherID`
- `SessionDeductionService` / observed used-sessions read `SessionDeducted`

Soft Memo separation cannot make the invariant mechanically hard to violate.

### 3.2 Decision

| Option | Verdict |
|---|---|
| A — Reuse `StudentSignIn` with strict source/status | **Rejected** |
| B — Dedicated student campus-presence domain | **Accepted** |
| C — Dual-write hybrid | Migration tactic only, not end state |

Teacher clock already uses dedicated `TeacherSingIn`; student campus presence follows the same boundary.

### 3.3 Canonical presence entity (RFID-1)

**`StudentCampusPresence`** (name may bikeshed in migration; semantics fixed):

| Field | Role |
|---|---|
| `CampusID` | Reader campus |
| `StudentID` | Person |
| `Source` | `rfid` \| `manual` \| `system` |
| `DeviceID` | nullable reader identity |
| `ArrivedAt` / `DepartedAt` | lifecycle |
| `Status` | `open` \| `closed` \| `orphan_closed` \| `voided` |
| `CloseReason` | `swipe_out` \| `orphan_job` \| `manual` \| `void` |
| `IdempotencyKey` | unique dedupe |
| Void audit columns | correction without billing touch |

**Candidates:** compute at read time from presence ∩ today’s eligible `ClassSession` (exclude leave family / cancelled). No candidate table in v1.

**Card binding SSOT:** keep `Student.RFID` + `UserCampus.RFID`. Add minimal `RfidCardAudit` in RFID-3 (bind/unbind/replace).

**Presence writers must not import** `SessionDeductionService` or `AttendanceEffectsService`.

---

## 4. Attendance integration contract

```text
RFID swipe
  → StudentCampusPresence
  → GET candidates (computed)
  → Teacher confirms via existing:
        POST /api/v1/attendance
        POST /api/v1/attendance/batch-mark
  → AttendanceController (+ governed ClassSession/Leave paths)
  → AttendanceEffectsService
  → SessionDeductionService   ← only billing-authoritative family for this flow
```

### 4.1 `SwipeRfidController` under presence flag

| Path | Action |
|---|---|
| `applySessionStatus` on student create | Disable |
| `deductOnAttendance` on student create | Disable |
| Course-bound `StudentSingIn::create` | Redirect → presence only |
| `findMatchingClass` for write binding | Redirect → suggestion/read only |
| `backfillPresenceWindow` | Disable |
| Teacher `TeacherSingIn` | Keep |
| `TempRfid` unknown | Keep |
| JSON `ok/type/action/student/campus` | Keep for Pi compatibility; may add `presence_id` |

---

## 5. Visibility contract

### Teacher

- Current campus-presence state, arrival, departure
- Ranked candidate `ClassSession`(s); ambiguous → review
- Presence evidence badge on attendance UI (**not** auto-attended)
- Exceptions: leave+arrived, orphan open, device stale

### Parent

- Arrive/leave may use presence evidence (Telegram already 「到班|離班」)
- Do **not** present raw RFID as proof a specific course was attended
- Distinguish **到班** (presence) from **課程出席** (confirmed SignIn)

### Admin / director

- Who is on campus (open presence)
- Unknown cards (`TempRfid`)
- Missing checkout / orphan close (presence only — no billing backfill)
- Device health (RFID-6)
- Card bind/replace audit (RFID-3)

---

## 6. Feature flags (planned names)

Use existing `App\Helpers\FeatureFlag` (`FEATURE_{KEY}`, optional `_CAMPUS_{id}`).

| Key | Purpose | Default |
|---|---|---|
| `rfid-presence-only` | Swipe writes presence; no deduct/effects/backfill | `false` |
| `rfid-evidence-ui` | Teacher presence-evidence UI | `false` |
| `rfid-parent-presence-semantics` | Portal split 到班 vs 課程出席 | `false` |
| `rfid-auto-attend` | RFID-7 optional automation | `false` (remain off) |

Canary: enable `FEATURE_RFID_PRESENCE_ONLY_CAMPUS_{id}=true` before global.

---

## 7. Implementation slices

| ID | Outcome | Notes |
|---|---|---|
| **RFID-0** | This RFC + doc cross-links + program status | This PR |
| **RFID-1** | `StudentCampusPresence` + APIs + candidate query | Additive migration |
| **RFID-2** | Re-boundary `SwipeRfidController` behind flag | Billing fence; Pi-compatible |
| **RFID-3** | Bind/unbind/replace + `RfidCardAudit` | SSOT columns unchanged |
| **RFID-4** | Teacher evidence UI | Confirm still canonical POST |
| **RFID-5** | Parent 到班 ≠ 課程出席 | Portal + Telegram hygiene |
| **RFID-6** | Device id / heartbeat / retry / offline queue | Thin; no IoT platform |
| **RFID-7** | Optional automation | Calls canonical attendance API only; default off |

Safer order: RFID-0 → 1 → 2 (canary fence) → 3 → 4 → 5 → 6 → 7.  
RFID-2 may canary **before** RFID-4 UI if evidence UI lags; never ship deduct-on-swipe once flag is on.

---

## 8. Migration / compatibility

- Preserve all historical `StudentSingIn` / ledger rows; do not rewrite Memo/Status
- No retroactive billing mutation
- Flag rollout by campus; rollback = flag off
- Keep `POST /api/v1/swipe-rfid` URL/auth/body; additive response fields only
- Legacy `POST /attendance/swipe` + `PendingSwipe` remains a separate pipeline (quarantine; do not expand)

---

## 9. Non-goals (v1)

- Auto-deduct or auto-attend from door swipe
- Removing teacher manual attendance
- Destructive schema changes to `StudentSingIn`
- Campus.Token redesign without security review
- Hardware purchase/deploy in planning tickets
- Generic event platform

---

## 10. Founder decisions already locked

Policy §1 and architecture §3.2 (option B) are locked.

### Still open (do not block RFID-0/1 docs+schema design)

| Item | Blocks |
|---|---|
| Canary campus id for first flag on | RFID-2 production canary only |
| Dual-write vs presence-only during UI lag | RFID-2 rollout tactic |
| Hard-fail bind on teacher+student UID vs keep R33 | RFID-3 |
| Server-side Telegram vs Pi client | RFID-5/6 |
| Per-device credentials vs shared campus token | RFID-6 |
| Whether RFID-7 is ever desired | RFID-7 |
| Retire legacy `attendance/swipe` | Later ops |

---

## 11. Program status pointer

Canonical running status: [`docs/programs/RFID_2809_STATUS.md`](../programs/RFID_2809_STATUS.md)

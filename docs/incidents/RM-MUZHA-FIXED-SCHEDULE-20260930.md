# Repair Manifest — 木柵固定數學課 2026-09-30

Status: POP migration prepared; production execution requires a successful POP dry-run and authenticated database approval.
Risk: R3/T3. The Founder approved the four exact course changes in this conversation; the POP approval event remains a separate runtime gate.

## Business truth and exact targets

Effective from Saturday 2026-10-03, for all remaining regular occurrences:

| StudentClass | Student | Current contract | Intended contract | Count / used / remaining |
| --- | --- | --- | --- | --- |
| 3428 | 373 張正樂 | Sat 19:30–21:30 | Sat 10:00–12:00 | 8 / 4 / 4 |
| 3429 | 374 張正甯 | Sat 19:00–21:00 | Sat 10:00–12:00 | 8 / 4 / 4 |
| 2332 | 155 吳宏逸 | Sat 15:00–17:00 | Sat 15:00–17:00 | 16 / 4 / 12 |
| 2335 | 156 吳宛庭 | Sat 15:00–17:00 | Sat 15:00–17:00 | 16 / 13 / 3 |

All four are 木柵 CampusID 16, math SubjectID 66, TeacherID 29,
ClassType `one_on_two`, ScheduleMode `count`, Stop 0, and two-hour lessons.
The two Zhang rows are one pair and the two Wu rows are another. The Wu
contract times are already correct, but most of their regular future
ClassSession times are stale.

The original report spelled 正甯 as 正寗 and 宏逸 as 弘毅. The Founder confirmed
these four exact production identities and later approved the fixed Saturday
times, including both 10/03 afternoon exceptions. Runtime POP approval remains
required for production execution.

## Exact preconditions

The POP strategy `backend/app/Operations/Strategies/MuzhaFixedScheduleStrategy.php`
checks student identity/campus, contract keys, counts, dates, state, teacher,
subject and duration. It locks and compares **all 32 future ClassSession IDs**
with exact date, start/end, status and exception flag. Any additional or
changed future row aborts the transaction. Target rows must have no linked
sign-in or approved learning record. A production backend SHA drift also aborts.

Expected future rows are encoded in the immutable executor and grouped below:

- `3428`: 33781 (10/03 10–12 exception), 33782 and 33784 (cancelled),
  33783/42412/42472 (scheduled at old 19:30).
- `3429`: 32870 (10/03 10–12 exception), 32881 (cancelled),
  32871/32872/41621 (scheduled at old 19:00).
- `2332`: 19267 (10/03 17–19 exception), 19268–19275 and
  32735/33815/35545 (scheduled at old 10:00).
- `2335`: 19324 (10/03 17–19 exception), 19325/19326 (15–17 exceptions),
  19328/19329/19320/19321/19322 (cancelled),
  33814 (12/05 scheduled at old 10:00).

## Authorized POP mutation after dry-run and database approval

The application POP API creates an authenticated draft and read-only dry-run.
An authenticated super_admin approval through the same API must bind the deployed
SHA. The Pi-local POP scheduler alone executes and verifies the catalog strategy. The strategy checks
all four contracts and 32 future occurrences under locks in one transaction,
updates contract `time` only for 3428/3429, moves **20 scheduled occurrences**
to the intended time, and adopts four already matching exceptions as regular.
It preserves eight cancelled rows, all dates and statuses, attendance, learning
records, counters, charges, payments, teachers, subjects and rooms. The POP
execution record stores a rollback snapshot and verification outcome.

## 2026-10-01 production preflight hold

The first production dry-run, [Actions run 36748360106](https://github.com/jerry200176-png/AllTrue_System/actions/runs/36748360106),
printed `Occurrence precondition drift: 19267` but its job was incorrectly
marked successful. No apply run was started. Read-only authenticated course
details confirmed that the 2026-10-03 exception rows 19267 and 19324 are now
17:00–19:00, whereas the original manifest expected 15:00–17:00; the other 30 future
rows still match. The protected workflow and executor were fixed to fail nonzero. The Founder
clarified that both 10/03 exception rows must move to 15:00–17:00, and this
POP manifest now expects their observed 17:00–19:00 state before applying.

## Recovery and verification

Any failed precondition or postcondition rolls back the entire transaction.
If verification fails after a commit, use the stored POP snapshot and its
rollback strategy after confirming the exact after-state and obtaining the
required rollback approval. Reverting deployed code alone does not reverse
a committed data correction.

Attach the POP request ID, sanitized dry-run, execute and verify records,
deployed SHA, and read-only postcondition audit to closeout. Verify both
course lookup and student management show the two intended Saturday pairs.

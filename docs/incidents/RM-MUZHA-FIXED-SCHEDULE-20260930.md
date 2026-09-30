# Repair Manifest — 木柵固定數學課 2026-09-30

Status: prepared; **production apply is not authorized by this document**.
Risk: R3/T3. The Founder must approve the exact protected action after reviewing
the dry-run. The reporter's requested schedule and identity confirmation are
business evidence, not a substitute for the production Data Repair Gate.

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
on 2026-09-30 that these four exact production identities are the intended
targets. This identity confirmation does not itself authorize the protected
production apply action.

## Exact preconditions

The case executor in `backend/scripts/ops/muzha_fixed_schedule_20260930.php`
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
- `2332`: 19267 (10/03 15–17 exception), 19268–19275 and
  32735/33815/35545 (scheduled at old 10:00).
- `2335`: 19324/19325/19326 (scheduled 15–17 exceptions),
  19328/19329/19320/19321/19322 (cancelled),
  33814 (12/05 scheduled at old 10:00).

## Authorized mutation after Founder GO

The workflow `.github/workflows/ops-muzha-fixed-schedule-20260930.yml` is
the Data Repair Gate. `dry-run` performs the preconditions without a write.
`apply` repeats the same preconditions under locks inside one DB transaction,
then sets only contract `time` on 3428/3429, moves **18 scheduled regular
ClassSession rows** to the new time, and adopts six matching scheduled
exceptions as regular rows. It does not change dates, statuses, cancelled
rows, attendance, learning records, purchased/used/remaining counts, rates,
charges, invoices, payments, packages, teachers, subjects or rooms. Eloquent
ClassSession updates emit the existing schedule audit log. Postconditions are
checked before commit.

## Recovery and verification

Any failed precondition or postcondition rolls back the entire transaction.
If the transaction commits but a later problem is found, stop and prepare a
new Founder-approved inverse operation using the exact old times and exception
flags above; first verify no new attendance, approval or booking has appeared.
Reverting deployed code alone does not reverse a committed data correction.

Attach the protected workflow run URL, sanitized dry-run and apply output,
deployed SHA, and read-only postcondition audit to closeout. Verify both
course lookup and student management show the two intended Saturday pairs.

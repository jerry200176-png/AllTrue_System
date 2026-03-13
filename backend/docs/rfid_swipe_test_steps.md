# RFID Swipe Manual Test Flow

## Prerequisites
- A `Student` with `RFID` set.
- At least one `StudentClass` (Stop=0) for that student.
- One or more `ClassSession` records for the class on the test date.
- `Campus.SwipeWindowMinutes` configured (default 30).
- Create `ApiClient` with `ApiKeyHash` and `CampusID`.

## Test A: Matched Session
1. Call `POST /api/v1/attendance/swipe` with body:
   ```json
   { "RFID": "CARD_ID", "SwipeAt": "2026-02-08 14:05:00" }
   ```
2. Expect **201** and a new `StudentSingIn` record.
3. Verify `StudentSingIn.ClassSessionID` matches the closest session within window.

## Test B: No Session
1. Use a `SwipeAt` with no sessions that day (or outside window).
2. Expect **202** with reason `no_session` or `no_match_in_window`.
3. Verify a new `PendingSwipe` record is created.

## Test C: Ambiguous Session
1. Create two sessions with the same start time distance to `SwipeAt`.
2. Expect **202** with reason `ambiguous_session`.
3. Verify `PendingSwipe` was created with that reason.

## Test D: Campus Mismatch
1. Use an API key bound to Campus A.
2. Swipe with a student belonging to Campus B.
3. Expect **202** with reason `campus_mismatch`.
4. Verify `PendingSwipe.CampusID` is set to Campus A.

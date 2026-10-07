# Phase-C batch 2026-10-07 D (in-app 380, 382)

| In-app | Issue | Basis | rev (merge) | Deploy run |
|---|---|---|---|---|
| 380 | #3720 | #3719: transfer refill (`planSourceScheduleTail`) skips the target renewal's own live rows, so no false 「學生在此時段已有其他課程」; a real clash still rolls back (existing tests kept). | `f662fb7fcc17abb71ede2ffa8d4450da6ccf1a2d` | 37581142543 (head 6b0c3dd, contains the rev) |
| 382 | #3721 | #3723: a manual course created from the calendar opens 「新增下一堂」 instead of being invisible; the duplicate prompt offers it. StudentClass 4296 needs no repair (the director adds its first lesson). | `6b0c3dd2671062859dfe397cdb261541ccf499a7` | 37581142543 |

Both replies offer 「確認已修好」 and 「問題仍存在」. Standing GO B applies (the fix is deployed and the evidence is attached).
Until #3754 is live, dispatch each ID by hand, one at a time, and verify the in-app DB status (F22).
Recovery: the reopen path; if the evidence is found wrong, resolved → in_progress, plus a correction comment and an issue reopen.

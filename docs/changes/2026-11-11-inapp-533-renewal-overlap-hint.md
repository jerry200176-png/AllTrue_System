## 2026-11-11 — fix(enrollment): the renewal-overlap refusal names the parallel-course option (in-app #533)
<!-- release-notes: staff_update=staff-2026-11-11-renewal-overlap-hint -->
- A director adding a second course in the same subject (different lesson length) chose 建立下一期續報 in the conflict dialog and got 422 `renewal_range_overlap`. The message only offered the two renewal fixes (move the start date, or use 續課), not the option that fits this case.
- The message now also says: to run both courses at the same time, fill in the reason and press 建立獨立課程. Copy only; the rule, the code `renewal_range_overlap` and every other path are unchanged. `renewal_next_term` is only sent from that dialog, so the named button is always on screen.

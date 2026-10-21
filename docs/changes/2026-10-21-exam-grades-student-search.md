## 2026-10-21 — feat(exam-grades): find one student in the 段考 score dialog (in-app #420, #4276)
<!-- release-notes: staff_update=staff-2026-10-21-exam-grades-student-search -->
- The score-entry dialog gets a 「找學生」 box that narrows the table to rows matching a student name or exact student number; per-row 儲存 is unchanged and the filter survives the reload after saving.
- Directors see 「查看／登分」 on closed rounds (they can still correct scores there); drafts stay 「查看」 and score inputs stay disabled on drafts, matching the API that rejects draft entry.
- Frontend only; uses the existing `GET /exam-rounds/{id}/students` and `POST /exam-rounds/{id}/scores` with their current campus/teacher scope. The student-profile exam history panel from the hand-off is a follow-up.

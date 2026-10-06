## 2026-10-06 — chore(privacy): production diagnose and ops workflows print ids only, no person names or free text (#3605)
<!-- release-notes: silent_ship=silent-2026-10-06-diagnose-ids-only -->
<!-- silent-reason: 只改內部診斷／維運工作流程的輸出與輸入（log 不再含姓名、備註、電話），教職員看不到差異 -->
- `attendance-case-diagnose` / `student-session-diagnose` / `teacher-signin-diagnose` / `classsession-duplicate-diagnose-push`：輸入改 `student_id`／`teacher_id`，輸出只留 id、狀態、日期、數量（備註只回長度，不再印操作者與經手人姓名）。
- 舊案一次性維運工作流程（#289、#259、#308、SC1513、國文續購等）不再印學生／老師姓名與備註；`ops-director-leave-hc-pack` 的主任審核 CSV（含姓名）改為 `DUMP_ARTIFACT_KEY` 加密 artifact；`multi-guardian-activation` 的 log 遮罩家長電話與姓名。
- 仍會印出個資的後端指令與原始碼內姓名另開 #3626、#3627 追蹤。

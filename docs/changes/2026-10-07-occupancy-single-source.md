## 2026-10-07 — fix(schedule): 補課候選時段的老師忙碌判斷與排課守門一致（#3639）
<!-- release-notes: silent_ship=silent-2026-10-07-occupancy-single-source -->
<!-- silent-reason: 只讓補課候選不再推薦守門會拒絕的時段，或不再漏掉守門允許的時段；畫面與操作不變。 -->
- 補課候選的老師佔用改呼叫 ScheduleGuardService（Stop=0、作廢堂次空出、請假申請中仍佔用、分校篩選、依學生去重），刪除自己的一套計算。SubstituteService 的跨分校忙碌偵測依 PRD 維持跨分校，不改。

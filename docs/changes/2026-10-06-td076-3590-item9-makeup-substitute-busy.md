## 2026-10-06 — fix(scheduling): TD-076 #3590 item 9 補課代課老師的忙碌時段（旗標關閉）
<!-- release-notes: silent_ship=silent-2026-10-06-td076-3590-item9 -->
<!-- silent-reason: 全部在 schedule-occurrence-v2 旗標後面，正式站旗標關閉，教職員看到的畫面與功能完全不變。 -->
- 旗標開啟的分校，補課（extra）堂次的代課老師（只記在未作廢的 LearningRecord）在該時段視為忙碌；`collectTeacherBusySlots*` 與 `ScheduleGuardService` 讀同一個解析器（`teacherForOccurrence`），排除該堂自己的學生。旗標關閉＝結果不變。

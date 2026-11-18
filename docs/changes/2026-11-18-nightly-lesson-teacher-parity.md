## 2026-11-18 — feat(ops): nightly check that a taught lesson's teacher agrees with its evaluation author
<!-- release-notes: silent_ship=silent-2026-11-18-nightly-lesson-teacher-parity -->
<!-- silent-reason: 只新增夜間唯讀檢查（先回報、不擋），不變更產品畫面、資料或操作。 -->

- 夜間資料檢查新增一項：最近三十五天已上課且有評量的堂次，排課判定的授課老師要跟填寫評量的老師是同一人。只讀取、只回報筆數，連續十四晚為零後才改成強制。
- 屬於 #4991 根因家族「堂次歸屬」的預防措施。

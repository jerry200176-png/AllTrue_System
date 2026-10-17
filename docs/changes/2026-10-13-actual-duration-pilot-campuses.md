## 2026-10-13 — billing: 依實際時長扣堂先在新莊分校、新莊中平分校試辦
<!-- release-notes: staff_update=staff-2026-10-13-actual-duration-pilot-campuses -->
- 新增試辦分校名單 `PERF_ACTUAL_DURATION_CAMPUS_IDS`（預設 `11,13`＝新莊分校、新莊中平分校；`*`＝全部分校；空白＝全部不開）。名單外的分校即使課程標成「依實際上課時長換算」，扣堂也照「每次上課扣一堂」，建課與修改課程也會擋下這個選項。Refs #3834

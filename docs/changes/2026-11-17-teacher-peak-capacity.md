## 2026-11-17 — fix(calendar): count simultaneous students across staggered lesson times
<!-- release-notes: staff_update=staff-2026-11-17-teacher-peak-capacity -->

- 修正一對三課程跨兩個相接時段調課時，將不同時間的學生相加而誤判滿班的問題；改以調入期間同時在場的最高人數判斷。
- 一對一獨佔、即將加入班型的上限、試聽條件與老師同時最多三人維持原有規則。真正同時超額、資料不完整及其他校區／教室／學生時間衝突仍由既有守衛阻擋。
- 不自動調課、取消堂次或更動扣堂、帳單與收款。SourceRef: alltrue:bug_report:565；Refs #5436。

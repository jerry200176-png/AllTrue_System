## 2026-11-28 — chore(nightly): 每天數「開始日前的課沒帳單」和「同月帳單漏掉的課」 (#5702, #5703)
<!-- release-notes: silent_ship=silent-2026-11-28-nightly-lesson-bill-shapes -->
<!-- silent-reason: 只新增夜間唯讀檢查（只回報、不擋），不變更產品畫面、資料或操作。 -->

- 夜間檢查新增 `lesson_before_start_unbilled`：月結合約，上過的課日期早於合約開始日，而且沒有任何帳單明細涵蓋那一天（in-app #607 的形狀）。
- 夜間檢查新增 `lesson_uncovered_in_billed_month`：月結合約，某個月已經有帳單，卻有上過的課沒被算進去（in-app #608 的形狀）。
- 目的：同一種問題再出現時，每天的數字會先看到，不用等主任回報。只回報筆數，永遠不擋上線，不改任何資料。

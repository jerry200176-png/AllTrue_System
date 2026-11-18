## 2026-11-18 — feat(billing): 更正帳單 dialog and buttons (step 2, PR 2; default off)
<!-- release-notes: silent_ship=silent-2026-11-18-bill-correction-dialog -->
<!-- silent-reason: 功能預設關閉（bill_correction_campus_ids 為空），沒有任何分校看得到按鈕；教職員卡隨啟用 PR 一起發。 -->

- 新增「更正帳單」對話框：先預覽更正前後金額與多算的上課日，必填原因後才送出；每種拒絕都有一句白話與下一步。
- 學生帳務的帳單列與「超過未繳餘額」的拒絕訊息各多一顆按鈕，只在後端回報可更正時出現。
- 後端學生帳務多一個 `can_correct`，功能關閉時整個欄位不出現。PR #5483 的後端先上，啟用 PR 才發版本更新卡。

## 2026-11-20 — fix(learning-records): every delete keeps the teacher's text (TD-074)
<!-- release-notes: silent_ship=silent-2026-11-20-learning-record-delete-snapshot -->
<!-- silent-reason: 後台保護：刪除評量前一律留唯讀快照；畫面與操作不變。 -->

- 評量記錄被刪除前一律保存老師寫的內容（含主任留言）。以前只有主任按「刪除」會保存；課程重建、請假重建、drift 修復孤兒這些大量刪除會直接把內容刪掉、無法找回（R100、in-app #219）。新增測試擋住之後再寫出繞過的大量刪除。

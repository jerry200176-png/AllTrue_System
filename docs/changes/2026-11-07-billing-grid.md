## 2026-11-07 — feat(billing): 帳單清單改成可排序的表格（#5099 / in-app #520）
<!-- release-notes: staff_update=staff-2026-11-07-billing-grid -->
- 帳單清單用 TanStack Table（MIT，`@tanstack/vue-table` 8.21.3）：點欄位標題排序、表頭固定、清單底下顯示目前這些列的合計（應收／已收／未繳）、「複製這些列／複製選取列」貼到 Excel（文字開頭的 `= + - @` 會加引號防公式）、記住每位使用者上次選的篩選。只能檢視，不能在表格裡改金額。

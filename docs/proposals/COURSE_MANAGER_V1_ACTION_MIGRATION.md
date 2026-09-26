# COURSE_MANAGER_V1 — Action Migration Matrix

Inventory from `CourseManagement.vue` (active-course row + More + Details + Calendar). Flag: `COURSE_MANAGER_V1` (default OFF).

| Existing action | Old entry | New location | Handler / authority |
| --- | --- | --- | --- |
| 編輯 | row 編輯 | 課程設定 | `editCourse` → existing course update |
| 行事曆 | Details / Calendar | 排課與堂次 | `CourseSessionCalendar` + Phase 1a create |
| 詳情 / 上課日期 | row 詳情 | 排課與堂次 | `toggleDatesAndMakeups` / session units |
| 排課 / 新增下一堂 | row | 排課與堂次 | `openManualSessionModal` / monthly |
| 補課 / 補登 | Edit / More | 排課與堂次 / 設定 | `openQuickAddSessionModal` |
| Pending makeup | Details | 排課與堂次 / 總覽 needs | existing makeup list |
| 帳單 | More | 帳務與合約 | `openInvoiceModal` |
| 繳費通知 | More | 帳務與合約 | `openPaymentSlip` |
| 加購 / 續報 | More | 帳務與合約 / 總覽 | `openCommercialPurchaseEntry` |
| 合約／堂次調整 | More | 帳務與合約 | `openContractAdjustmentModal` |
| 轉多科方案預檢 | More | 帳務與合約 | `openPackageConversionPreview` |
| 換師複製 | Edit | 課程設定 | `duplicateCourseForTeacher` |
| 暫停 / 恢復 | More | 課程狀態（總覽） | `requestCoursePause` |
| 結束課程 | More | 課程狀態 | `goToStudentsCommercial(..., 'close')` |
| 刪除 | More | Danger Zone | `confirmDeleteTarget` + existing guards |
| 堂數待對帳 | — | 總覽 / 帳務 | `openLedgerForCourse` |
| 查看帳務 | More | 帳務與合約 | `goToTuitionBilling` |

Phase 1b/2/3 absent: no occurrence cancel/time/teacher edit, no this-and-future, no recurrence rewrite.

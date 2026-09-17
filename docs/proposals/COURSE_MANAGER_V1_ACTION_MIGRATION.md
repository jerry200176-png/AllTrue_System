# COURSE_MANAGER_V1 — Action migration matrix

**Source tip:** `28f58b84780584a53aed1e8358305c8cd3e96ec0`  
**Scope:** Consolidate Edit + More + Details/Calendar into one Course Manager.  
**Non-scope:** Phase 1b/2/3 occurrence cancel/time/teacher edit; new APIs; billing/auth/schema changes.

## BEFORE — old active-course entry points

| Entry | Location |
|-------|----------|
| 編輯 | Row primary |
| 結束課程 | Row (conditional) |
| ＋新增下一堂 / 排課 / 排月結 | Row |
| 詳情 / 收起 | Row → expansion |
| 行事曆 | Inside 詳情 |
| 更多 ▾ | Row dropdown |
| Payment CTAs / 堂數待對帳 | Payment / subject cells (contextual) |
| ▶ 恢復課程 | Paused notice strip |

## ACTION MIGRATION MATRIX

| OLD_ENTRY | DOMAIN | NEW_LOCATION | EXISTING_HANDLER | EXISTING_API/SERVICE | MUTATION_RISK |
|-----------|--------|--------------|------------------|----------------------|---------------|
| 編輯 | settings | 課程設定 | `editCourse` / `submitEdit` | `PUT /api/v1/student-classes/{id}` + editability GET | write |
| 行事曆 | sessions | 排課與堂次 | `toggleCourseSessionCalendar` → always on in tab | `CourseSessionCalendar` + Phase 1a create | read / write create |
| 詳情 / 上課日期 chips | sessions | 排課與堂次 | `toggleDatesAndMakeups` / `openSessionEdit` | sessions composable + `SessionEditModal` | read / write status |
| 排課 / 排月結 / ＋新增下一堂 | sessions | 排課與堂次 | `openManualSessionModal` | `ManualSessionModal` → manual-sessions | write |
| 補課 / 補登 / 新增月結堂次 | sessions | 排課與堂次 | `openQuickAddSessionModal` / `openMonthlySessionModal` | add-session / manual-sessions | write |
| Pending makeup + 取消補課 | sessions | 排課與堂次 | `cancelMakeupSchedule` | `POST …/schedules/{id}/cancel-makeup` | write |
| Cancelled/moved visibility | sessions | 排課與堂次 | `toggleCancelledSessions` | existing units | read |
| 帳單（唯讀） | billing | 帳務與合約 | `openInvoiceModal` | GET invoices | read |
| 繳費通知 | billing | 帳務與合約 | `openPaymentSlip` | `PaymentSlipModal` | read |
| 加購 / 續報 / 轉正式 / 結算續約 | billing | 帳務與合約 | `openCommercialPurchaseEntry` | Students commercial / purchase modals | write / navigate |
| 合約／堂次調整 | billing | 帳務與合約 | `openContractAdjustmentModal` | amendment / correction / transfer | write |
| 轉多科方案預檢 | billing | 帳務與合約 | `openPackageConversionPreview` | package conversion preview/convert | read→write |
| 堂數待對帳 / 對帳 | billing | 帳務與合約 + overview CTA | `openLedgerForCourse` | `AccountingLedgerModal` | read |
| 前往帳務中心 / 登記繳費 | billing | 帳務與合約 + payment cell CTA | `goToTuitionBilling` | navigate tuition | navigate |
| 換師複製 | settings/lifecycle | 課程設定（其他） | `duplicateCourseForTeacher` | `UniversalClassScheduler` | write |
| 暫停 / 恢復 | lifecycle | 總覽 → 課程狀態 | `requestCoursePause` / `confirmCoursePause` | `POST …/pause` | write |
| 結束課程 | lifecycle | 總覽 → 課程狀態 | `goToStudentsCommercial(…,'close')` | Students commercial | write off-page |
| 刪除課程 | lifecycle | 總覽 → 危險操作 | `confirmDeleteTarget` / `executeDeleteCourse` | `DELETE …/student-classes/{id}` | write |
| Session chip status/reschedule/sub | sessions | 排課與堂次 → SessionEditModal | `openSessionEdit` | class-sessions APIs | write |
| Occurrence cancel / time / teacher / this-and-future | — | **NOT ADDED** | — | — | forbidden |

## Row after COURSE_MANAGER_V1

Primary: **管理課程** only.  
Preserved outside manager (high-frequency / notice, not competing IA): payment-status CTAs, 堂數待對帳 chip, paused **恢復** strip.

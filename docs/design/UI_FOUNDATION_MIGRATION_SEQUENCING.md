# UI Foundation — migration sequencing

Companion to [`ALLTRUE_UI_FOUNDATION.md`](ALLTRUE_UI_FOUNDATION.md) (look and rules) and [`UI_AUDIT_2026-07-26.md`](UI_AUDIT_2026-07-26.md). Decision: #4659 (shadcn-vue template, Apple-style look). This is the single order; the older At*-pilot wave tables are retired.

Every PR touching `frontend/` gets a preview of the whole app (demo logins 主任/老師/家長, fake data) from the `ui-preview` workflow's sticky PR comment; review there instead of screenshots.

## Order

| # | Step | Status |
|---|---|---|
| 1 | PR-0 template foundation (shadcn-vue on `--ds-*`) | done, #4951 |
| 2 | 主任首頁 (next) | #4949 |
| 3 | 課程管理 | #4918 |
| 4 | App shell (sidebar, top bar, mobile tabs) | planned |
| 5 | 老師首頁 | planned |
| 6 | 帳務中心 v3 | planned |
| 7 | Remaining pages, one PR each | planned |
| P | Parent track #4986 | step 1 #4987; steps 2-6 planned |

The Apple-style look is delivered per page, not by changing global tokens; global `--ds-*` values stay until the last pages migrate. Every migrated page uses `components/ui` plus the `ParentHome.vue` pattern (small label over big value, one dark primary pill). `At*` components are not restyled; they retire as pages move.

## Constraints that outlive the waves

- SmartCalendar: visual change only, no occurrence merge / G-007 paths.
- Billing / tuition: no payment, `Paid` or invoice logic changes.
- Attendance: no RFID / deduction path changes.
- Director home keeps its work-queue structure: priority data first, secondary analysis on demand, no duplicate counts, primary CTA visible at 390.

## Definition of done per page

1. Uses `components/ui` + foundation tokens (no new literals, no unused primitives).
2. Unit/a11y coverage for new controls.
3. Real Vue Playwright evidence at 390 / 768 / 1440 (mocked API), plus `toHaveScreenshot` baselines at 1280 and 390 (`npm run test:visual:update`, reviewer looks at the PNG diff).
4. Fixtures stay out of `public/` / `dist_build`.
5. No API / DB / permission / billing logic changes.
6. No unintended horizontal overflow at 390/412/768/1280/1440; primary CTA visible at the two mobile widths; for the director home, a production read-only smoke walk after deploy.
7. Design QA (in this order, vendored under `.claude/skills/`): `impeccable` critique/audit, then polish; the `make-interfaces-feel-better` checklist; the existing `web-design-guidelines` review; `ui-ux-pro-max` for data-heavy pages (tables, forms, charts). Brand: ink #0B0B0C + gold #F7D917/#F2C40F, amber #E9A51A, red only for danger; body >=16px, touch targets >=44px.

## Motion layer

Copy-paste source like shadcn, never npm dependencies. Sources: Inspira UI (`unovue/inspira-ui`, MIT), Vue Bits (`DavidHDev/vue-bits`, MIT + Commons Clause: use in the app is fine, reselling is not), Uiverse (`uiverse-io/galaxy`, MIT, CSS). Each copied component carries a header comment naming upstream, SHA and licence.

- Where: Login; parent home; director home (number count-up, gold shine border on the primary action, blur-fade entrance); page and dialog transitions; notifications list.
- `prefers-reduced-motion` turns all motion off.
- No WebGL or particle backgrounds on daily staff work screens (attendance, calendar, billing, course management). Motion never delays input.
- UI feedback is at most 300 ms.
- Brand colours only: ink and gold.
- Visual baselines are captured with motion disabled.


## Overlays

Census (#4989, `node scripts/design/overlay-census.mjs`; `--json` adds line, trigger and destructive flag per overlay). Gallery: the `ui-foundation-overlay-gallery` spec saves every overlay it can open at 1280 and 375 into the CI evidence artifact (`overlays/`); no baselines are committed.

| system | count |
|---|---|
| AtToast | 1 |
| AtDialog | 43 |
| prompt | 15 |
| raw-teleport | 10 |
| window.confirm | 41 |
| hand-rolled | 54 |
| ActionMenu | 7 |
| AtRowMenu | 3 |

| page / component | systems (count) | destructive |
|---|---|---|
| `App.vue` | AtToast(1) | 0 |
| `components/AccountingLedgerModal.vue` | AtDialog(3) prompt(2) | 1 |
| `components/ActionMenu.vue` | raw-teleport(1) | 0 |
| `components/BugReportLauncher.vue` | AtDialog(1) window.confirm(1) | 0 |
| `components/EngagementRankStrip.vue` | hand-rolled(1) | 0 |
| `components/EnrollmentConflictDecisionModal.vue` | hand-rolled(1) | 0 |
| `components/LatestPaymentInfoModal.vue` | hand-rolled(1) | 0 |
| `components/PaymentSlipModal.vue` | hand-rolled(1) | 0 |
| `components/PopInappAgentKeyPanel.vue` | AtDialog(2) | 1 |
| `components/ReceiptModal.vue` | hand-rolled(1) | 0 |
| `components/ReportDiscrepancyModal.vue` | raw-teleport(1) window.confirm(1) | 1 |
| `components/TeacherAdjustModal.vue` | hand-rolled(1) | 0 |
| `components/TeacherMonthlyAttendance.vue` | window.confirm(1) prompt(1) | 0 |
| `components/UniversalClassScheduler.vue` | hand-rolled(1) window.confirm(3) | 1 |
| `components/calendar/CalendarPrintDialog.vue` | raw-teleport(1) | 0 |
| `components/calendar/modals/CalendarExtraLessonModal.vue` | hand-rolled(1) window.confirm(1) | 0 |
| `components/calendar/modals/CalendarLeaveModal.vue` | hand-rolled(1) | 0 |
| `components/calendar/modals/CalendarRescheduleModal.vue` | hand-rolled(1) | 0 |
| `components/calendar/modals/CalendarSessionEditModal.vue` | hand-rolled(1) | 0 |
| `components/calendar/modals/CalendarSubstituteLegacyModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/BulkLeaveModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/ConfirmDialogHost.vue` | AtDialog(1) | 0 |
| `components/course-management/ContractAdjustmentChoiceModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/ContractAmendmentModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/ContractAmendmentRevertModal.vue` | hand-rolled(1) | 1 |
| `components/course-management/CourseManager.vue` | ActionMenu(1) | 0 |
| `components/course-management/CourseTransferModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/LeaveModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/MakeupSlotsModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/ManualSessionModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/MonthlyBatchRenewModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/MonthlyCorrectionPreviewModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/PackageConversionPreviewModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/PurchaseSessionsModal.vue` | AtDialog(1) hand-rolled(1) | 0 |
| `components/course-management/QuickAddSessionModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/RenewMonthlyModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/RescheduleModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/RestoreCancelledSessionDialog.vue` | AtDialog(1) | 0 |
| `components/course-management/SessionEditModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/SiblingTransferModal.vue` | hand-rolled(1) | 0 |
| `components/course-management/TransferSessionsModal.vue` | hand-rolled(1) | 0 |
| `components/design-system/AtDialog.vue` | raw-teleport(1) | 0 |
| `components/learning-records/LearningRecordExportModal.vue` | hand-rolled(1) | 0 |
| `components/students/IdentityLinkModal.vue` | hand-rolled(1) | 0 |
| `components/students/StudentLearningTimeline.vue` | hand-rolled(1) | 0 |
| `components/tuition/BillList.vue` | ActionMenu(2) | 0 |
| `components/tuition/BillQuickView.vue` | AtDialog(3) ActionMenu(1) | 1 |
| `components/tuition/ContractCard.vue` | ActionMenu(1) AtDialog(2) | 0 |
| `components/tuition/FeeLedgerPanel.vue` | AtDialog(1) | 0 |
| `components/tuition/LegacyPaidFlagActions.vue` | AtDialog(1) | 0 |
| `components/tuition/MonthlyDraftsPanel.vue` | AtDialog(1) | 0 |
| `components/tuition/RefundDialog.vue` | AtDialog(1) | 0 |
| `lib/scheduleDisplay.js` | window.confirm(1) | 0 |
| `pages/AssessmentPage.vue` | AtDialog(2) window.confirm(1) | 0 |
| `pages/AttendancePage.vue` | raw-teleport(4) window.confirm(2) | 2 |
| `pages/BindingConflictReviewPage.vue` | AtDialog(1) | 0 |
| `pages/BindingManagementPage.vue` | AtDialog(1) | 0 |
| `pages/BranchManagementPage.vue` | hand-rolled(2) | 1 |
| `pages/ChatPage.vue` | AtDialog(3) | 1 |
| `pages/ClassroomManagement.vue` | AtDialog(2) | 1 |
| `pages/CourseManagement.vue` | ActionMenu(1) hand-rolled(4) AtDialog(2) | 2 |
| `pages/DirectorAccountsPage.vue` | AtDialog(1) window.confirm(3) | 2 |
| `pages/DirectorDashboard.vue` | hand-rolled(1) window.confirm(1) prompt(5) | 1 |
| `pages/DuplicateSessionReviewPage.vue` | raw-teleport(1) | 0 |
| `pages/ExamGradesPage.vue` | ActionMenu(1) AtDialog(6) | 2 |
| `pages/LearningRecordsPage.vue` | hand-rolled(4) AtDialog(2) window.confirm(5) prompt(5) | 3 |
| `pages/NotificationsCenter.vue` | AtRowMenu(2) prompt(1) | 0 |
| `pages/ParttimePayrollPage.vue` | hand-rolled(4) window.confirm(3) | 3 |
| `pages/QuestionBankPage.vue` | AtDialog(1) window.confirm(1) | 1 |
| `pages/ScheduleDiscrepancyPage.vue` | raw-teleport(1) | 0 |
| `pages/SmartCalendar.vue` | window.confirm(5) | 2 |
| `pages/StudentsList.vue` | AtRowMenu(1) hand-rolled(4) window.confirm(7) | 5 |
| `pages/SubjectSettingsPage.vue` | AtDialog(2) | 1 |
| `pages/TeacherEligibilityPage.vue` | window.confirm(1) prompt(1) | 0 |
| `pages/TeachersList.vue` | hand-rolled(2) AtDialog(1) window.confirm(4) | 2 |
| `pages/TuitionCollectionPage.vue` | AtDialog(1) | 0 |

Target: one template per kind, built only on `components/ui` (Reka UI handles focus trap, Esc, aria, scroll lock). References: shadcn-vue, Material 3 (dialog vs side sheet vs full-screen on phone), Apple HIG (alerts, sheets), Atlassian modal sizes, GitLab Pajamas (button order, when not to use a modal).

| Kind | Template (`components/app-overlay/`) | Built on | Use for |
|---|---|---|---|
| Confirm | `ConfirmDialog` + `useConfirm()` | `alert-dialog` | yes/no on one action; replaces `window.confirm` and `prompt(` |
| Form | `FormDialog` (sm / md) | `dialog` | short create/edit; unsaved-changes guard |
| Detail | `DetailSheet` | `sheet` (right on desktop, full-screen on phone) | view/edit one record with tabs |
| Menu | `ActionMenu` (same props) | `dropdown-menu` | row and overflow actions |
| Result | `toast` helper | `sonner` | success results only; errors stay inline in the form |

Pattern rules:

1. At most one overlay deep; only a confirm may stack on top.
2. Labels are verbs (「儲存」, not 「確定」). Footer order: [取消] on the left, primary on the right; destructive primary is red.
3. Confirm = question title, one consequence line, [取消] [verb].
4. The X is top-right. Focus starts on the first field (confirm: on 取消).
5. Esc and outside click close, except a dirty form (guard) and a confirm (no outside click).
6. Motion 200 ms or less, none under `prefers-reduced-motion`.
7. Do not use a modal for something a page section, inline expand or inline error can do.
8. New code may not add `class="modal`, `window.confirm`, `prompt(` or raw `<Teleport` in `pages/` or `components/` (ratchet, can only go down).

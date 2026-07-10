# REF — API Routes

> **GENERATED FILE — do not hand-edit.** Regenerate: `bash scripts/generate-ref-api-routes.sh`
> Source: `php artisan route:list --json` · 318 api/* routes · generated 2026-07-10
>
> Auth legend: `role`=role middleware group, `campus`=require_campus, `public`=no auth middleware.
> ⚠️ `public` means no auth *middleware* — some public routes carry inline guards
> (X-Deploy-Secret, LINE channel signature, ParentSession bearer). Verify the route
> closure/controller before treating a `public` row as exposed (R60 checks belong in CI).

## /api/health (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/health` | `Closure` | public |

## /api/internal/opcache-reset (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/internal/opcache-reset` | `Closure` | public |

## /api/v1/accounting (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/accounting/ledger` | `AccountingController@ledger` | public |
| GET | `api/v1/accounting/payments` | `AccountingController@payments` | public |
| GET | `api/v1/accounting/payments/export` | `AccountingController@paymentsExport` | public |
| GET | `api/v1/accounting/settled-courses` | `AccountingController@settledCourses` | public |

## /api/v1/admin (9)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/admin/campuses` | `AdminCampusController@index` | public |
| POST | `api/v1/admin/campuses` | `AdminCampusController@store` | public |
| PUT | `api/v1/admin/campuses/{id}` | `AdminCampusController@update` | public |
| DELETE | `api/v1/admin/campuses/{id}` | `AdminCampusController@destroy` | public |
| POST | `api/v1/admin/reset-data` | `ResetDataController` | public |
| GET | `api/v1/admin/routing-rules` | `AdminRoutingRuleController@index` | public |
| GET | `api/v1/admin/routing-rules/check` | `AdminRoutingRuleController@check` | public |
| POST | `api/v1/admin/routing-rules/versions` | `AdminRoutingRuleController@store` | public |
| POST | `api/v1/admin/routing-rules/versions/{version}/publish` | `AdminRoutingRuleController@publish` | public |

## /api/v1/adoption (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/adoption/activity-log` | `AdoptionInsightsController@activityLog` | public |
| GET | `api/v1/adoption/cross-branch-metrics` | `AdoptionInsightsController@crossBranchMetrics` | public |
| POST | `api/v1/adoption/events` | `AdoptionInsightsController@recordEvent` | public |
| GET | `api/v1/adoption/task-tracker` | `AdoptionInsightsController@taskTracker` | public |
| GET | `api/v1/adoption/weekly-metrics` | `AdoptionInsightsController@weeklyMetrics` | public |

## /api/v1/alerts (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/alerts/tuition` | `AlertController@tuition` | public |
| GET | `api/v1/alerts/tuition-slip/{studentClassId}` | `AlertController@tuitionSlipData` | public |

## /api/v1/api-clients (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/api-clients` | `ApiClientController@index` | public |
| POST | `api/v1/api-clients` | `ApiClientController@store` | public |
| POST | `api/v1/api-clients/{apiClient}/revoke` | `ApiClientController@revoke` | public |

## /api/v1/attendance (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/attendance` | `AttendanceController@index` | public |
| POST | `api/v1/attendance` | `AttendanceController@store` | public |
| POST | `api/v1/attendance/batch-mark` | `AttendanceController@batchMark` | public |
| GET | `api/v1/attendance/ended-sessions` | `AttendanceController@endedSessions` | public |
| POST | `api/v1/attendance/swipe` | `AttendanceController@swipe` | public |
| PATCH | `api/v1/attendance/{id}` | `AttendanceController@update` | public |
| DELETE | `api/v1/attendance/{id}` | `AttendanceController@destroy` | public |
| POST | `api/v1/attendance/{id}/convert-to-attended` | `AttendanceController@convertToAttended` | public |

## /api/v1/auth (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/auth/forgot-password` | `PasswordResetRequestController@store` | public |
| POST | `api/v1/auth/login` | `AuthController@login` | public |
| POST | `api/v1/auth/register` | `AuthController@register` | public |

## /api/v1/backfill (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/backfill/register-subject-units` | `BackfillController@registerSubjectUnits` | public |

## /api/v1/bank-reconciliation (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/bank-reconciliation` | `BankReconciliationController@index` | public |
| POST | `api/v1/bank-reconciliation/import` | `BankReconciliationController@importCsv` | public |
| POST | `api/v1/bank-reconciliation/{id}/reconcile` | `BankReconciliationController@reconcile` | public |
| GET | `api/v1/bank-reconciliation/{id}/suggest` | `BankReconciliationController@suggestMatches` | public |

## /api/v1/branches (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/branches` | `CampusController@listPublic` | public |

## /api/v1/bugs (9)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/bugs` | `BugReportController@store` | public |
| GET | `api/v1/bugs` | `BugReportController@index` | public |
| POST | `api/v1/bugs/mark-inbox-seen` | `BugReportController@markInboxSeen` | public |
| GET | `api/v1/bugs/unread-badge` | `BugReportController@unreadBadge` | public |
| GET | `api/v1/bugs/{id}` | `BugReportController@show` | public |
| POST | `api/v1/bugs/{id}/comments` | `BugReportController@addComment` | public |
| PATCH | `api/v1/bugs/{id}/comments/{commentId}/visibility` | `BugReportController@updateCommentVisibility` | public |
| POST | `api/v1/bugs/{id}/reporter-verify` | `BugReportController@reporterVerify` | public |
| POST | `api/v1/bugs/{id}/status` | `BugReportController@updateStatus` | public |

## /api/v1/campuses (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/campuses` | `CampusController@index` | public |

## /api/v1/chat (18)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| DELETE | `api/v1/chat/messages/{messageId}` | `ChatController@deleteMessage` | public |
| GET | `api/v1/chat/threads` | `ChatController@threads` | public |
| POST | `api/v1/chat/threads/dm` | `ChatController@createDm` | public |
| POST | `api/v1/chat/threads/group` | `ChatController@createGroup` | public |
| DELETE | `api/v1/chat/threads/{threadId}` | `ChatController@deleteThread` | public |
| PATCH | `api/v1/chat/threads/{threadId}` | `ChatController@updateThread` | public |
| POST | `api/v1/chat/threads/{threadId}/attachments` | `ChatController@uploadAttachment` | public |
| POST | `api/v1/chat/threads/{threadId}/leave` | `ChatController@leaveThread` | public |
| GET | `api/v1/chat/threads/{threadId}/members` | `ChatController@getMembers` | public |
| POST | `api/v1/chat/threads/{threadId}/members` | `ChatController@addMembers` | public |
| DELETE | `api/v1/chat/threads/{threadId}/members/{userId}` | `ChatController@removeMember` | public |
| GET | `api/v1/chat/threads/{threadId}/messages` | `ChatController@messages` | public |
| POST | `api/v1/chat/threads/{threadId}/messages` | `ChatController@sendMessage` | public |
| POST | `api/v1/chat/threads/{threadId}/pin` | `ChatController@pinThread` | public |
| POST | `api/v1/chat/threads/{threadId}/read` | `ChatController@markRead` | public |
| POST | `api/v1/chat/threads/{threadId}/transfer-owner` | `ChatController@transferOwner` | public |
| POST | `api/v1/chat/threads/{threadId}/typing` | `ChatController@typing` | public |
| GET | `api/v1/chat/unread-count` | `ChatController@unreadCount` | public |

## /api/v1/class-sessions (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/class-sessions` | `ClassSessionController@index` | public |
| POST | `api/v1/class-sessions/batch` | `ClassSessionController@batchStore` | public |
| POST | `api/v1/class-sessions/ensure-projected` | `ClassSessionController@ensureProjected` | public |
| GET | `api/v1/class-sessions/projection` | `ClassSessionController@projection` | public |
| PATCH | `api/v1/class-sessions/{id}` | `ClassSessionController@update` | public |
| POST | `api/v1/class-sessions/{id}/substitute` | `ClassSessionController@substitute` | public |
| POST | `api/v1/class-sessions/{id}/substitute/undo` | `SubstituteController@undo` | public |

## /api/v1/course-packages (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/course-packages` | `CoursePackageController@index` | public |
| POST | `api/v1/course-packages/create-multi-subject` | `CoursePackageController@createMultiSubject` | public |
| GET | `api/v1/course-packages/{id}` | `CoursePackageController@show` | public |
| PUT | `api/v1/course-packages/{id}` | `CoursePackageController@update` | public |
| POST | `api/v1/course-packages/{id}/bind-courses` | `CoursePackageController@bindCourses` | public |
| POST | `api/v1/course-packages/{id}/rebuild-ledger` | `CoursePackageController@rebuildLedger` | public |
| POST | `api/v1/course-packages/{id}/recompute` | `CoursePackageController@recompute` | public |

## /api/v1/directors (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/directors` | `DirectorAccountController@index` | public |
| GET | `api/v1/directors/pending` | `DirectorAccountController@pending` | public |
| POST | `api/v1/directors/register` | `DirectorAccountController@register` | public |
| DELETE | `api/v1/directors/{id}` | `DirectorAccountController@destroy` | public |
| POST | `api/v1/directors/{id}/approve` | `DirectorAccountController@approve` | public |
| PUT | `api/v1/directors/{id}/campuses` | `DirectorAccountController@updateCampuses` | public |
| POST | `api/v1/directors/{id}/reject` | `DirectorAccountController@reject` | public |
| POST | `api/v1/directors/{id}/reset-password` | `DirectorAccountController@resetPassword` | public |

## /api/v1/dunning (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/dunning/history` | `DunningController@history` | public |
| GET | `api/v1/dunning/rules` | `DunningController@rules` | public |
| POST | `api/v1/dunning/trigger` | `DunningController@trigger` | public |

## /api/v1/engagement (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/engagement/award-xp` | `EngagementController@awardXp` | public |
| GET | `api/v1/engagement/badges` | `EngagementController@badges` | public |
| POST | `api/v1/engagement/badges/{key}/toggle-visibility` | `EngagementController@toggleBadgeVisibility` | public |
| GET | `api/v1/engagement/event-types` | `EngagementController@eventTypes` | public |
| GET | `api/v1/engagement/my-progress` | `EngagementController@myProgress` | public |
| GET | `api/v1/engagement/rank-thresholds` | `EngagementController@rankThresholds` | public |
| POST | `api/v1/engagement/ranks-for` | `EngagementController@ranksFor` | public |
| GET | `api/v1/engagement/xp-history` | `EngagementController@xpHistory` | public |

## /api/v1/enrollments (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/enrollments` | `EnrollmentController@store` | public |

## /api/v1/exception-workflows (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/exception-workflows` | `ExceptionWorkflowController@index` | public |
| GET | `api/v1/exception-workflows/{id}` | `ExceptionWorkflowController@show` | public |
| POST | `api/v1/exception-workflows/{id}/confirm-candidate` | `ExceptionWorkflowController@confirmCandidate` | public |
| POST | `api/v1/exception-workflows/{id}/generate-candidates` | `ExceptionWorkflowController@generateCandidates` | public |
| POST | `api/v1/exception-workflows/{id}/waive` | `ExceptionWorkflowController@waive` | public |

## /api/v1/finance (24)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/finance/ar-aging` | `FinanceController@arAging` | public |
| GET | `api/v1/finance/branch-monthly-tuition` | `FinanceController@branchMonthlyTuition` | public |
| GET | `api/v1/finance/branch-monthly-tuition/export` | `FinanceController@branchMonthlyTuitionExport` | public |
| GET | `api/v1/finance/consolidated-summary` | `FinanceController@consolidatedSummary` | public |
| GET | `api/v1/finance/duplicate-courses` | `FinanceController@duplicateCourses` | public |
| GET | `api/v1/finance/gl-export` | `FinanceController@glExport` | public |
| GET | `api/v1/finance/outstanding` | `FinanceController@outstanding` | public |
| GET | `api/v1/finance/parttime-payroll` | `FinanceController@parttimePayroll` | public |
| GET | `api/v1/finance/parttime-payroll/export` | `FinanceController@parttimePayrollExport` | public |
| POST | `api/v1/finance/parttime-payroll/lock` | `FinanceController@parttimePayrollLock` | public |
| POST | `api/v1/finance/parttime-payroll/reopen` | `FinanceController@parttimePayrollReopen` | public |
| GET | `api/v1/finance/parttime-payroll/rules` | `FinanceController@parttimePayrollRules` | public |
| PUT | `api/v1/finance/parttime-payroll/rules` | `FinanceController@parttimePayrollRulesUpdate` | public |
| GET | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRules` | public |
| PUT | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRulesUpdate` | public |
| DELETE | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRulesDelete` | public |
| GET | `api/v1/finance/parttime-payroll/{teacherId}/sessions` | `FinanceController@parttimePayrollSessions` | public |
| GET | `api/v1/finance/periods` | `AccountingPeriodController@index` | public |
| POST | `api/v1/finance/periods/close` | `AccountingPeriodController@close` | public |
| POST | `api/v1/finance/periods/reopen` | `AccountingPeriodController@reopen` | public |
| GET | `api/v1/finance/revenue` | `FinanceController@revenue` | public |
| GET | `api/v1/finance/subject-units` | `FinanceController@subjectUnits` | public |
| GET | `api/v1/finance/summary` | `FinanceController@summary` | public |
| GET | `api/v1/finance/teacher-payroll` | `FinanceController@teacherPayroll` | public |

## /api/v1/health (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/health` | `Closure` | public |
| GET | `api/v1/health/detailed` | `Closure` | public |

## /api/v1/invoices (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/invoices` | `BillingController@index` | public |
| POST | `api/v1/invoices` | `BillingController@store` | public |
| GET | `api/v1/invoices/export` | `ExportController@invoices` | public |
| POST | `api/v1/invoices/{invoice}/exception-void` | `BillingController@exceptionVoidInvoice` | public |
| POST | `api/v1/invoices/{invoice}/payments` | `BillingController@recordPayment` | public |
| GET | `api/v1/invoices/{invoice}/slip-data` | `BillingController@slipData` | public |
| POST | `api/v1/invoices/{invoice}/void` | `BillingController@voidInvoice` | public |

## /api/v1/learning-record-feedbacks (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/learning-record-feedbacks` | `LearningRecordFeedbackController@index` | public |
| GET | `api/v1/learning-record-feedbacks/analytics` | `LearningRecordFeedbackController@analytics` | public |
| POST | `api/v1/learning-record-feedbacks/{feedback}/read` | `LearningRecordFeedbackController@markRead` | public |
| GET | `api/v1/learning-record-feedbacks/{feedback}/replies` | `LearningRecordFeedbackController@replies` | public |
| POST | `api/v1/learning-record-feedbacks/{feedback}/reply` | `LearningRecordFeedbackController@staffReply` | public |

## /api/v1/learning-record-teacher-comments (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/learning-record-teacher-comments/{comment}/read` | `LearningRecordTeacherCommentController@markRead` | public |

## /api/v1/learning-records (19)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/learning-records` | `LearningRecordController@index` | public |
| POST | `api/v1/learning-records` | `LearningRecordController@store` | public |
| POST | `api/v1/learning-records/backdoor-approve` | `LearningRecordController@backdoorApprove` | public |
| POST | `api/v1/learning-records/batch-approve` | `LearningRecordController@batchApprove` | public |
| POST | `api/v1/learning-records/batch-reject` | `LearningRecordController@batchReject` | public |
| POST | `api/v1/learning-records/batch-request-changes` | `LearningRecordController@batchRequestChanges` | public |
| POST | `api/v1/learning-records/bulk-backdoor-approve` | `LearningRecordController@bulkBackdoorApprove` | public |
| POST | `api/v1/learning-records/ensure-past` | `LearningRecordController@ensurePastRecords` | public |
| GET | `api/v1/learning-records/latest-approved-summary` | `LearningRecordController@latestApprovedSummary` | public |
| POST | `api/v1/learning-records/reschedule-session` | `LearningRecordController@rescheduleSession` | public |
| POST | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@update` | public |
| PUT | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@update` | public |
| DELETE | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@destroy` | public |
| POST | `api/v1/learning-records/{learningRecord}/approve` | `LearningRecordController@approve` | public |
| POST | `api/v1/learning-records/{learningRecord}/reject` | `LearningRecordController@reject` | public |
| POST | `api/v1/learning-records/{learningRecord}/request-changes` | `LearningRecordController@requestChanges` | public |
| POST | `api/v1/learning-records/{learningRecord}/rollback-approval` | `LearningRecordController@rollbackApproval` | public |
| PATCH | `api/v1/learning-records/{learningRecord}/teacher` | `LearningRecordController@updateTeacher` | public |
| PUT | `api/v1/learning-records/{learningRecord}/teacher-comment` | `LearningRecordTeacherCommentController@upsert` | public |

## /api/v1/line (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/line/settings` | `LineWebhookController@saveSettings` | public |
| GET | `api/v1/line/status` | `LineWebhookController@status` | public |
| POST | `api/v1/line/webhook` | `LineWebhookController@handleDomainBased` | public |
| POST | `api/v1/line/webhook/{campusId}` | `LineWebhookController@handle` | public |

## /api/v1/me (15)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/me` | `AuthController@me` | public |
| PUT | `api/v1/me` | `AuthController@updateMe` | public |
| POST | `api/v1/me/avatar` | `AuthController@uploadAvatar` | public |
| GET | `api/v1/me/learning-pending-summary` | `LearningRecordController@teacherPendingBadgeSummary` | public |
| GET | `api/v1/me/learning-progress-summary` | `LearningRecordController@teacherLearningProgressSummary` | public |
| GET | `api/v1/me/notification-preferences` | `AuthController@notificationPreferences` | public |
| PUT | `api/v1/me/notification-preferences` | `AuthController@updateNotificationPreferences` | public |
| POST | `api/v1/me/pin/lock` | `PinVerificationController@lock` | public |
| POST | `api/v1/me/pin/reset` | `PinVerificationController@reset` | public |
| POST | `api/v1/me/pin/set` | `PinVerificationController@set` | public |
| GET | `api/v1/me/pin/status` | `PinVerificationController@status` | public |
| POST | `api/v1/me/pin/verify` | `PinVerificationController@verify` | public |
| GET | `api/v1/me/security` | `AuthController@security` | public |
| POST | `api/v1/me/security/logout-others` | `AuthController@logoutOtherSessions` | public |
| GET | `api/v1/me/unread-feedback-count` | `LearningRecordFeedbackController@unreadCount` | public |

## /api/v1/notifications (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/notifications` | `NotificationController@index` | public |
| POST | `api/v1/notifications/read-all` | `NotificationController@markAllRead` | public |
| POST | `api/v1/notifications/sync` | `NotificationController@sync` | public |
| GET | `api/v1/notifications/unread-count` | `NotificationController@unreadCount` | public |
| POST | `api/v1/notifications/{notificationId}/read` | `NotificationController@markRead` | public |
| POST | `api/v1/notifications/{notificationId}/tuition-paid` | `NotificationController@markTuitionPaid` | public |

## /api/v1/parent-feedback (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/parent-feedback` | `ParentFeedbackController@index` | public |
| GET | `api/v1/parent-feedback/for-teacher` | `ParentFeedbackController@forTeacher` | public |
| GET | `api/v1/parent-feedback/unread-count` | `ParentFeedbackController@unreadCount` | public |
| POST | `api/v1/parent-feedback/{id}/mark-read` | `ParentFeedbackController@markRead` | public |
| POST | `api/v1/parent-feedback/{id}/read` | `ParentFeedbackController@markReadByTeacher` | public |
| GET | `api/v1/parent-feedback/{id}/replies` | `ParentFeedbackController@replies` | public |
| POST | `api/v1/parent-feedback/{id}/reply` | `ParentFeedbackController@reply` | public |

## /api/v1/parent (16)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/parent/billing-history` | `ParentPortalController@billingHistory` | public |
| GET | `api/v1/parent/dashboard` | `ParentPortalController@dashboard` | public |
| POST | `api/v1/parent/events` | `ParentPortalController@recordParentEvent` | public |
| POST | `api/v1/parent/feedback` | `ParentFeedbackController@store` | public |
| GET | `api/v1/parent/learning-records/{learningRecord}/feedback` | `LearningRecordFeedbackController@parentShow` | public |
| PUT | `api/v1/parent/learning-records/{learningRecord}/feedback` | `LearningRecordFeedbackController@parentUpsert` | public |
| POST | `api/v1/parent/learning-records/{learningRecord}/feedback/reply` | `LearningRecordFeedbackController@parentReply` | public |
| POST | `api/v1/parent/login` | `ParentPortalController@login` | public |
| POST | `api/v1/parent/login-line` | `ParentPortalController@loginWithLine` | public |
| GET | `api/v1/parent/notification-preferences` | `ParentPortalController@getNotificationPreferences` | public |
| PUT | `api/v1/parent/notification-preferences` | `ParentPortalController@setNotificationPreferences` | public |
| GET | `api/v1/parent/payment-message/{studentId}` | `ParentPortalController@paymentMessage` | public |
| GET | `api/v1/parent/resolve-liff` | `ParentPortalController@resolveLiff` | public |
| POST | `api/v1/parent/sessions/{sessionId}/leave` | `ParentPortalController@requestLeave` | public |
| POST | `api/v1/parent/switch-student` | `ParentPortalController@switchStudent` | public |
| GET | `api/v1/parent/system-trust-summary` | `SystemTrustController@parentSummary` | public |

## /api/v1/part-time-rate-cards (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/part-time-rate-cards` | `PartTimeRateCardController@index` | public |
| POST | `api/v1/part-time-rate-cards` | `PartTimeRateCardController@store` | public |
| PUT | `api/v1/part-time-rate-cards/{id}` | `PartTimeRateCardController@update` | public |
| DELETE | `api/v1/part-time-rate-cards/{id}` | `PartTimeRateCardController@destroy` | public |

## /api/v1/payment-reports (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/payment-reports` | `PaymentReportController@index` | public |
| POST | `api/v1/payment-reports/director-record` | `PaymentReportController@directorRecord` | public |
| PUT | `api/v1/payment-reports/{id}/confirm` | `PaymentReportController@confirm` | public |
| GET | `api/v1/payment-reports/{id}/receipt` | `PaymentReportController@receipt` | public |
| PUT | `api/v1/payment-reports/{id}/reject` | `PaymentReportController@reject` | public |
| PUT | `api/v1/payment-reports/{id}/void` | `PaymentReportController@void` | public |

## /api/v1/pending-swipes (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/pending-swipes` | `PendingSwipeController@index` | public |
| DELETE | `api/v1/pending-swipes/{pendingSwipe}` | `PendingSwipeController@destroy` | public |
| POST | `api/v1/pending-swipes/{pendingSwipe}/assign-student` | `PendingSwipeController@assignStudent` | public |
| POST | `api/v1/pending-swipes/{pendingSwipe}/match` | `PendingSwipeController@match` | public |

## /api/v1/profiles (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/profiles` | `ProfileController@index` | public |
| POST | `api/v1/profiles` | `ProfileController@store` | public |
| POST | `api/v1/profiles/bulk-teachers` | `ProfileController@bulkTeachers` | public |
| PUT | `api/v1/profiles/{id}` | `ProfileController@update` | public |
| DELETE | `api/v1/profiles/{id}` | `ProfileController@destroy` | public |
| POST | `api/v1/profiles/{id}/reset-password` | `ProfileController@resetPassword` | public |

## /api/v1/recent-unknown-rfids (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/recent-unknown-rfids` | `PendingSwipeController@recentUnknownRfids` | public |

## /api/v1/reports (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/reports/teacher-learning-fill-rates` | `ClassSessionController@directorTeacherLearningFillRates` | public |

## /api/v1/rooms (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/rooms` | `RoomController@index` | public |
| POST | `api/v1/rooms` | `RoomController@store` | public |
| PUT | `api/v1/rooms/{room}` | `RoomController@update` | public |
| DELETE | `api/v1/rooms/{room}` | `RoomController@destroy` | public |

## /api/v1/schedule-audit (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/schedule-audit` | `ScheduleAuditController@index` | public |

## /api/v1/schedule-discrepancies (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/schedule-discrepancies` | `ScheduleDiscrepancyController@store` | public |
| GET | `api/v1/schedule-discrepancies` | `ScheduleDiscrepancyController@index` | public |
| GET | `api/v1/schedule-discrepancies/active-for-session` | `ScheduleDiscrepancyController@activeForSession` | public |
| GET | `api/v1/schedule-discrepancies/my` | `ScheduleDiscrepancyController@mine` | public |
| GET | `api/v1/schedule-discrepancies/summary` | `ScheduleDiscrepancyController@summary` | public |
| PUT | `api/v1/schedule-discrepancies/{id}` | `ScheduleDiscrepancyController@updateStatus` | public |
| POST | `api/v1/schedule-discrepancies/{id}/withdraw` | `ScheduleDiscrepancyController@withdraw` | public |

## /api/v1/schedule-import (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/schedule-import/preview` | `ScheduleImportController@preview` | public |

## /api/v1/schedules (10)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/schedules` | `ScheduleController@index` | public |
| POST | `api/v1/schedules` | `ScheduleController@store` | public |
| POST | `api/v1/schedules/bulk-leave` | `ScheduleController@bulkHolidayLeave` | public |
| POST | `api/v1/schedules/leave-by-session` | `ScheduleController@leaveBySession` | public |
| POST | `api/v1/schedules/retro-leave` | `ScheduleController@retroLeave` | public |
| POST | `api/v1/schedules/undo-leave-by-session` | `ScheduleController@undoLeaveBySession` | public |
| PUT | `api/v1/schedules/{schedule}` | `ScheduleController@update` | public |
| DELETE | `api/v1/schedules/{schedule}` | `ScheduleController@destroy` | public |
| POST | `api/v1/schedules/{schedule}/cancel-makeup` | `ScheduleController@cancelMakeup` | public |
| POST | `api/v1/schedules/{schedule}/undo-leave` | `ScheduleController@undoLeave` | public |

## /api/v1/student-classes (19)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/student-classes` | `StudentClassController@index` | public |
| POST | `api/v1/student-classes` | `StudentClassController@store` | public |
| GET | `api/v1/student-classes/export` | `ExportController@studentClasses` | public |
| POST | `api/v1/student-classes/import` | `ImportController@studentClasses` | public |
| GET | `api/v1/student-classes/session-dates` | `StudentClassController@sessionDates` | public |
| POST | `api/v1/student-classes/session-dates` | `StudentClassController@sessionDates` | public |
| POST | `api/v1/student-classes/sync` | `StudentClassController@sync` | public |
| GET | `api/v1/student-classes/{studentClass}` | `StudentClassController@show` | public |
| PUT | `api/v1/student-classes/{studentClass}` | `StudentClassController@update` | public |
| DELETE | `api/v1/student-classes/{studentClass}` | `StudentClassController@destroy` | public |
| POST | `api/v1/student-classes/{studentClass}/add-session` | `StudentClassController@addSession` | public |
| POST | `api/v1/student-classes/{studentClass}/add-session/check` | `StudentClassController@checkAddSession` | public |
| POST | `api/v1/student-classes/{studentClass}/confirm-payment` | `StudentClassController@confirmPayment` | public |
| GET | `api/v1/student-classes/{studentClass}/invoices` | `StudentClassController@invoices` | public |
| POST | `api/v1/student-classes/{studentClass}/pause` | `StudentClassController@togglePause` | public |
| POST | `api/v1/student-classes/{studentClass}/purchase-batch` | `StudentClassController@purchaseBatch` | public |
| POST | `api/v1/student-classes/{studentClass}/renew-monthly` | `StudentClassController@renewMonthly` | public |
| POST | `api/v1/student-classes/{studentClass}/renewal-confirm` | `StudentClassController@renewalConfirm` | public |
| POST | `api/v1/student-classes/{studentClass}/renewal-preview` | `StudentClassController@renewalPreview` | public |

## /api/v1/students (12)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/students` | `StudentController@index` | public |
| POST | `api/v1/students` | `StudentController@store` | public |
| POST | `api/v1/students/bulk-delete` | `StudentController@bulkDestroy` | public |
| GET | `api/v1/students/export` | `ExportController@students` | public |
| POST | `api/v1/students/import` | `ImportController@students` | public |
| GET | `api/v1/students/{student}` | `StudentController@show` | public |
| PUT | `api/v1/students/{student}` | `StudentController@update` | public |
| DELETE | `api/v1/students/{student}` | `StudentController@destroy` | public |
| GET | `api/v1/students/{student}/active-courses` | `StudentController@activeCourses` | public |
| POST | `api/v1/students/{student}/bind-card` | `StudentController@bindCard` | public |
| GET | `api/v1/students/{student}/line-bindings` | `StudentController@lineBindings` | public |
| DELETE | `api/v1/students/{student}/line-bindings/{binding}` | `StudentController@removeLineBinding` | public |

## /api/v1/subjects (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/subjects` | `SubjectController@store` | public |
| GET | `api/v1/subjects` | `SubjectController@index` | public |
| PUT | `api/v1/subjects/{id}` | `SubjectController@update` | public |
| DELETE | `api/v1/subjects/{id}` | `SubjectController@destroy` | public |

## /api/v1/subjects-public (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/subjects-public` | `SubjectController@indexPublic` | public |

## /api/v1/substitutes (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/substitutes/recent` | `SubstituteController@recent` | public |

## /api/v1/swipe-rfid (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/swipe-rfid` | `SwipeRfidController@swipe` | public |

## /api/v1/system (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/system/settings/substitute-undo` | `SubstituteController@getUndoSetting` | public |
| PUT | `api/v1/system/settings/substitute-undo` | `SubstituteController@setUndoSetting` | public |
| GET | `api/v1/system/trust-summary` | `SystemTrustController@summary` | public |

## /api/v1/teacher-attendance (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teacher-attendance` | `TeacherAttendanceController@index` | public |
| GET | `api/v1/teacher-attendance/export` | `TeacherAttendanceController@export` | public |
| GET | `api/v1/teacher-attendance/export-monthly` | `TeacherAttendanceController@exportMonthly` | public |
| GET | `api/v1/teacher-attendance/today` | `TeacherAttendanceController@today` | public |
| GET | `api/v1/teacher-attendance/unclosed` | `TeacherAttendanceController@unclosed` | public |
| POST | `api/v1/teacher-attendance/{id}/adjust` | `TeacherAttendanceController@adjust` | public |

## /api/v1/teacher-leaves (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/teacher-leaves/batch-substitute` | `TeacherLeaveController@batchSubstitute` | public |
| POST | `api/v1/teacher-leaves/preview` | `TeacherLeaveController@preview` | public |

## /api/v1/teacher_branches (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teacher_branches` | `TeacherBranchController@index` | public |
| POST | `api/v1/teacher_branches` | `TeacherBranchController@store` | public |
| DELETE | `api/v1/teacher_branches` | `TeacherBranchController@destroy` | public |

## /api/v1/teachers (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teachers` | `Closure` | public |
| GET | `api/v1/teachers/{id}/availability` | `SubstituteController@availability` | public |

## /api/v1/teaching-logs (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teaching-logs/missing` | `TeachingLogController@missing` | public |

## /api/v1/telegram (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/telegram/webhook/{code}` | `TelegramWebhookController@handle` | public |

## /api/v1/temp-rfid (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/temp-rfid` | `TempRfidController@show` | public |
| POST | `api/v1/temp-rfid/consume` | `TempRfidController@consume` | public |


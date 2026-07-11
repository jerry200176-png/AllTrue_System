# REF — API Routes

> **GENERATED FILE — do not hand-edit.** Regenerate: `bash scripts/generate-ref-api-routes.sh`
> Source: `php artisan route:list --json` · 318 api/* routes · generated 2026-07-11
>
> Auth legend: `role`=role middleware group, `campus`=require_campus, `pin`=require_pin,
> `auth`=non-role authentication (for example API key), `public`=no enforcing auth middleware.
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
| GET | `api/v1/accounting/ledger` | `AccountingController@ledger` | role+campus |
| GET | `api/v1/accounting/payments` | `AccountingController@payments` | role+campus+pin |
| GET | `api/v1/accounting/payments/export` | `AccountingController@paymentsExport` | role+campus+pin |
| GET | `api/v1/accounting/settled-courses` | `AccountingController@settledCourses` | role+campus+pin |

## /api/v1/admin (9)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/admin/campuses` | `AdminCampusController@index` | role |
| POST | `api/v1/admin/campuses` | `AdminCampusController@store` | role |
| PUT | `api/v1/admin/campuses/{id}` | `AdminCampusController@update` | role |
| DELETE | `api/v1/admin/campuses/{id}` | `AdminCampusController@destroy` | role |
| POST | `api/v1/admin/reset-data` | `ResetDataController` | role |
| GET | `api/v1/admin/routing-rules` | `AdminRoutingRuleController@index` | role |
| GET | `api/v1/admin/routing-rules/check` | `AdminRoutingRuleController@check` | role |
| POST | `api/v1/admin/routing-rules/versions` | `AdminRoutingRuleController@store` | role |
| POST | `api/v1/admin/routing-rules/versions/{version}/publish` | `AdminRoutingRuleController@publish` | role |

## /api/v1/adoption (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/adoption/activity-log` | `AdoptionInsightsController@activityLog` | role+campus |
| GET | `api/v1/adoption/cross-branch-metrics` | `AdoptionInsightsController@crossBranchMetrics` | role |
| POST | `api/v1/adoption/events` | `AdoptionInsightsController@recordEvent` | role+campus |
| GET | `api/v1/adoption/task-tracker` | `AdoptionInsightsController@taskTracker` | role+campus |
| GET | `api/v1/adoption/weekly-metrics` | `AdoptionInsightsController@weeklyMetrics` | role+campus |

## /api/v1/alerts (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/alerts/tuition` | `AlertController@tuition` | role+campus |
| GET | `api/v1/alerts/tuition-slip/{studentClassId}` | `AlertController@tuitionSlipData` | role+campus |

## /api/v1/api-clients (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/api-clients` | `ApiClientController@index` | role+campus |
| POST | `api/v1/api-clients` | `ApiClientController@store` | role+campus |
| POST | `api/v1/api-clients/{apiClient}/revoke` | `ApiClientController@revoke` | role+campus |

## /api/v1/attendance (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/attendance` | `AttendanceController@index` | role+campus |
| POST | `api/v1/attendance` | `AttendanceController@store` | role+campus |
| POST | `api/v1/attendance/batch-mark` | `AttendanceController@batchMark` | role+campus |
| GET | `api/v1/attendance/ended-sessions` | `AttendanceController@endedSessions` | role+campus |
| POST | `api/v1/attendance/swipe` | `AttendanceController@swipe` | auth |
| PATCH | `api/v1/attendance/{id}` | `AttendanceController@update` | role+campus |
| DELETE | `api/v1/attendance/{id}` | `AttendanceController@destroy` | role+campus |
| POST | `api/v1/attendance/{id}/convert-to-attended` | `AttendanceController@convertToAttended` | role+campus |

## /api/v1/auth (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/auth/forgot-password` | `PasswordResetRequestController@store` | public |
| POST | `api/v1/auth/login` | `AuthController@login` | public |
| POST | `api/v1/auth/register` | `AuthController@register` | public |

## /api/v1/backfill (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/backfill/register-subject-units` | `BackfillController@registerSubjectUnits` | role+campus |

## /api/v1/bank-reconciliation (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/bank-reconciliation` | `BankReconciliationController@index` | role+campus |
| POST | `api/v1/bank-reconciliation/import` | `BankReconciliationController@importCsv` | role+campus |
| POST | `api/v1/bank-reconciliation/{id}/reconcile` | `BankReconciliationController@reconcile` | role+campus |
| GET | `api/v1/bank-reconciliation/{id}/suggest` | `BankReconciliationController@suggestMatches` | role+campus |

## /api/v1/branches (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/branches` | `CampusController@listPublic` | public |

## /api/v1/bugs (9)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/bugs` | `BugReportController@store` | role+campus |
| GET | `api/v1/bugs` | `BugReportController@index` | role+campus |
| POST | `api/v1/bugs/mark-inbox-seen` | `BugReportController@markInboxSeen` | role+campus |
| GET | `api/v1/bugs/unread-badge` | `BugReportController@unreadBadge` | role+campus |
| GET | `api/v1/bugs/{id}` | `BugReportController@show` | role+campus |
| POST | `api/v1/bugs/{id}/comments` | `BugReportController@addComment` | role+campus |
| PATCH | `api/v1/bugs/{id}/comments/{commentId}/visibility` | `BugReportController@updateCommentVisibility` | role+campus |
| POST | `api/v1/bugs/{id}/reporter-verify` | `BugReportController@reporterVerify` | role+campus |
| POST | `api/v1/bugs/{id}/status` | `BugReportController@updateStatus` | role+campus |

## /api/v1/campuses (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/campuses` | `CampusController@index` | role+campus |

## /api/v1/chat (18)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| DELETE | `api/v1/chat/messages/{messageId}` | `ChatController@deleteMessage` | role+campus |
| GET | `api/v1/chat/threads` | `ChatController@threads` | role+campus |
| POST | `api/v1/chat/threads/dm` | `ChatController@createDm` | role+campus |
| POST | `api/v1/chat/threads/group` | `ChatController@createGroup` | role+campus |
| DELETE | `api/v1/chat/threads/{threadId}` | `ChatController@deleteThread` | role+campus |
| PATCH | `api/v1/chat/threads/{threadId}` | `ChatController@updateThread` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/attachments` | `ChatController@uploadAttachment` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/leave` | `ChatController@leaveThread` | role+campus |
| GET | `api/v1/chat/threads/{threadId}/members` | `ChatController@getMembers` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/members` | `ChatController@addMembers` | role+campus |
| DELETE | `api/v1/chat/threads/{threadId}/members/{userId}` | `ChatController@removeMember` | role+campus |
| GET | `api/v1/chat/threads/{threadId}/messages` | `ChatController@messages` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/messages` | `ChatController@sendMessage` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/pin` | `ChatController@pinThread` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/read` | `ChatController@markRead` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/transfer-owner` | `ChatController@transferOwner` | role+campus |
| POST | `api/v1/chat/threads/{threadId}/typing` | `ChatController@typing` | role+campus |
| GET | `api/v1/chat/unread-count` | `ChatController@unreadCount` | role+campus |

## /api/v1/class-sessions (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/class-sessions` | `ClassSessionController@index` | role+campus |
| POST | `api/v1/class-sessions/batch` | `ClassSessionController@batchStore` | role+campus |
| POST | `api/v1/class-sessions/ensure-projected` | `ClassSessionController@ensureProjected` | role+campus |
| GET | `api/v1/class-sessions/projection` | `ClassSessionController@projection` | role+campus |
| PATCH | `api/v1/class-sessions/{id}` | `ClassSessionController@update` | role+campus |
| POST | `api/v1/class-sessions/{id}/substitute` | `ClassSessionController@substitute` | role+campus |
| POST | `api/v1/class-sessions/{id}/substitute/undo` | `SubstituteController@undo` | role+campus |

## /api/v1/course-packages (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/course-packages` | `CoursePackageController@index` | role+campus |
| POST | `api/v1/course-packages/create-multi-subject` | `CoursePackageController@createMultiSubject` | role+campus |
| GET | `api/v1/course-packages/{id}` | `CoursePackageController@show` | role+campus |
| PUT | `api/v1/course-packages/{id}` | `CoursePackageController@update` | role+campus |
| POST | `api/v1/course-packages/{id}/bind-courses` | `CoursePackageController@bindCourses` | role+campus |
| POST | `api/v1/course-packages/{id}/rebuild-ledger` | `CoursePackageController@rebuildLedger` | role+campus |
| POST | `api/v1/course-packages/{id}/recompute` | `CoursePackageController@recompute` | role+campus |

## /api/v1/directors (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/directors` | `DirectorAccountController@index` | role+campus |
| GET | `api/v1/directors/pending` | `DirectorAccountController@pending` | role+campus |
| POST | `api/v1/directors/register` | `DirectorAccountController@register` | public |
| DELETE | `api/v1/directors/{id}` | `DirectorAccountController@destroy` | role+campus |
| POST | `api/v1/directors/{id}/approve` | `DirectorAccountController@approve` | role+campus |
| PUT | `api/v1/directors/{id}/campuses` | `DirectorAccountController@updateCampuses` | role+campus |
| POST | `api/v1/directors/{id}/reject` | `DirectorAccountController@reject` | role+campus |
| POST | `api/v1/directors/{id}/reset-password` | `DirectorAccountController@resetPassword` | role+campus |

## /api/v1/dunning (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/dunning/history` | `DunningController@history` | role+campus |
| GET | `api/v1/dunning/rules` | `DunningController@rules` | role+campus |
| POST | `api/v1/dunning/trigger` | `DunningController@trigger` | role+campus |

## /api/v1/engagement (8)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/engagement/award-xp` | `EngagementController@awardXp` | role |
| GET | `api/v1/engagement/badges` | `EngagementController@badges` | role |
| POST | `api/v1/engagement/badges/{key}/toggle-visibility` | `EngagementController@toggleBadgeVisibility` | role |
| GET | `api/v1/engagement/event-types` | `EngagementController@eventTypes` | role |
| GET | `api/v1/engagement/my-progress` | `EngagementController@myProgress` | role |
| GET | `api/v1/engagement/rank-thresholds` | `EngagementController@rankThresholds` | role |
| POST | `api/v1/engagement/ranks-for` | `EngagementController@ranksFor` | role |
| GET | `api/v1/engagement/xp-history` | `EngagementController@xpHistory` | role |

## /api/v1/enrollments (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/enrollments` | `EnrollmentController@store` | role+campus |

## /api/v1/exception-workflows (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/exception-workflows` | `ExceptionWorkflowController@index` | role+campus |
| GET | `api/v1/exception-workflows/{id}` | `ExceptionWorkflowController@show` | role+campus |
| POST | `api/v1/exception-workflows/{id}/confirm-candidate` | `ExceptionWorkflowController@confirmCandidate` | role+campus |
| POST | `api/v1/exception-workflows/{id}/generate-candidates` | `ExceptionWorkflowController@generateCandidates` | role+campus |
| POST | `api/v1/exception-workflows/{id}/waive` | `ExceptionWorkflowController@waive` | role+campus |

## /api/v1/finance (24)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/finance/ar-aging` | `FinanceController@arAging` | role+campus |
| GET | `api/v1/finance/branch-monthly-tuition` | `FinanceController@branchMonthlyTuition` | role+campus+pin |
| GET | `api/v1/finance/branch-monthly-tuition/export` | `FinanceController@branchMonthlyTuitionExport` | role+campus+pin |
| GET | `api/v1/finance/consolidated-summary` | `FinanceController@consolidatedSummary` | role+campus |
| GET | `api/v1/finance/duplicate-courses` | `FinanceController@duplicateCourses` | role+campus |
| GET | `api/v1/finance/gl-export` | `FinanceController@glExport` | role+campus |
| GET | `api/v1/finance/outstanding` | `FinanceController@outstanding` | role+campus |
| GET | `api/v1/finance/parttime-payroll` | `FinanceController@parttimePayroll` | role+campus+pin |
| GET | `api/v1/finance/parttime-payroll/export` | `FinanceController@parttimePayrollExport` | role+campus+pin |
| POST | `api/v1/finance/parttime-payroll/lock` | `FinanceController@parttimePayrollLock` | role+campus+pin |
| POST | `api/v1/finance/parttime-payroll/reopen` | `FinanceController@parttimePayrollReopen` | role+campus+pin |
| GET | `api/v1/finance/parttime-payroll/rules` | `FinanceController@parttimePayrollRules` | role+campus+pin |
| PUT | `api/v1/finance/parttime-payroll/rules` | `FinanceController@parttimePayrollRulesUpdate` | role+campus+pin |
| GET | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRules` | role+campus+pin |
| PUT | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRulesUpdate` | role+campus+pin |
| DELETE | `api/v1/finance/parttime-payroll/teacher-rules` | `FinanceController@parttimePayrollTeacherRulesDelete` | role+campus+pin |
| GET | `api/v1/finance/parttime-payroll/{teacherId}/sessions` | `FinanceController@parttimePayrollSessions` | role+campus+pin |
| GET | `api/v1/finance/periods` | `AccountingPeriodController@index` | role+campus |
| POST | `api/v1/finance/periods/close` | `AccountingPeriodController@close` | role+campus |
| POST | `api/v1/finance/periods/reopen` | `AccountingPeriodController@reopen` | role+campus |
| GET | `api/v1/finance/revenue` | `FinanceController@revenue` | role+campus |
| GET | `api/v1/finance/subject-units` | `FinanceController@subjectUnits` | role+campus |
| GET | `api/v1/finance/summary` | `FinanceController@summary` | role+campus |
| GET | `api/v1/finance/teacher-payroll` | `FinanceController@teacherPayroll` | role+campus+pin |

## /api/v1/health (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/health` | `Closure` | public |
| GET | `api/v1/health/detailed` | `Closure` | public |

## /api/v1/invoices (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/invoices` | `BillingController@index` | role+campus |
| POST | `api/v1/invoices` | `BillingController@store` | role+campus |
| GET | `api/v1/invoices/export` | `ExportController@invoices` | role+campus |
| POST | `api/v1/invoices/{invoice}/exception-void` | `BillingController@exceptionVoidInvoice` | role+campus |
| POST | `api/v1/invoices/{invoice}/payments` | `BillingController@recordPayment` | role+campus |
| GET | `api/v1/invoices/{invoice}/slip-data` | `BillingController@slipData` | role+campus |
| POST | `api/v1/invoices/{invoice}/void` | `BillingController@voidInvoice` | role+campus |

## /api/v1/learning-record-feedbacks (5)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/learning-record-feedbacks` | `LearningRecordFeedbackController@index` | role+campus |
| GET | `api/v1/learning-record-feedbacks/analytics` | `LearningRecordFeedbackController@analytics` | role+campus |
| POST | `api/v1/learning-record-feedbacks/{feedback}/read` | `LearningRecordFeedbackController@markRead` | role+campus |
| GET | `api/v1/learning-record-feedbacks/{feedback}/replies` | `LearningRecordFeedbackController@replies` | role+campus |
| POST | `api/v1/learning-record-feedbacks/{feedback}/reply` | `LearningRecordFeedbackController@staffReply` | role+campus |

## /api/v1/learning-record-teacher-comments (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/learning-record-teacher-comments/{comment}/read` | `LearningRecordTeacherCommentController@markRead` | role+campus |

## /api/v1/learning-records (19)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/learning-records` | `LearningRecordController@index` | role+campus |
| POST | `api/v1/learning-records` | `LearningRecordController@store` | role+campus |
| POST | `api/v1/learning-records/backdoor-approve` | `LearningRecordController@backdoorApprove` | role+campus |
| POST | `api/v1/learning-records/batch-approve` | `LearningRecordController@batchApprove` | role+campus |
| POST | `api/v1/learning-records/batch-reject` | `LearningRecordController@batchReject` | role+campus |
| POST | `api/v1/learning-records/batch-request-changes` | `LearningRecordController@batchRequestChanges` | role+campus |
| POST | `api/v1/learning-records/bulk-backdoor-approve` | `LearningRecordController@bulkBackdoorApprove` | role+campus |
| POST | `api/v1/learning-records/ensure-past` | `LearningRecordController@ensurePastRecords` | role+campus |
| GET | `api/v1/learning-records/latest-approved-summary` | `LearningRecordController@latestApprovedSummary` | role+campus |
| POST | `api/v1/learning-records/reschedule-session` | `LearningRecordController@rescheduleSession` | role+campus |
| POST | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@update` | role+campus |
| PUT | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@update` | role+campus |
| DELETE | `api/v1/learning-records/{learningRecord}` | `LearningRecordController@destroy` | role+campus |
| POST | `api/v1/learning-records/{learningRecord}/approve` | `LearningRecordController@approve` | role+campus |
| POST | `api/v1/learning-records/{learningRecord}/reject` | `LearningRecordController@reject` | role+campus |
| POST | `api/v1/learning-records/{learningRecord}/request-changes` | `LearningRecordController@requestChanges` | role+campus |
| POST | `api/v1/learning-records/{learningRecord}/rollback-approval` | `LearningRecordController@rollbackApproval` | role+campus |
| PATCH | `api/v1/learning-records/{learningRecord}/teacher` | `LearningRecordController@updateTeacher` | role+campus |
| PUT | `api/v1/learning-records/{learningRecord}/teacher-comment` | `LearningRecordTeacherCommentController@upsert` | role+campus |

## /api/v1/line (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/line/settings` | `LineWebhookController@saveSettings` | role |
| GET | `api/v1/line/status` | `LineWebhookController@status` | role |
| POST | `api/v1/line/webhook` | `LineWebhookController@handleDomainBased` | public |
| POST | `api/v1/line/webhook/{campusId}` | `LineWebhookController@handle` | public |

## /api/v1/me (15)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/me` | `AuthController@me` | public |
| PUT | `api/v1/me` | `AuthController@updateMe` | public |
| POST | `api/v1/me/avatar` | `AuthController@uploadAvatar` | public |
| GET | `api/v1/me/learning-pending-summary` | `LearningRecordController@teacherPendingBadgeSummary` | role+campus |
| GET | `api/v1/me/learning-progress-summary` | `LearningRecordController@teacherLearningProgressSummary` | role+campus |
| GET | `api/v1/me/notification-preferences` | `AuthController@notificationPreferences` | public |
| PUT | `api/v1/me/notification-preferences` | `AuthController@updateNotificationPreferences` | public |
| POST | `api/v1/me/pin/lock` | `PinVerificationController@lock` | public |
| POST | `api/v1/me/pin/reset` | `PinVerificationController@reset` | public |
| POST | `api/v1/me/pin/set` | `PinVerificationController@set` | public |
| GET | `api/v1/me/pin/status` | `PinVerificationController@status` | public |
| POST | `api/v1/me/pin/verify` | `PinVerificationController@verify` | public |
| GET | `api/v1/me/security` | `AuthController@security` | public |
| POST | `api/v1/me/security/logout-others` | `AuthController@logoutOtherSessions` | public |
| GET | `api/v1/me/unread-feedback-count` | `LearningRecordFeedbackController@unreadCount` | role+campus |

## /api/v1/notifications (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/notifications` | `NotificationController@index` | role+campus |
| POST | `api/v1/notifications/read-all` | `NotificationController@markAllRead` | role+campus |
| POST | `api/v1/notifications/sync` | `NotificationController@sync` | role+campus |
| GET | `api/v1/notifications/unread-count` | `NotificationController@unreadCount` | role+campus |
| POST | `api/v1/notifications/{notificationId}/read` | `NotificationController@markRead` | role+campus |
| POST | `api/v1/notifications/{notificationId}/tuition-paid` | `NotificationController@markTuitionPaid` | role+campus |

## /api/v1/parent-feedback (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/parent-feedback` | `ParentFeedbackController@index` | role+campus |
| GET | `api/v1/parent-feedback/for-teacher` | `ParentFeedbackController@forTeacher` | role+campus |
| GET | `api/v1/parent-feedback/unread-count` | `ParentFeedbackController@unreadCount` | role+campus |
| POST | `api/v1/parent-feedback/{id}/mark-read` | `ParentFeedbackController@markRead` | role+campus |
| POST | `api/v1/parent-feedback/{id}/read` | `ParentFeedbackController@markReadByTeacher` | role+campus |
| GET | `api/v1/parent-feedback/{id}/replies` | `ParentFeedbackController@replies` | role+campus |
| POST | `api/v1/parent-feedback/{id}/reply` | `ParentFeedbackController@reply` | role+campus |

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
| GET | `api/v1/parent/payment-message/{studentId}` | `ParentPortalController@paymentMessage` | role |
| GET | `api/v1/parent/resolve-liff` | `ParentPortalController@resolveLiff` | public |
| POST | `api/v1/parent/sessions/{sessionId}/leave` | `ParentPortalController@requestLeave` | public |
| POST | `api/v1/parent/switch-student` | `ParentPortalController@switchStudent` | public |
| GET | `api/v1/parent/system-trust-summary` | `SystemTrustController@parentSummary` | public |

## /api/v1/part-time-rate-cards (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/part-time-rate-cards` | `PartTimeRateCardController@index` | role+campus+pin |
| POST | `api/v1/part-time-rate-cards` | `PartTimeRateCardController@store` | role+campus+pin |
| PUT | `api/v1/part-time-rate-cards/{id}` | `PartTimeRateCardController@update` | role+campus+pin |
| DELETE | `api/v1/part-time-rate-cards/{id}` | `PartTimeRateCardController@destroy` | role+campus+pin |

## /api/v1/payment-reports (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/payment-reports` | `PaymentReportController@index` | role+campus |
| POST | `api/v1/payment-reports/director-record` | `PaymentReportController@directorRecord` | role+campus |
| PUT | `api/v1/payment-reports/{id}/confirm` | `PaymentReportController@confirm` | role+campus |
| GET | `api/v1/payment-reports/{id}/receipt` | `PaymentReportController@receipt` | role+campus |
| PUT | `api/v1/payment-reports/{id}/reject` | `PaymentReportController@reject` | role+campus |
| PUT | `api/v1/payment-reports/{id}/void` | `PaymentReportController@void` | role+campus |

## /api/v1/pending-swipes (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/pending-swipes` | `PendingSwipeController@index` | role+campus |
| DELETE | `api/v1/pending-swipes/{pendingSwipe}` | `PendingSwipeController@destroy` | role+campus |
| POST | `api/v1/pending-swipes/{pendingSwipe}/assign-student` | `PendingSwipeController@assignStudent` | role+campus |
| POST | `api/v1/pending-swipes/{pendingSwipe}/match` | `PendingSwipeController@match` | role+campus |

## /api/v1/profiles (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/profiles` | `ProfileController@index` | role+campus |
| POST | `api/v1/profiles` | `ProfileController@store` | role+campus |
| POST | `api/v1/profiles/bulk-teachers` | `ProfileController@bulkTeachers` | role+campus |
| PUT | `api/v1/profiles/{id}` | `ProfileController@update` | role+campus |
| DELETE | `api/v1/profiles/{id}` | `ProfileController@destroy` | role+campus |
| POST | `api/v1/profiles/{id}/reset-password` | `ProfileController@resetPassword` | role+campus |

## /api/v1/recent-unknown-rfids (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/recent-unknown-rfids` | `PendingSwipeController@recentUnknownRfids` | role+campus |

## /api/v1/reports (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/reports/teacher-learning-fill-rates` | `ClassSessionController@directorTeacherLearningFillRates` | role+campus |

## /api/v1/rooms (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/rooms` | `RoomController@index` | role+campus |
| POST | `api/v1/rooms` | `RoomController@store` | role+campus |
| PUT | `api/v1/rooms/{room}` | `RoomController@update` | role+campus |
| DELETE | `api/v1/rooms/{room}` | `RoomController@destroy` | role+campus |

## /api/v1/schedule-audit (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/schedule-audit` | `ScheduleAuditController@index` | role+campus |

## /api/v1/schedule-discrepancies (7)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/schedule-discrepancies` | `ScheduleDiscrepancyController@store` | role+campus |
| GET | `api/v1/schedule-discrepancies` | `ScheduleDiscrepancyController@index` | role+campus |
| GET | `api/v1/schedule-discrepancies/active-for-session` | `ScheduleDiscrepancyController@activeForSession` | role+campus |
| GET | `api/v1/schedule-discrepancies/my` | `ScheduleDiscrepancyController@mine` | role+campus |
| GET | `api/v1/schedule-discrepancies/summary` | `ScheduleDiscrepancyController@summary` | role+campus |
| PUT | `api/v1/schedule-discrepancies/{id}` | `ScheduleDiscrepancyController@updateStatus` | role+campus |
| POST | `api/v1/schedule-discrepancies/{id}/withdraw` | `ScheduleDiscrepancyController@withdraw` | role+campus |

## /api/v1/schedule-import (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/schedule-import/preview` | `ScheduleImportController@preview` | role+campus |

## /api/v1/schedules (10)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/schedules` | `ScheduleController@index` | role+campus |
| POST | `api/v1/schedules` | `ScheduleController@store` | role+campus |
| POST | `api/v1/schedules/bulk-leave` | `ScheduleController@bulkHolidayLeave` | role+campus |
| POST | `api/v1/schedules/leave-by-session` | `ScheduleController@leaveBySession` | role+campus |
| POST | `api/v1/schedules/retro-leave` | `ScheduleController@retroLeave` | role+campus |
| POST | `api/v1/schedules/undo-leave-by-session` | `ScheduleController@undoLeaveBySession` | role+campus |
| PUT | `api/v1/schedules/{schedule}` | `ScheduleController@update` | role+campus |
| DELETE | `api/v1/schedules/{schedule}` | `ScheduleController@destroy` | role+campus |
| POST | `api/v1/schedules/{schedule}/cancel-makeup` | `ScheduleController@cancelMakeup` | role+campus |
| POST | `api/v1/schedules/{schedule}/undo-leave` | `ScheduleController@undoLeave` | role+campus |

## /api/v1/student-classes (19)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/student-classes` | `StudentClassController@index` | role+campus |
| POST | `api/v1/student-classes` | `StudentClassController@store` | role+campus |
| GET | `api/v1/student-classes/export` | `ExportController@studentClasses` | role+campus |
| POST | `api/v1/student-classes/import` | `ImportController@studentClasses` | role+campus |
| GET | `api/v1/student-classes/session-dates` | `StudentClassController@sessionDates` | role+campus |
| POST | `api/v1/student-classes/session-dates` | `StudentClassController@sessionDates` | role+campus |
| POST | `api/v1/student-classes/sync` | `StudentClassController@sync` | role+campus |
| GET | `api/v1/student-classes/{studentClass}` | `StudentClassController@show` | role+campus |
| PUT | `api/v1/student-classes/{studentClass}` | `StudentClassController@update` | role+campus |
| DELETE | `api/v1/student-classes/{studentClass}` | `StudentClassController@destroy` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/add-session` | `StudentClassController@addSession` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/add-session/check` | `StudentClassController@checkAddSession` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/confirm-payment` | `StudentClassController@confirmPayment` | role+campus |
| GET | `api/v1/student-classes/{studentClass}/invoices` | `StudentClassController@invoices` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/pause` | `StudentClassController@togglePause` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/purchase-batch` | `StudentClassController@purchaseBatch` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/renew-monthly` | `StudentClassController@renewMonthly` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/renewal-confirm` | `StudentClassController@renewalConfirm` | role+campus |
| POST | `api/v1/student-classes/{studentClass}/renewal-preview` | `StudentClassController@renewalPreview` | role+campus |

## /api/v1/students (12)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/students` | `StudentController@index` | role+campus |
| POST | `api/v1/students` | `StudentController@store` | role+campus |
| POST | `api/v1/students/bulk-delete` | `StudentController@bulkDestroy` | role+campus |
| GET | `api/v1/students/export` | `ExportController@students` | role+campus |
| POST | `api/v1/students/import` | `ImportController@students` | role+campus |
| GET | `api/v1/students/{student}` | `StudentController@show` | role+campus |
| PUT | `api/v1/students/{student}` | `StudentController@update` | role+campus |
| DELETE | `api/v1/students/{student}` | `StudentController@destroy` | role+campus |
| GET | `api/v1/students/{student}/active-courses` | `StudentController@activeCourses` | role+campus |
| POST | `api/v1/students/{student}/bind-card` | `StudentController@bindCard` | role+campus |
| GET | `api/v1/students/{student}/line-bindings` | `StudentController@lineBindings` | role+campus |
| DELETE | `api/v1/students/{student}/line-bindings/{binding}` | `StudentController@removeLineBinding` | role+campus |

## /api/v1/subjects (4)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/subjects` | `SubjectController@store` | role+campus |
| GET | `api/v1/subjects` | `SubjectController@index` | role+campus |
| PUT | `api/v1/subjects/{id}` | `SubjectController@update` | role+campus |
| DELETE | `api/v1/subjects/{id}` | `SubjectController@destroy` | role+campus |

## /api/v1/subjects-public (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/subjects-public` | `SubjectController@indexPublic` | public |

## /api/v1/substitutes (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/substitutes/recent` | `SubstituteController@recent` | role+campus |

## /api/v1/swipe-rfid (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/swipe-rfid` | `SwipeRfidController@swipe` | public |

## /api/v1/system (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/system/settings/substitute-undo` | `SubstituteController@getUndoSetting` | role+campus |
| PUT | `api/v1/system/settings/substitute-undo` | `SubstituteController@setUndoSetting` | role+campus |
| GET | `api/v1/system/trust-summary` | `SystemTrustController@summary` | role+campus |

## /api/v1/teacher-attendance (6)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teacher-attendance` | `TeacherAttendanceController@index` | role+campus |
| GET | `api/v1/teacher-attendance/export` | `TeacherAttendanceController@export` | role+campus |
| GET | `api/v1/teacher-attendance/export-monthly` | `TeacherAttendanceController@exportMonthly` | role+campus |
| GET | `api/v1/teacher-attendance/today` | `TeacherAttendanceController@today` | role+campus |
| GET | `api/v1/teacher-attendance/unclosed` | `TeacherAttendanceController@unclosed` | role+campus |
| POST | `api/v1/teacher-attendance/{id}/adjust` | `TeacherAttendanceController@adjust` | role+campus |

## /api/v1/teacher-leaves (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/teacher-leaves/batch-substitute` | `TeacherLeaveController@batchSubstitute` | role+campus |
| POST | `api/v1/teacher-leaves/preview` | `TeacherLeaveController@preview` | role+campus |

## /api/v1/teacher_branches (3)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teacher_branches` | `TeacherBranchController@index` | role+campus |
| POST | `api/v1/teacher_branches` | `TeacherBranchController@store` | role+campus |
| DELETE | `api/v1/teacher_branches` | `TeacherBranchController@destroy` | role+campus |

## /api/v1/teachers (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teachers` | `Closure` | role+campus |
| GET | `api/v1/teachers/{id}/availability` | `SubstituteController@availability` | role+campus |

## /api/v1/teaching-logs (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/teaching-logs/missing` | `TeachingLogController@missing` | role+campus |

## /api/v1/telegram (1)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| POST | `api/v1/telegram/webhook/{code}` | `TelegramWebhookController@handle` | public |

## /api/v1/temp-rfid (2)

| Method | URI | Action | Auth |
|--------|-----|--------|------|
| GET | `api/v1/temp-rfid` | `TempRfidController@show` | role+campus |
| POST | `api/v1/temp-rfid/consume` | `TempRfidController@consume` | role+campus |


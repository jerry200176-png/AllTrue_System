# API 路由參考（自動產生）

> 由 `php artisan route:list --path=api --json` 自動產生，共 363 條路由。
> 只列出 method / URI / controller action / middleware，不含 request/response schema（golden contracts 屬後續工作，見 #992）。
> 重新產生：`php artisan route:list --path=api --json` 後用本檔案同樣的分組邏輯手動更新，或參考 `scripts/generate-api-routes-doc.php`。

## 目錄

- [student-classes](#student-classes) （21 條）
- [class-sessions](#class-sessions) （7 條）
- [schedules](#schedules) （11 條）
- [auth](#auth) （3 條）
- [swipe-rfid](#swipe-rfid) （1 條）
- [payment-reports](#payment-reports) （6 條）
- [accounting](#accounting) （4 條）
- [action-inbox](#action-inbox) （3 條）
- [admin](#admin) （18 條）
- [adoption](#adoption) （5 條）
- [alerts](#alerts) （2 條）
- [api-clients](#api-clients) （3 條）
- [attendance](#attendance) （8 條）
- [backfill](#backfill) （1 條）
- [bank-reconciliation](#bank-reconciliation) （4 條）
- [branches](#branches) （1 條）
- [bugs](#bugs) （9 條）
- [campuses](#campuses) （1 條）
- [chat](#chat) （18 條）
- [course-contract-groups](#course-contract-groups) （4 條）
- [course-packages](#course-packages) （7 條）
- [director](#director) （1 條）
- [directors](#directors) （8 條）
- [dunning](#dunning) （3 條）
- [engagement](#engagement) （8 條）
- [enrollments](#enrollments) （1 條）
- [exception-workflows](#exception-workflows) （6 條）
- [finance](#finance) （40 條）
- [fix-db](#fix-db) （1 條）
- [github](#github) （1 條）
- [health](#health) （3 條）
- [internal](#internal) （1 條）
- [invoices](#invoices) （7 條）
- [learning-record-feedbacks](#learning-record-feedbacks) （5 條）
- [learning-record-teacher-comments](#learning-record-teacher-comments) （1 條）
- [learning-records](#learning-records) （19 條）
- [line](#line) （4 條）
- [me](#me) （16 條）
- [notifications](#notifications) （6 條）
- [parent](#parent) （16 條）
- [parent-feedback](#parent-feedback) （7 條）
- [part-time-rate-cards](#part-time-rate-cards) （4 條）
- [pending-swipes](#pending-swipes) （4 條）
- [profiles](#profiles) （6 條）
- [recent-unknown-rfids](#recent-unknown-rfids) （1 條）
- [reports](#reports) （1 條）
- [rooms](#rooms) （4 條）
- [schedule-audit](#schedule-audit) （1 條）
- [schedule-discrepancies](#schedule-discrepancies) （7 條）
- [schedule-import](#schedule-import) （1 條）
- [student-identities](#student-identities) （5 條）
- [students](#students) （12 條）
- [subjects](#subjects) （4 條）
- [subjects-public](#subjects-public) （1 條）
- [substitutes](#substitutes) （1 條）
- [system](#system) （3 條）
- [teacher-attendance](#teacher-attendance) （6 條）
- [teacher-leaves](#teacher-leaves) （2 條）
- [teacher_branches](#teacher-branches) （3 條）
- [teachers](#teachers) （2 條）
- [teaching-logs](#teaching-logs) （1 條）
- [telegram](#telegram) （1 條）
- [temp-rfid](#temp-rfid) （2 條）

## student-classes

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/student-classes` | StudentClassController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes` | StudentClassController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/student-classes/export` | ExportController@studentClasses | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/import` | ImportController@studentClasses | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/student-classes/session-dates` | StudentClassController@sessionDates | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/session-dates` | StudentClassController@sessionDates | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/sync` | StudentClassController@sync | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/student-classes/{studentClass}` | StudentClassController@show | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/student-classes/{studentClass}` | StudentClassController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/student-classes/{studentClass}` | StudentClassController@destroy | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/add-session` | StudentClassController@addSession | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/add-session/check` | StudentClassController@checkAddSession | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/confirm-payment` | StudentClassController@confirmPayment | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/student-classes/{studentClass}/invoices` | StudentClassController@invoices | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/manual-sessions` | StudentClassController@createManualSession | App\Http\Middleware\RequireRole:director,admin,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/manual-sessions/check` | StudentClassController@checkManualSession | App\Http\Middleware\RequireRole:director,admin,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/pause` | StudentClassController@togglePause | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/purchase-batch` | StudentClassController@purchaseBatch | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/renew-monthly` | StudentClassController@renewMonthly | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/renewal-confirm` | StudentClassController@renewalConfirm | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-classes/{studentClass}/renewal-preview` | StudentClassController@renewalPreview | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## class-sessions

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/class-sessions` | ClassSessionController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/class-sessions/batch` | ClassSessionController@batchStore | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/class-sessions/ensure-projected` | ClassSessionController@ensureProjected | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| GET|HEAD | `api/v1/class-sessions/projection` | ClassSessionController@projection | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PATCH | `api/v1/class-sessions/{id}` | ClassSessionController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/class-sessions/{id}/substitute` | ClassSessionController@substitute | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/class-sessions/{id}/substitute/undo` | SubstituteController@undo | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## schedules

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/schedules` | ScheduleController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules` | ScheduleController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/bulk-leave` | ScheduleController@bulkHolidayLeave | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/leave-by-session` | ScheduleController@leaveBySession | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/leave-cascade-preview` | ScheduleController@leaveCascadePreview | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/retro-leave` | ScheduleController@retroLeave | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/undo-leave-by-session` | ScheduleController@undoLeaveBySession | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/schedules/{schedule}` | ScheduleController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/schedules/{schedule}` | ScheduleController@destroy | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/{schedule}/cancel-makeup` | ScheduleController@cancelMakeup | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedules/{schedule}/undo-leave` | ScheduleController@undoLeave | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## auth

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/auth/forgot-password` | PasswordResetRequestController@store | App\Http\Middleware\ThrottleRequestsByIp:5,60 |
| POST | `api/v1/auth/login` | AuthController@login |  |
| POST | `api/v1/auth/register` | AuthController@register | App\Http\Middleware\ThrottleRequestsByIp:10,10 |

## swipe-rfid

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/swipe-rfid` | SwipeRfidController@swipe | App\Http\Middleware\ThrottleRequestsByIp:30,1 |

## payment-reports

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/payment-reports` | PaymentReportController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/payment-reports/director-record` | PaymentReportController@directorRecord | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/payment-reports/{id}/confirm` | PaymentReportController@confirm | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/payment-reports/{id}/receipt` | PaymentReportController@receipt | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/payment-reports/{id}/reject` | PaymentReportController@reject | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/payment-reports/{id}/void` | PaymentReportController@void | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## accounting

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/accounting/ledger` | AccountingController@ledger | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/accounting/payments` | AccountingController@payments | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/accounting/payments/export` | AccountingController@paymentsExport | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/accounting/settled-courses` | AccountingController@settledCourses | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |

## action-inbox

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/action-inbox` | ActionInboxController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/action-inbox/cases/{id}` | ActionInboxController@showCase | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/action-inbox/count` | ActionInboxController@count | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## admin

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/admin/bug-reports` | BugReportController@index | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/business-digest` | BusinessDigestController@index | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/campuses` | AdminCampusController@index | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/campuses` | AdminCampusController@store | App\Http\Middleware\RequireRole:super_admin |
| PUT | `api/v1/admin/campuses/{id}` | AdminCampusController@update | App\Http\Middleware\RequireRole:super_admin |
| DELETE | `api/v1/admin/campuses/{id}` | AdminCampusController@destroy | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/duplicate-sessions/p2-review` | AdminDuplicateSessionController@p2Review | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PATCH | `api/v1/admin/duplicate-sessions/p2-review/{groupId}` | AdminDuplicateSessionController@patchP2Review | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/admin/reconcile` | AdminReconcileController@index | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/reconcile/latest` | AdminReconcileController@latest | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/reset-data` | ResetDataController | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/admin/routing-rules` | AdminRoutingRuleController@index | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/routing-rules/check` | AdminRoutingRuleController@check | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/routing-rules/versions` | AdminRoutingRuleController@store | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/routing-rules/versions/{version}/publish` | AdminRoutingRuleController@publish | App\Http\Middleware\RequireRole:super_admin |
| GET|HEAD | `api/v1/admin/teachers/duplicates` | TeacherDuplicateController@index | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/teachers/merge` | TeacherDuplicateController@merge | App\Http\Middleware\RequireRole:super_admin |
| POST | `api/v1/admin/teachers/merge-preview` | TeacherDuplicateController@preview | App\Http\Middleware\RequireRole:super_admin |

## adoption

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/adoption/activity-log` | AdoptionInsightsController@activityLog | App\Http\Middleware\RequireRole:director,teacher,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/adoption/cross-branch-metrics` | AdoptionInsightsController@crossBranchMetrics | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/adoption/events` | AdoptionInsightsController@recordEvent | App\Http\Middleware\RequireRole:director,teacher,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/adoption/task-tracker` | AdoptionInsightsController@taskTracker | App\Http\Middleware\RequireRole:director,teacher,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/adoption/weekly-metrics` | AdoptionInsightsController@weeklyMetrics | App\Http\Middleware\RequireRole:director,teacher,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## alerts

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/alerts/tuition` | AlertController@tuition | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/alerts/tuition-slip/{studentClassId}` | AlertController@tuitionSlipData | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## api-clients

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/api-clients` | ApiClientController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/api-clients` | ApiClientController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/api-clients/{apiClient}/revoke` | ApiClientController@revoke | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## attendance

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/attendance` | AttendanceController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/attendance` | AttendanceController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/attendance/batch-mark` | AttendanceController@batchMark | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/attendance/ended-sessions` | AttendanceController@endedSessions | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/attendance/swipe` | AttendanceController@swipe | App\Http\Middleware\ApiKeyAuth |
| PATCH | `api/v1/attendance/{id}` | AttendanceController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| DELETE | `api/v1/attendance/{id}` | AttendanceController@destroy | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| POST | `api/v1/attendance/{id}/convert-to-attended` | AttendanceController@convertToAttended | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |

## backfill

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/backfill/register-subject-units` | BackfillController@registerSubjectUnits | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## bank-reconciliation

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/bank-reconciliation` | BankReconciliationController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bank-reconciliation/import` | BankReconciliationController@importCsv | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bank-reconciliation/{id}/reconcile` | BankReconciliationController@reconcile | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/bank-reconciliation/{id}/suggest` | BankReconciliationController@suggestMatches | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## branches

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/branches` | CampusController@listPublic |  |

## bugs

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/bugs` | BugReportController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/bugs` | BugReportController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bugs/mark-inbox-seen` | BugReportController@markInboxSeen | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/bugs/unread-badge` | BugReportController@unreadBadge | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/bugs/{id}` | BugReportController@show | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bugs/{id}/comments` | BugReportController@addComment | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PATCH | `api/v1/bugs/{id}/comments/{commentId}/visibility` | BugReportController@updateCommentVisibility | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bugs/{id}/reporter-verify` | BugReportController@reporterVerify | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/bugs/{id}/status` | BugReportController@updateStatus | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## campuses

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/campuses` | CampusController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## chat

| Method | URI | Action | Middleware |
|---|---|---|---|
| DELETE | `api/v1/chat/messages/{messageId}` | ChatController@deleteMessage | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/chat/threads` | ChatController@threads | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/dm` | ChatController@createDm | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/group` | ChatController@createGroup | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/chat/threads/{threadId}` | ChatController@deleteThread | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PATCH | `api/v1/chat/threads/{threadId}` | ChatController@updateThread | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/attachments` | ChatController@uploadAttachment | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/leave` | ChatController@leaveThread | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/chat/threads/{threadId}/members` | ChatController@getMembers | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/members` | ChatController@addMembers | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/chat/threads/{threadId}/members/{userId}` | ChatController@removeMember | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/chat/threads/{threadId}/messages` | ChatController@messages | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/messages` | ChatController@sendMessage | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/pin` | ChatController@pinThread | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/read` | ChatController@markRead | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/transfer-owner` | ChatController@transferOwner | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/chat/threads/{threadId}/typing` | ChatController@typing | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/chat/unread-count` | ChatController@unreadCount | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## course-contract-groups

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/course-contract-groups` | CourseContractGroupController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-contract-groups` | CourseContractGroupController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-contract-groups/{id}/members` | CourseContractGroupController@addMember | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/course-contract-groups/{id}/members/{memberId}` | CourseContractGroupController@unlinkMember | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## course-packages

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/course-packages` | CoursePackageController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-packages/create-multi-subject` | CoursePackageController@createMultiSubject | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/course-packages/{id}` | CoursePackageController@show | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/course-packages/{id}` | CoursePackageController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-packages/{id}/bind-courses` | CoursePackageController@bindCourses | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-packages/{id}/rebuild-ledger` | CoursePackageController@rebuildLedger | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/course-packages/{id}/recompute` | CoursePackageController@recompute | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## director

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/director/operations-trust` | DirectorOperationsTrustController@show | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## directors

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/directors` | DirectorAccountController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/directors/pending` | DirectorAccountController@pending | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/directors/register` | DirectorAccountController@register | App\Http\Middleware\ThrottleRequestsByIp:10,10 |
| DELETE | `api/v1/directors/{id}` | DirectorAccountController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/directors/{id}/approve` | DirectorAccountController@approve | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/directors/{id}/campuses` | DirectorAccountController@updateCampuses | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/directors/{id}/reject` | DirectorAccountController@reject | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/directors/{id}/reset-password` | DirectorAccountController@resetPassword | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## dunning

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/dunning/history` | DunningController@history | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/dunning/rules` | DunningController@rules | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/dunning/trigger` | DunningController@trigger | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## engagement

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/engagement/award-xp` | EngagementController@awardXp | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| GET|HEAD | `api/v1/engagement/badges` | EngagementController@badges | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| POST | `api/v1/engagement/badges/{key}/toggle-visibility` | EngagementController@toggleBadgeVisibility | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| GET|HEAD | `api/v1/engagement/event-types` | EngagementController@eventTypes | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| GET|HEAD | `api/v1/engagement/my-progress` | EngagementController@myProgress | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| GET|HEAD | `api/v1/engagement/rank-thresholds` | EngagementController@rankThresholds | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| POST | `api/v1/engagement/ranks-for` | EngagementController@ranksFor | App\Http\Middleware\RequireRole:director,teacher,super_admin |
| GET|HEAD | `api/v1/engagement/xp-history` | EngagementController@xpHistory | App\Http\Middleware\RequireRole:director,teacher,super_admin |

## enrollments

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/enrollments` | EnrollmentController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## exception-workflows

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/exception-workflows` | ExceptionWorkflowController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/exception-workflows/{id}` | ExceptionWorkflowController@show | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/exception-workflows/{id}/confirm-candidate` | ExceptionWorkflowController@confirmCandidate | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/exception-workflows/{id}/generate-candidates` | ExceptionWorkflowController@generateCandidates | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/exception-workflows/{id}/reject` | ExceptionWorkflowController@reject | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/exception-workflows/{id}/waive` | ExceptionWorkflowController@waive | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## finance

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/finance/ar-aging` | FinanceController@arAging | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/branch-monthly-tuition` | FinanceController@branchMonthlyTuition | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/branch-monthly-tuition/export` | FinanceController@branchMonthlyTuitionExport | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/consolidated-summary` | FinanceController@consolidatedSummary | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/duplicate-courses` | FinanceController@duplicateCourses | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/gl-export` | FinanceController@glExport | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/outstanding` | FinanceController@outstanding | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/parttime-payroll` | FinanceController@parttimePayroll | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/parttime-payroll/export` | FinanceController@parttimePayrollExport | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/parttime-payroll/lock` | FinanceController@parttimePayrollLock | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/parttime-payroll/reopen` | FinanceController@parttimePayrollReopen | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/parttime-payroll/rules` | FinanceController@parttimePayrollRules | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/finance/parttime-payroll/rules` | FinanceController@parttimePayrollRulesUpdate | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/parttime-payroll/teacher-rules` | FinanceController@parttimePayrollTeacherRules | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/finance/parttime-payroll/teacher-rules` | FinanceController@parttimePayrollTeacherRulesUpdate | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| DELETE | `api/v1/finance/parttime-payroll/teacher-rules` | FinanceController@parttimePayrollTeacherRulesDelete | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/parttime-payroll/{teacherId}/sessions` | FinanceController@parttimePayrollSessions | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/periods` | AccountingPeriodController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/finance/periods/close` | AccountingPeriodController@close | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/finance/periods/reopen` | AccountingPeriodController@reopen | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/revenue` | FinanceController@revenue | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/subject-units` | FinanceController@subjectUnits | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/summary` | FinanceController@summary | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/finance/teacher-eligibility` | TeacherEligibilityController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/achievements` | TeacherEligibilityInputController@storeAchievement | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/finance/teacher-eligibility/achievements/{id}` | TeacherEligibilityInputController@updateAchievement | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/achievements/{id}/verify` | TeacherEligibilityInputController@verifyAchievement | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/achievements/{id}/withdraw` | TeacherEligibilityInputController@withdrawAchievement | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/deductions` | TeacherEligibilityInputController@storeDeduction | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/finance/teacher-eligibility/deductions/{id}` | TeacherEligibilityInputController@updateDeduction | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/deductions/{id}/approve` | TeacherEligibilityInputController@approveDeduction | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/deductions/{id}/confirm` | TeacherEligibilityInputController@confirmDeduction | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/deductions/{id}/withdraw` | TeacherEligibilityInputController@withdrawDeduction | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/events` | TeacherEligibilityInputController@storeEvent | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/finance/teacher-eligibility/events/{id}` | TeacherEligibilityInputController@updateEvent | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/events/{id}/approve` | TeacherEligibilityInputController@approveEvent | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/events/{id}/withdraw` | TeacherEligibilityInputController@withdrawEvent | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/teacher-eligibility/inputs` | TeacherEligibilityInputController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/finance/teacher-eligibility/salary-profiles` | TeacherEligibilityInputController@storeSalaryProfile | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| GET|HEAD | `api/v1/finance/teacher-payroll` | FinanceController@teacherPayroll | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |

## fix-db

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/fix-db` | Closure |  |

## github

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/github/issues` | GitHubIssueController@index | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequirePasswordChange |

## health

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/health` | Closure |  |
| GET|HEAD | `api/v1/health` | Closure |  |
| GET|HEAD | `api/v1/health/detailed` | Closure |  |

## internal

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/internal/opcache-reset` | Closure |  |

## invoices

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/invoices` | BillingController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/invoices` | BillingController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/invoices/export` | ExportController@invoices | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/invoices/{invoice}/exception-void` | BillingController@exceptionVoidInvoice | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/invoices/{invoice}/payments` | BillingController@recordPayment | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/invoices/{invoice}/slip-data` | BillingController@slipData | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/invoices/{invoice}/void` | BillingController@voidInvoice | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## learning-record-feedbacks

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/learning-record-feedbacks` | LearningRecordFeedbackController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/learning-record-feedbacks/analytics` | LearningRecordFeedbackController@analytics | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-record-feedbacks/{feedback}/read` | LearningRecordFeedbackController@markRead | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/learning-record-feedbacks/{feedback}/replies` | LearningRecordFeedbackController@replies | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-record-feedbacks/{feedback}/reply` | LearningRecordFeedbackController@staffReply | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## learning-record-teacher-comments

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/learning-record-teacher-comments/{comment}/read` | LearningRecordTeacherCommentController@markRead | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## learning-records

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/learning-records` | LearningRecordController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records` | LearningRecordController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/backdoor-approve` | LearningRecordController@backdoorApprove | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/batch-approve` | LearningRecordController@batchApprove | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/batch-reject` | LearningRecordController@batchReject | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/batch-request-changes` | LearningRecordController@batchRequestChanges | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/bulk-backdoor-approve` | LearningRecordController@bulkBackdoorApprove | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/ensure-past` | LearningRecordController@ensurePastRecords | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/learning-records/latest-approved-summary` | LearningRecordController@latestApprovedSummary | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/reschedule-session` | LearningRecordController@rescheduleSession | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/{learningRecord}` | LearningRecordController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/learning-records/{learningRecord}` | LearningRecordController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/learning-records/{learningRecord}` | LearningRecordController@destroy | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/{learningRecord}/approve` | LearningRecordController@approve | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/{learningRecord}/reject` | LearningRecordController@reject | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/{learningRecord}/request-changes` | LearningRecordController@requestChanges | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/learning-records/{learningRecord}/rollback-approval` | LearningRecordController@rollbackApproval | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PATCH | `api/v1/learning-records/{learningRecord}/teacher` | LearningRecordController@updateTeacher | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/learning-records/{learningRecord}/teacher-comment` | LearningRecordTeacherCommentController@upsert | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## line

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/line/settings` | LineWebhookController@saveSettings | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/line/status` | LineWebhookController@status | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/line/webhook` | LineWebhookController@handleDomainBased |  |
| POST | `api/v1/line/webhook/{campusId}` | LineWebhookController@handle |  |

## me

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/me` | AuthController@me |  |
| PUT | `api/v1/me` | AuthController@updateMe |  |
| POST | `api/v1/me/avatar` | AuthController@uploadAvatar |  |
| GET|HEAD | `api/v1/me/awaiting-reply-count` | LearningRecordFeedbackController@awaitingReplyCount | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/me/learning-pending-summary` | LearningRecordController@teacherPendingBadgeSummary | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/me/learning-progress-summary` | LearningRecordController@teacherLearningProgressSummary | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/me/notification-preferences` | AuthController@notificationPreferences |  |
| PUT | `api/v1/me/notification-preferences` | AuthController@updateNotificationPreferences |  |
| POST | `api/v1/me/pin/lock` | PinVerificationController@lock |  |
| POST | `api/v1/me/pin/reset` | PinVerificationController@reset | App\Http\Middleware\ThrottleRequestsByIp:10,10 |
| POST | `api/v1/me/pin/set` | PinVerificationController@set | App\Http\Middleware\ThrottleRequestsByIp:10,10 |
| GET|HEAD | `api/v1/me/pin/status` | PinVerificationController@status |  |
| POST | `api/v1/me/pin/verify` | PinVerificationController@verify | App\Http\Middleware\ThrottleRequestsByIp:20,10 |
| GET|HEAD | `api/v1/me/security` | AuthController@security |  |
| POST | `api/v1/me/security/logout-others` | AuthController@logoutOtherSessions |  |
| GET|HEAD | `api/v1/me/unread-feedback-count` | LearningRecordFeedbackController@unreadCount | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## notifications

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/notifications` | NotificationController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/notifications/read-all` | NotificationController@markAllRead | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/notifications/sync` | NotificationController@sync | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/notifications/unread-count` | NotificationController@unreadCount | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/notifications/{notificationId}/read` | NotificationController@markRead | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/notifications/{notificationId}/tuition-paid` | NotificationController@markTuitionPaid | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## parent

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/parent/billing-history` | ParentPortalController@billingHistory |  |
| GET|HEAD | `api/v1/parent/dashboard` | ParentPortalController@dashboard |  |
| POST | `api/v1/parent/events` | ParentPortalController@recordParentEvent |  |
| POST | `api/v1/parent/feedback` | ParentFeedbackController@store | App\Http\Middleware\ThrottleRequestsByIp:20,1 |
| GET|HEAD | `api/v1/parent/learning-records/{learningRecord}/feedback` | LearningRecordFeedbackController@parentShow |  |
| PUT | `api/v1/parent/learning-records/{learningRecord}/feedback` | LearningRecordFeedbackController@parentUpsert | App\Http\Middleware\ThrottleRequestsByIp:20,1 |
| POST | `api/v1/parent/learning-records/{learningRecord}/feedback/reply` | LearningRecordFeedbackController@parentReply | App\Http\Middleware\ThrottleRequestsByIp:20,1 |
| POST | `api/v1/parent/login` | ParentPortalController@login | App\Http\Middleware\ThrottleRequestsByIp:5,10 |
| POST | `api/v1/parent/login-line` | ParentPortalController@loginWithLine | App\Http\Middleware\ThrottleRequestsByIp:30,10 |
| GET|HEAD | `api/v1/parent/notification-preferences` | ParentPortalController@getNotificationPreferences |  |
| PUT | `api/v1/parent/notification-preferences` | ParentPortalController@setNotificationPreferences | App\Http\Middleware\ThrottleRequestsByIp:20,1 |
| GET|HEAD | `api/v1/parent/payment-message/{studentId}` | ParentPortalController@paymentMessage | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/parent/resolve-liff` | ParentPortalController@resolveLiff |  |
| POST | `api/v1/parent/sessions/{sessionId}/leave` | ParentPortalController@requestLeave |  |
| POST | `api/v1/parent/switch-student` | ParentPortalController@switchStudent |  |
| GET|HEAD | `api/v1/parent/system-trust-summary` | SystemTrustController@parentSummary |  |

## parent-feedback

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/parent-feedback` | ParentFeedbackController@index | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/parent-feedback/for-teacher` | ParentFeedbackController@forTeacher | App\Http\Middleware\RequireRole:teacher,director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/parent-feedback/unread-count` | ParentFeedbackController@unreadCount | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/parent-feedback/{id}/mark-read` | ParentFeedbackController@markRead | App\Http\Middleware\RequireSuperAdmin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/parent-feedback/{id}/read` | ParentFeedbackController@markReadByTeacher | App\Http\Middleware\RequireRole:teacher,director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/parent-feedback/{id}/replies` | ParentFeedbackController@replies | App\Http\Middleware\RequireRole:teacher,director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/parent-feedback/{id}/reply` | ParentFeedbackController@reply | App\Http\Middleware\RequireRole:teacher,director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## part-time-rate-cards

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/part-time-rate-cards` | PartTimeRateCardController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| POST | `api/v1/part-time-rate-cards` | PartTimeRateCardController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| PUT | `api/v1/part-time-rate-cards/{id}` | PartTimeRateCardController@update | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |
| DELETE | `api/v1/part-time-rate-cards/{id}` | PartTimeRateCardController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequirePin |

## pending-swipes

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/pending-swipes` | PendingSwipeController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/pending-swipes/{pendingSwipe}` | PendingSwipeController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/pending-swipes/{pendingSwipe}/assign-student` | PendingSwipeController@assignStudent | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/pending-swipes/{pendingSwipe}/match` | PendingSwipeController@match | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## profiles

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/profiles` | ProfileController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/profiles` | ProfileController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/profiles/bulk-teachers` | ProfileController@bulkTeachers | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/profiles/{id}` | ProfileController@update | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/profiles/{id}` | ProfileController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/profiles/{id}/reset-password` | ProfileController@resetPassword | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## recent-unknown-rfids

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/recent-unknown-rfids` | PendingSwipeController@recentUnknownRfids | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## reports

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/reports/teacher-learning-fill-rates` | ClassSessionController@directorTeacherLearningFillRates | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## rooms

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/rooms` | RoomController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/rooms` | RoomController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/rooms/{room}` | RoomController@update | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/rooms/{room}` | RoomController@destroy | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## schedule-audit

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/schedule-audit` | ScheduleAuditController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## schedule-discrepancies

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/schedule-discrepancies` | ScheduleDiscrepancyController@store | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/schedule-discrepancies` | ScheduleDiscrepancyController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/schedule-discrepancies/active-for-session` | ScheduleDiscrepancyController@activeForSession | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/schedule-discrepancies/my` | ScheduleDiscrepancyController@mine | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/schedule-discrepancies/summary` | ScheduleDiscrepancyController@summary | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/schedule-discrepancies/{id}` | ScheduleDiscrepancyController@updateStatus | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/schedule-discrepancies/{id}/withdraw` | ScheduleDiscrepancyController@withdraw | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## schedule-import

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/schedule-import/preview` | ScheduleImportController@preview | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## student-identities

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/student-identities` | StudentIdentityController@index | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/student-identities/link` | StudentIdentityController@link | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/student-identities/members/{studentId}` | StudentIdentityController@unlink | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/student-identities/{groupId}/access` | StudentIdentityController@access | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/student-identities/{groupId}/audit` | StudentIdentityController@audit | App\Http\Middleware\RequireRole:director,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## students

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/students` | StudentController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/students` | StudentController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/students/bulk-delete` | StudentController@bulkDestroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/students/export` | ExportController@students | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/students/import` | ImportController@students | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/students/{student}` | StudentController@show | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/students/{student}` | StudentController@update | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/students/{student}` | StudentController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/students/{student}/active-courses` | StudentController@activeCourses | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/students/{student}/bind-card` | StudentController@bindCard | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/students/{student}/line-bindings` | StudentController@lineBindings | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/students/{student}/line-bindings/{binding}` | StudentController@removeLineBinding | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## subjects

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/subjects` | SubjectController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/subjects` | SubjectController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/subjects/{id}` | SubjectController@update | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/subjects/{id}` | SubjectController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## subjects-public

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/subjects-public` | SubjectController@indexPublic |  |

## substitutes

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/substitutes/recent` | SubstituteController@recent | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## system

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/system/settings/substitute-undo` | SubstituteController@getUndoSetting | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| PUT | `api/v1/system/settings/substitute-undo` | SubstituteController@setUndoSetting | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/system/trust-summary` | SystemTrustController@summary | App\Http\Middleware\RequireRole:director,teacher,super_admin, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## teacher-attendance

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/teacher-attendance` | TeacherAttendanceController@index | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| GET|HEAD | `api/v1/teacher-attendance/export` | TeacherAttendanceController@export | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| GET|HEAD | `api/v1/teacher-attendance/export-monthly` | TeacherAttendanceController@exportMonthly | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| GET|HEAD | `api/v1/teacher-attendance/today` | TeacherAttendanceController@today | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/teacher-attendance/unclosed` | TeacherAttendanceController@unclosed | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |
| POST | `api/v1/teacher-attendance/{id}/adjust` | TeacherAttendanceController@adjust | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange, App\Http\Middleware\RequireRole:director,super_admin |

## teacher-leaves

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/teacher-leaves/batch-substitute` | TeacherLeaveController@batchSubstitute | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/teacher-leaves/preview` | TeacherLeaveController@preview | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## teacher_branches

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/teacher_branches` | TeacherBranchController@index | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/teacher_branches` | TeacherBranchController@store | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| DELETE | `api/v1/teacher_branches` | TeacherBranchController@destroy | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## teachers

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/teachers` | Closure | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| GET|HEAD | `api/v1/teachers/{id}/availability` | SubstituteController@availability | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## teaching-logs

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/teaching-logs/missing` | TeachingLogController@missing | App\Http\Middleware\RequireRole:director,teacher, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

## telegram

| Method | URI | Action | Middleware |
|---|---|---|---|
| POST | `api/v1/telegram/webhook/{code}` | TelegramWebhookController@handle |  |

## temp-rfid

| Method | URI | Action | Middleware |
|---|---|---|---|
| GET|HEAD | `api/v1/temp-rfid` | TempRfidController@show | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |
| POST | `api/v1/temp-rfid/consume` | TempRfidController@consume | App\Http\Middleware\RequireRole:director, App\Http\Middleware\RequireCampus, App\Http\Middleware\RequirePasswordChange |

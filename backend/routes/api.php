<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LearningRecordController;
use App\Http\Controllers\LearningRecordFeedbackController;
use App\Http\Controllers\LearningRecordTeacherCommentController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\PartTimeRateCardController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\PendingSwipeController;
use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\CampusController;
use App\Http\Controllers\AdminCampusController;
use App\Http\Controllers\AdminRoutingRuleController;
use App\Http\Controllers\ExceptionWorkflowController;
use App\Http\Controllers\ParentFeedbackController;
use App\Http\Controllers\ParentPortalController;
use App\Http\Controllers\StudentClassController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TeacherBranchController;
use App\Http\Controllers\DirectorAccountController;
use App\Http\Controllers\SwipeRfidController;
use App\Http\Controllers\TempRfidController;
use App\Http\Controllers\ResetDataController;
use App\Http\Controllers\BackfillController;
use App\Http\Controllers\LineWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetRequestController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ClassSessionController;
use App\Http\Controllers\SubstituteController;
use App\Http\Controllers\TeacherLeaveController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\PaymentReportController;
use App\Http\Controllers\ScheduleAuditController;
use App\Http\Controllers\ScheduleDiscrepancyController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AdoptionInsightsController;
use App\Http\Controllers\SystemTrustController;


if (app()->environment('local')) {
    Route::get('/fix-db', function () {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('schedules')) {
                \Illuminate\Support\Facades\Schema::create('schedules', function (\Illuminate\Database\Schema\Blueprint $table) {
                    $table->id();
                    $table->integer('student_id');
                    $table->integer('teacher_id')->nullable();
                    $table->string('subject')->nullable();
                    $table->integer('day_of_week');
                    $table->string('start_time');
                    $table->string('end_time');
                    $table->decimal('duration_hours', 5, 2)->nullable();
                    $table->string('class_type')->nullable();
                    $table->string('status');
                    $table->string('type');
                    $table->integer('deduction')->default(1);
                    $table->integer('branch_id');
                    $table->date('schedule_date')->nullable();
                    $table->integer('student_course_id')->nullable();
                    $table->integer('original_schedule_id')->nullable();
                    $table->timestamps();
                });
            }

            \Illuminate\Support\Facades\DB::statement("ALTER TABLE User MODIFY PSW VARCHAR(255)");
            $columns = \Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM User");
            return response()->json([
                'message' => 'DB fixed successfully (PSW updated, schedules table checked/created)',
                'columns' => $columns,
                'schedules_exists' => \Illuminate\Support\Facades\Schema::hasTable('schedules')
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    });
}

Route::get('/health', fn() => response()->json(['ok' => true, 'message' => 'Laravel routing OK']));

// FR-003: Post-deploy OPcache flush — protected by DEPLOY_SECRET, localhost-only preferred.
// Call: curl -s -X POST http://localhost/api/internal/opcache-reset -H "X-Deploy-Secret: <token>"
Route::post('/internal/opcache-reset', function (\Illuminate\Http\Request $req) {
    $secret = config('app.deploy_secret', '');
    if (empty($secret) || $req->header('X-Deploy-Secret') !== $secret) {
        return response()->json(['error' => 'Forbidden'], 403);
    }
    $cleared = false;
    if (function_exists('opcache_reset')) {
        $cleared = opcache_reset();
    }
    return response()->json(['ok' => true, 'opcache_cleared' => $cleared]);
});

Route::prefix('v1')->group(function () {
    // ── Auth (public) ───────────────────────────────────────────────
    Route::post('auth/login', [AuthController::class, 'login']);
    // SEC-003: 5 req/IP/60 min — prevents email bombing & account enumeration.
    Route::post('auth/forgot-password', [PasswordResetRequestController::class, 'store'])
        ->middleware('throttle:5,60');

    // ── LINE Webhook (public, verified by campus channel secret) ──
    // Domain-based URL (no id): match Host to Campus.URL — must be registered before the {campusId} route
    Route::post('line/webhook', [LineWebhookController::class, 'handleDomainBased']);
    Route::post('line/webhook/{campusId}', [LineWebhookController::class, 'handle'])->where('campusId', '[0-9]+');
    Route::post('telegram/webhook/{code}', [TelegramWebhookController::class, 'handle']);

    // ── Parent Portal: LIFF & LINE-based login ─────────────────────────
    Route::get('parent/resolve-liff', [ParentPortalController::class, 'resolveLiff']);
    Route::post('parent/login-line', [ParentPortalController::class, 'loginWithLine'])
        ->middleware('throttle:30,10');

    // ── Public Branch Data (No auth required) ───────────────────────
    Route::get('branches', [CampusController::class, 'listPublic']);
    Route::get('subjects-public', [SubjectController::class, 'indexPublic']);

    // ── Health (public, minimal) ─────────────────────────────────────
    // SEC-F3: Only status+timestamp exposed publicly. Operational metrics
    // (perf_flags, log_pipeline, sentry details) are on /health/detailed (auth required).
    Route::get('health', fn() => response()->json([
        'status'    => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]));

    // ── RFID 刷卡 (public，供讀卡機呼叫) ─────────────────────────────
    // SEC-006: 30 req/IP/1 min — blocks RFID brute-force enumeration.
    Route::post('swipe-rfid', [SwipeRfidController::class, 'swipe'])
        ->middleware('throttle:30,1');
    // SEC-002: 10 req/IP/10 min — prevents bulk account creation.
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:10,10');

    // ── Director self-registration (public) ───────────────────────────────
    // SEC-002: same throttle as auth/register.
    Route::post('directors/register', [DirectorAccountController::class, 'register'])
        ->middleware('throttle:10,10');

    // ── Current user profile (any authenticated user: director, teacher, super_admin) ──
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateMe']);
    Route::post('me/avatar', [AuthController::class, 'uploadAvatar']);
    Route::get('me/notification-preferences', [AuthController::class, 'notificationPreferences']);
    Route::put('me/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    Route::get('me/security', [AuthController::class, 'security']);
    Route::post('me/security/logout-others', [AuthController::class, 'logoutOtherSessions']);

    // ── 敏感頁 PIN 二次驗證 (#769) — 任何已登入使用者，controller 自檢 auth_user ──
    // per-account 鎖定在 controller（DB）；per-IP 節流由此處 throttle 提供（OWASP 雙桶）。
    Route::get('me/pin/status', [\App\Http\Controllers\PinVerificationController::class, 'status']);
    Route::post('me/pin/set', [\App\Http\Controllers\PinVerificationController::class, 'set'])->middleware('throttle:10,10');
    Route::post('me/pin/verify', [\App\Http\Controllers\PinVerificationController::class, 'verify'])->middleware('throttle:20,10');
    Route::post('me/pin/reset', [\App\Http\Controllers\PinVerificationController::class, 'reset'])->middleware('throttle:10,10');
    Route::post('me/pin/lock', [\App\Http\Controllers\PinVerificationController::class, 'lock']);

    // ── Health Detailed (auth required) ─────────────────────────────
    // SEC-F3: Operational metrics behind auth — not exposed to unauthenticated requests.
    Route::get('health/detailed', function () {
        // Require any authenticated user — AttachAuthUser sets auth_user for valid tokens.
        if (!request()->attributes->has("auth_user") || !request()->attributes->get("auth_user")) {
            return response()->json(["message" => "Unauthenticated"], 401);
        }
        $logDir = storage_path('logs');
        $tmpfsMount = '/var/log/alltrue-tmpfs';
        $tmpfsActive = is_dir($tmpfsMount) && @disk_total_space($tmpfsMount) > 0;
        $logPipeline = [
            'driver'        => config('logging.channels.stack.channels.0', 'daily'),
            'tmpfs_active'  => $tmpfsActive,
            'logs_dir_writable' => is_writable($logDir),
        ];
        if ($tmpfsActive) {
            $total = @disk_total_space($tmpfsMount) ?: 0;
            $free  = @disk_free_space($tmpfsMount) ?: 0;
            $used  = $total - $free;
            $logPipeline['tmpfs_total_mb']  = round($total / 1048576, 1);
            $logPipeline['tmpfs_used_mb']   = round($used / 1048576, 1);
            $logPipeline['tmpfs_usage_pct'] = $total > 0 ? round($used / $total * 100, 1) : 0;
            $logPipeline['tmpfs_healthy']   = $logPipeline['tmpfs_usage_pct'] < 80;
        }
        $sentryDsn = config('sentry.dsn') ?: env('SENTRY_LARAVEL_DSN');
        return response()->json([
            'status'    => 'ok',
            'timestamp' => now()->toIso8601String(),
            'perf_flags' => [
                'throttle_notif_sync'    => config('perfflags.throttle_notification_sync'),
                'lr_default_per_page'    => config('perfflags.learning_records_default_per_page'),
                'lr_max_per_page'        => config('perfflags.learning_records_max_per_page'),
                'lr_default_window_days' => config('perfflags.learning_records_default_window_days'),
            ],
            'log_pipeline' => $logPipeline,
            'sentry' => ['configured' => !empty($sentryDsn), 'sdk_bound' => app()->bound('sentry')],
            'security' => ['debug_mode' => config('app.debug')],
        ]);
    });

    // ── Engagement / Gamification ──
    // SEC-AUDIT-007/008 (#974): these routes (incl. the award-xp / toggle-visibility
    // mutators) previously had no role gate and AttachAuthUser does not fail closed,
    // so an unauthenticated request reached the controller (null-deref) and any caller
    // could mutate. Engagement is a teacher-workbench feature → require an authenticated
    // director/teacher/super_admin role. No public/parent caller uses these endpoints.
    Route::middleware(['role:director,teacher,super_admin'])->group(function () {
        Route::get('engagement/rank-thresholds', [\App\Http\Controllers\EngagementController::class, 'rankThresholds']);
        Route::get('engagement/my-progress', [\App\Http\Controllers\EngagementController::class, 'myProgress']);
        Route::get('engagement/xp-history', [\App\Http\Controllers\EngagementController::class, 'xpHistory']);
        Route::post('engagement/award-xp', [\App\Http\Controllers\EngagementController::class, 'awardXp']);
        Route::get('engagement/event-types', [\App\Http\Controllers\EngagementController::class, 'eventTypes']);
        Route::get('engagement/badges', [\App\Http\Controllers\EngagementController::class, 'badges']);
        Route::post('engagement/badges/{key}/toggle-visibility', [\App\Http\Controllers\EngagementController::class, 'toggleBadgeVisibility']);
        Route::post('engagement/ranks-for', [\App\Http\Controllers\EngagementController::class, 'ranksFor']);
    });

    Route::post('attendance/swipe', [AttendanceController::class, 'swipe'])
        ->middleware('api_key');

    // ── Super Admin only: 清空課程／學生／老師（保留 super_admin）────────────────
    Route::post('admin/reset-data', ResetDataController::class)->middleware(['role:director', 'require_password_change']);

    // ── Super Admin: 分校管理 CRUD ───────────────────────────────────────────────
    Route::middleware(['role:super_admin'])->group(function () {
        Route::get('admin/campuses', [AdminCampusController::class, 'index']);
        Route::post('admin/campuses', [AdminCampusController::class, 'store']);
        Route::put('admin/campuses/{id}', [AdminCampusController::class, 'update'])->whereNumber('id');
        Route::delete('admin/campuses/{id}', [AdminCampusController::class, 'destroy'])->whereNumber('id');

        // CRDE Phase 4 — immutable versioned routing store (create-version-only, never overwrite).
        Route::get('admin/routing-rules', [AdminRoutingRuleController::class, 'index']);
        Route::post('admin/routing-rules/versions', [AdminRoutingRuleController::class, 'store']);
        Route::post('admin/routing-rules/versions/{version}/publish', [AdminRoutingRuleController::class, 'publish'])->whereNumber('version');
        Route::get('admin/routing-rules/check', [AdminRoutingRuleController::class, 'check']);
    });

    Route::middleware(['role:director', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::post('students/bulk-delete', [StudentController::class, 'bulkDestroy']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::put('students/{student}', [StudentController::class, 'update']);
        Route::delete('students/{student}', [StudentController::class, 'destroy']);
        Route::post('students/{student}/bind-card', [StudentController::class, 'bindCard']);
        Route::get('students/{student}/line-bindings', [StudentController::class, 'lineBindings']);
        Route::delete('students/{student}/line-bindings/{binding}', [StudentController::class, 'removeLineBinding']);

        Route::post('students/import', [ImportController::class, 'students']);
        Route::get('students/export', [ExportController::class, 'students']);

        Route::post('student-classes/import', [ImportController::class, 'studentClasses']);
        Route::get('student-classes/export', [ExportController::class, 'studentClasses']);
        Route::post('enrollments', [EnrollmentController::class, 'store']);

        Route::get('invoices', [BillingController::class, 'index']);
        Route::post('invoices', [BillingController::class, 'store']);
        Route::get('invoices/{invoice}/slip-data', [BillingController::class, 'slipData']);
        Route::post('invoices/{invoice}/payments', [BillingController::class, 'recordPayment']);
        Route::post('invoices/{invoice}/void', [BillingController::class, 'voidInvoice']);
        Route::post('invoices/{invoice}/exception-void', [BillingController::class, 'exceptionVoidInvoice']);
        Route::get('invoices/export', [ExportController::class, 'invoices']);

        Route::post('learning-records/{learningRecord}/approve', [LearningRecordController::class, 'approve']);
        Route::patch('learning-records/{learningRecord}/teacher', [LearningRecordController::class, 'updateTeacher']);
        Route::post('learning-records/{learningRecord}/rollback-approval', [LearningRecordController::class, 'rollbackApproval']);
        Route::post('learning-records/{learningRecord}/request-changes', [LearningRecordController::class, 'requestChanges']);
        Route::post('learning-records/{learningRecord}/reject', [LearningRecordController::class, 'reject']);
        Route::post('learning-records/backdoor-approve', [LearningRecordController::class, 'backdoorApprove']);
        Route::post('learning-records/bulk-backdoor-approve', [LearningRecordController::class, 'bulkBackdoorApprove']);
        Route::post('learning-records/batch-approve', [LearningRecordController::class, 'batchApprove']);
        Route::post('learning-records/batch-reject', [LearningRecordController::class, 'batchReject']);
        Route::post('learning-records/batch-request-changes', [LearningRecordController::class, 'batchRequestChanges']);
        Route::post('learning-records/reschedule-session', [LearningRecordController::class, 'rescheduleSession']);
        Route::post('learning-records/ensure-past', [LearningRecordController::class, 'ensurePastRecords']);

        Route::get('finance/summary', [FinanceController::class, 'summary']);
        Route::get('finance/revenue', [FinanceController::class, 'revenue']);
        Route::get('finance/outstanding', [FinanceController::class, 'outstanding']);
        Route::get('finance/teacher-payroll', [FinanceController::class, 'teacherPayroll'])->middleware('require_pin'); // #769 Phase C：薪資敏感
        Route::get('finance/ar-aging', [FinanceController::class, 'arAging']);
        Route::get('finance/gl-export', [FinanceController::class, 'glExport']);
        Route::get('finance/consolidated-summary', [FinanceController::class, 'consolidatedSummary']);
        Route::get('finance/periods', [\App\Http\Controllers\AccountingPeriodController::class, 'index']);
        Route::post('finance/periods/close', [\App\Http\Controllers\AccountingPeriodController::class, 'close']);
        Route::post('finance/periods/reopen', [\App\Http\Controllers\AccountingPeriodController::class, 'reopen']);

        // ── Dunning (#400) ──
        Route::get('dunning/rules', [\App\Http\Controllers\DunningController::class, 'rules']);
        Route::get('dunning/history', [\App\Http\Controllers\DunningController::class, 'history']);
        Route::post('dunning/trigger', [\App\Http\Controllers\DunningController::class, 'trigger']);

        // ── Bank Reconciliation (#397) ──
        Route::get('bank-reconciliation', [\App\Http\Controllers\BankReconciliationController::class, 'index']);
        Route::post('bank-reconciliation/import', [\App\Http\Controllers\BankReconciliationController::class, 'importCsv']);
        Route::get('bank-reconciliation/{id}/suggest', [\App\Http\Controllers\BankReconciliationController::class, 'suggestMatches']);
        Route::post('bank-reconciliation/{id}/reconcile', [\App\Http\Controllers\BankReconciliationController::class, 'reconcile']);

        // finance/subject-units is intentionally registered in the role:director,teacher group below
        // so that teachers can also call this endpoint for their own hours view (FR-008).
        // #769 Phase C：當月學收（tuition-report 頁）為敏感 API。
        Route::get('finance/branch-monthly-tuition', [FinanceController::class, 'branchMonthlyTuition'])->middleware('require_pin');
        Route::get('finance/branch-monthly-tuition/export', [FinanceController::class, 'branchMonthlyTuitionExport'])->middleware('require_pin');
        Route::get('finance/duplicate-courses', [FinanceController::class, 'duplicateCourses']);

        // #769 Phase C：兼職薪資（parttime-payroll 頁）+ 費率卡為敏感 API，掛 require_pin（soft）。
        Route::get('finance/parttime-payroll', [FinanceController::class, 'parttimePayroll'])->middleware('require_pin');
        Route::get('finance/parttime-payroll/rules', [FinanceController::class, 'parttimePayrollRules'])->middleware('require_pin');
        Route::put('finance/parttime-payroll/rules', [FinanceController::class, 'parttimePayrollRulesUpdate'])->middleware('require_pin');
        Route::get('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRules'])->middleware('require_pin');
        Route::put('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRulesUpdate'])->middleware('require_pin');
        Route::delete('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRulesDelete'])->middleware('require_pin');
        // #767: part-time teacher rate cards (class-size-based rates) — 薪資費率，同屬敏感（#769 Phase C）。
        Route::get('part-time-rate-cards', [PartTimeRateCardController::class, 'index'])->middleware('require_pin');
        Route::post('part-time-rate-cards', [PartTimeRateCardController::class, 'store'])->middleware('require_pin');
        Route::put('part-time-rate-cards/{id}', [PartTimeRateCardController::class, 'update'])->whereNumber('id')->middleware('require_pin');
        Route::delete('part-time-rate-cards/{id}', [PartTimeRateCardController::class, 'destroy'])->whereNumber('id')->middleware('require_pin');
        Route::get('finance/parttime-payroll/export', [FinanceController::class, 'parttimePayrollExport'])->middleware('require_pin');
        Route::get('finance/parttime-payroll/{teacherId}/sessions', [FinanceController::class, 'parttimePayrollSessions'])->whereNumber('teacherId')->middleware('require_pin');
        Route::post('finance/parttime-payroll/lock', [FinanceController::class, 'parttimePayrollLock'])->middleware('require_pin');
        Route::post('finance/parttime-payroll/reopen', [FinanceController::class, 'parttimePayrollReopen'])->middleware('require_pin');
        Route::post('backfill/register-subject-units', [BackfillController::class, 'registerSubjectUnits']);

        Route::get('alerts/tuition', [AlertController::class, 'tuition']);
        Route::get('alerts/tuition-slip/{studentClassId}', [AlertController::class, 'tuitionSlipData']);

        // ── Payment Reports (學收核銷) ──────────────────────────────
        Route::get('accounting/ledger', [AccountingController::class, 'ledger']);
        // #769 Phase C：帳務中心（tuition-collect 頁）核銷/收款資料為敏感 API。
        Route::get('accounting/settled-courses', [AccountingController::class, 'settledCourses'])->middleware('require_pin');
        Route::get('accounting/payments', [AccountingController::class, 'payments'])->middleware('require_pin');
        Route::get('accounting/payments/export', [AccountingController::class, 'paymentsExport'])->middleware('require_pin');
        Route::post('payment-reports/director-record', [PaymentReportController::class, 'directorRecord']);
        Route::get('payment-reports', [PaymentReportController::class, 'index']);
        Route::put('payment-reports/{id}/confirm', [PaymentReportController::class, 'confirm']);
        Route::put('payment-reports/{id}/reject', [PaymentReportController::class, 'reject']);
        Route::put('payment-reports/{id}/void', [PaymentReportController::class, 'void']);
        Route::get('payment-reports/{id}/receipt', [PaymentReportController::class, 'receipt']);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/sync', [NotificationController::class, 'sync']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/{notificationId}/tuition-paid', [NotificationController::class, 'markTuitionPaid']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);

        Route::get('exception-workflows', [ExceptionWorkflowController::class, 'index']);
        Route::get('exception-workflows/{id}', [ExceptionWorkflowController::class, 'show'])->whereNumber('id');
        Route::post('exception-workflows/{id}/generate-candidates', [ExceptionWorkflowController::class, 'generateCandidates'])->whereNumber('id');
        Route::post('exception-workflows/{id}/confirm-candidate', [ExceptionWorkflowController::class, 'confirmCandidate'])->whereNumber('id');
        Route::post('exception-workflows/{id}/waive', [ExceptionWorkflowController::class, 'waive'])->whereNumber('id');

        Route::get('temp-rfid', [TempRfidController::class, 'show']);
        Route::post('temp-rfid/consume', [TempRfidController::class, 'consume']);

        Route::get('recent-unknown-rfids', [PendingSwipeController::class, 'recentUnknownRfids']);
        Route::get('pending-swipes', [PendingSwipeController::class, 'index']);
        Route::post('pending-swipes/{pendingSwipe}/assign-student', [PendingSwipeController::class, 'assignStudent']);
        Route::post('pending-swipes/{pendingSwipe}/match', [PendingSwipeController::class, 'match']);
        Route::delete('pending-swipes/{pendingSwipe}', [PendingSwipeController::class, 'destroy']);

        Route::get('campuses', [CampusController::class, 'index']);
        Route::get('directors', [DirectorAccountController::class, 'index']);
        Route::get('directors/pending', [DirectorAccountController::class, 'pending']);
        Route::post('directors/{id}/approve', [DirectorAccountController::class, 'approve']);
        Route::post('directors/{id}/reject', [DirectorAccountController::class, 'reject']);
        Route::post('directors/{id}/reset-password', [DirectorAccountController::class, 'resetPassword']);
        Route::put('directors/{id}/campuses', [DirectorAccountController::class, 'updateCampuses'])->whereNumber('id');
        Route::delete('directors/{id}', [DirectorAccountController::class, 'destroy'])->whereNumber('id');
        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::post('api-clients', [ApiClientController::class, 'store']);
        Route::post('api-clients/{apiClient}/revoke', [ApiClientController::class, 'revoke']);

        Route::post('subjects', [SubjectController::class, 'store']);
        Route::put('subjects/{id}', [SubjectController::class, 'update']);
        Route::delete('subjects/{id}', [SubjectController::class, 'destroy']);
    });

    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        // #768 教學日誌漏交追蹤（主任看本校各老師、老師看自己）。
        Route::get('teaching-logs/missing', [\App\Http\Controllers\TeachingLogController::class, 'missing']);
        Route::get('students', [StudentController::class, 'index']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::get('students/{student}/active-courses', [StudentController::class, 'activeCourses']);
        Route::get('profiles', [ProfileController::class, 'index']);
        // Alias: /api/v1/teachers → profiles filtered to type=T
        Route::get('teachers', function (\Illuminate\Http\Request $req) {
            $req->merge(['role' => 'teacher']);
            return app(\App\Http\Controllers\ProfileController::class)->index($req);
        });

        Route::get('subjects', [SubjectController::class, 'index']);

        Route::get('student-classes/session-dates', [StudentClassController::class, 'sessionDates']);
        Route::post('student-classes/session-dates', [StudentClassController::class, 'sessionDates']);
        Route::post('student-classes/sync', [StudentClassController::class, 'sync']);
        Route::get('student-classes', [StudentClassController::class, 'index']);
        Route::post('student-classes', [StudentClassController::class, 'store']);
        Route::get('student-classes/{studentClass}', [StudentClassController::class, 'show']);
        Route::put('student-classes/{studentClass}', [StudentClassController::class, 'update']);
        Route::post('student-classes/{studentClass}/confirm-payment', [StudentClassController::class, 'confirmPayment']);
        Route::post('student-classes/{studentClass}/renewal-preview', [StudentClassController::class, 'renewalPreview']);
        Route::post('student-classes/{studentClass}/renewal-confirm', [StudentClassController::class, 'renewalConfirm']);
        Route::post('student-classes/{studentClass}/purchase-batch', [StudentClassController::class, 'purchaseBatch']);
        Route::post('student-classes/{studentClass}/renew-monthly', [StudentClassController::class, 'renewMonthly']);
        Route::get('student-classes/{studentClass}/invoices', [StudentClassController::class, 'invoices']);
        Route::post('student-classes/{studentClass}/add-session', [StudentClassController::class, 'addSession']);
        Route::post('student-classes/{studentClass}/add-session/check', [StudentClassController::class, 'checkAddSession']);
        Route::post('student-classes/{studentClass}/pause', [StudentClassController::class, 'togglePause']);
        Route::delete('student-classes/{studentClass}', [StudentClassController::class, 'destroy']);

        // Course packages (multi-subject shared session pool)
        Route::get('course-packages', [\App\Http\Controllers\CoursePackageController::class, 'index']);
        Route::post('course-packages/create-multi-subject', [\App\Http\Controllers\CoursePackageController::class, 'createMultiSubject']);
        Route::get('course-packages/{id}', [\App\Http\Controllers\CoursePackageController::class, 'show']);
        Route::put('course-packages/{id}', [\App\Http\Controllers\CoursePackageController::class, 'update']);
        Route::post('course-packages/{id}/recompute', [\App\Http\Controllers\CoursePackageController::class, 'recompute']);
        Route::post('course-packages/{id}/rebuild-ledger', [\App\Http\Controllers\CoursePackageController::class, 'rebuildLedger']);
        Route::post('course-packages/{id}/bind-courses', [\App\Http\Controllers\CoursePackageController::class, 'bindCourses']);

        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/ended-sessions', [AttendanceController::class, 'endedSessions']);
        Route::post('attendance', [AttendanceController::class, 'store']);
        Route::post('attendance/batch-mark', [AttendanceController::class, 'batchMark']);
        Route::patch('attendance/{id}', [AttendanceController::class, 'update'])->middleware('role:director,super_admin')->whereNumber('id');
        Route::delete('attendance/{id}', [AttendanceController::class, 'destroy'])->middleware('role:director,super_admin')->whereNumber('id');
        Route::post('attendance/{id}/convert-to-attended', [AttendanceController::class, 'convertToAttended'])->middleware('role:director,super_admin')->whereNumber('id');

        // ── Teacher Attendance (teacher-attendance-v1) ────────────────────
        Route::get('teacher-attendance/today', [\App\Http\Controllers\TeacherAttendanceController::class, 'today']);
        Route::get('teacher-attendance/unclosed', [\App\Http\Controllers\TeacherAttendanceController::class, 'unclosed'])->middleware('role:director,super_admin');
        Route::get('teacher-attendance/export', [\App\Http\Controllers\TeacherAttendanceController::class, 'export'])->middleware('role:director,super_admin');
        Route::get('teacher-attendance/export-monthly', [\App\Http\Controllers\TeacherAttendanceController::class, 'exportMonthly'])->middleware('role:director,super_admin');
        Route::get('teacher-attendance', [\App\Http\Controllers\TeacherAttendanceController::class, 'index'])->middleware('role:director,super_admin');
        Route::post('teacher-attendance/{id}/adjust', [\App\Http\Controllers\TeacherAttendanceController::class, 'adjust'])->middleware('role:director,super_admin')->whereNumber('id');
        Route::get('finance/subject-units', [FinanceController::class, 'subjectUnits']);

        Route::get('learning-records', [LearningRecordController::class, 'index']);
        Route::get('learning-records/latest-approved-summary', [LearningRecordController::class, 'latestApprovedSummary']);
        Route::post('learning-records', [LearningRecordController::class, 'store']);
        Route::post('learning-records/{learningRecord}', [LearningRecordController::class, 'update']);
        Route::put('learning-records/{learningRecord}', [LearningRecordController::class, 'update']);
        Route::delete('learning-records/{learningRecord}', [LearningRecordController::class, 'destroy']);
        Route::put('learning-records/{learningRecord}/teacher-comment', [LearningRecordTeacherCommentController::class, 'upsert']);
        Route::post('learning-record-teacher-comments/{comment}/read', [LearningRecordTeacherCommentController::class, 'markRead']);
        Route::get('me/unread-feedback-count', [LearningRecordFeedbackController::class, 'unreadCount']);
        Route::get('me/learning-pending-summary', [LearningRecordController::class, 'teacherPendingBadgeSummary']);
        Route::get('me/learning-progress-summary', [LearningRecordController::class, 'teacherLearningProgressSummary']);
        Route::get('learning-record-feedbacks', [LearningRecordFeedbackController::class, 'index']);
        Route::post('learning-record-feedbacks/{feedback}/read', [LearningRecordFeedbackController::class, 'markRead']);
        Route::get('learning-record-feedbacks/analytics', [LearningRecordFeedbackController::class, 'analytics']);
        // 家長回饋雙向回覆（員工端，沿用本群組 role:teacher,director + require_campus）
        Route::get('learning-record-feedbacks/{feedback}/replies', [LearningRecordFeedbackController::class, 'replies']);
        Route::post('learning-record-feedbacks/{feedback}/reply', [LearningRecordFeedbackController::class, 'staffReply']);
        Route::get('class-sessions/projection', [ClassSessionController::class, 'projection']);
        Route::get('class-sessions', [ClassSessionController::class, 'index']);
        Route::post('class-sessions/batch', [ClassSessionController::class, 'batchStore']);
        // #770 批次排課 CSV 匯入 — 衝突檢查 preview（純讀取）。
        Route::post('schedule-import/preview', [\App\Http\Controllers\ScheduleImportController::class, 'preview']);
        Route::post('class-sessions/ensure-projected', [ClassSessionController::class, 'ensureProjected'])
            ->middleware('role:director,super_admin');
        Route::get('schedule-audit', [ScheduleAuditController::class, 'index']);
        Route::patch('class-sessions/{id}', [ClassSessionController::class, 'update']);
        Route::post('class-sessions/{id}/substitute', [ClassSessionController::class, 'substitute']);
        // PRD 9c058f19 — 代課流程 UX 優化
        Route::post('class-sessions/{id}/substitute/undo', [SubstituteController::class, 'undo']);
        Route::get('teachers/{id}/availability', [SubstituteController::class, 'availability']);
        Route::post('teacher-leaves/preview', [TeacherLeaveController::class, 'preview']);
        Route::post('teacher-leaves/batch-substitute', [TeacherLeaveController::class, 'batchSubstitute']);
        Route::get('substitutes/recent', [SubstituteController::class, 'recent']);
        // Undo 時間窗設定（Gmail Undo Send 模式：5/10/20/30 秒）
        Route::get('system/settings/substitute-undo', [SubstituteController::class, 'getUndoSetting']);
        Route::put('system/settings/substitute-undo', [SubstituteController::class, 'setUndoSetting']);

        Route::get('schedules', [\App\Http\Controllers\ScheduleController::class, 'index']);
        Route::post('schedules', [\App\Http\Controllers\ScheduleController::class, 'store']);
        Route::post('schedules/{schedule}/cancel-makeup', [\App\Http\Controllers\ScheduleController::class, 'cancelMakeup']);
        Route::post('schedules/{schedule}/undo-leave', [\App\Http\Controllers\ScheduleController::class, 'undoLeave']);
        Route::post('schedules/undo-leave-by-session', [\App\Http\Controllers\ScheduleController::class, 'undoLeaveBySession']);
        Route::post('schedules/retro-leave', [\App\Http\Controllers\ScheduleController::class, 'retroLeave']);
        Route::post('schedules/leave-by-session', [\App\Http\Controllers\ScheduleController::class, 'leaveBySession']);
        Route::post('schedules/bulk-leave', [\App\Http\Controllers\ScheduleController::class, 'bulkHolidayLeave']);
        Route::put('schedules/{schedule}', [\App\Http\Controllers\ScheduleController::class, 'update']);
        Route::delete('schedules/{schedule}', [\App\Http\Controllers\ScheduleController::class, 'destroy']);

        // #995: alerts/tuition is intentionally registered ONCE in the director-only
        // group above (least privilege — tuition/financial alerts; only the director
        // dashboard + tuition-collection page call it). The duplicate registration that
        // used to live here (role:director,teacher) silently widened access to teachers
        // and was the last-wins route, so it has been removed. See AlertsTuitionRouteTest.

        // ── Rooms ────────────────────────────────────────────────────
        Route::get('rooms', [RoomController::class, 'index']);
        Route::post('rooms', [RoomController::class, 'store']);
        Route::put('rooms/{room}', [RoomController::class, 'update']);
        Route::delete('rooms/{room}', [RoomController::class, 'destroy']);
    });

    Route::middleware(['role:director,teacher,super_admin', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('adoption/task-tracker', [AdoptionInsightsController::class, 'taskTracker']);
        Route::get('adoption/activity-log', [AdoptionInsightsController::class, 'activityLog']);
        Route::get('adoption/weekly-metrics', [AdoptionInsightsController::class, 'weeklyMetrics']);
        Route::post('adoption/events', [AdoptionInsightsController::class, 'recordEvent']);
        Route::get('system/trust-summary', [SystemTrustController::class, 'summary']);
    });

    Route::middleware(['super_admin', 'require_password_change'])->group(function () {
        Route::get('adoption/cross-branch-metrics', [AdoptionInsightsController::class, 'crossBranchMetrics']);
    });

    // ── Teacher Management (Profiles) ────────────────────────────────
    Route::middleware(['role:director', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('profiles', [ProfileController::class, 'store']);
        Route::post('profiles/bulk-teachers', [ProfileController::class, 'bulkTeachers']);
        Route::post('profiles/{id}/reset-password', [ProfileController::class, 'resetPassword']);
        Route::put('profiles/{id}', [ProfileController::class, 'update']);
        Route::delete('profiles/{id}', [ProfileController::class, 'destroy']);

        Route::get('teacher_branches', [TeacherBranchController::class, 'index']);
        Route::post('teacher_branches', [TeacherBranchController::class, 'store']);
        Route::delete('teacher_branches', [TeacherBranchController::class, 'destroy']);
    });

    // (pay-report public endpoints removed — reconciliation is now director-only)

    // PRD-B: rate-limit parent login to mitigate credential stuffing (5 attempts/10min per IP).
    Route::post('parent/login', [ParentPortalController::class, 'login'])
        ->middleware('throttle:5,10');
    Route::get('parent/dashboard', [ParentPortalController::class, 'dashboard']);
    Route::post('parent/switch-student', [ParentPortalController::class, 'switchStudent']);
    Route::post('parent/sessions/{sessionId}/leave', [ParentPortalController::class, 'requestLeave']);
    Route::post('parent/events', [ParentPortalController::class, 'recordParentEvent']);
    Route::get('parent/notification-preferences', [ParentPortalController::class, 'getNotificationPreferences']);
    Route::put('parent/notification-preferences', [ParentPortalController::class, 'setNotificationPreferences'])
        ->middleware('throttle:20,1');
    Route::get('parent/system-trust-summary', [SystemTrustController::class, 'parentSummary']);
    Route::get('parent/learning-records/{learningRecord}/feedback', [LearningRecordFeedbackController::class, 'parentShow']);
    Route::put('parent/learning-records/{learningRecord}/feedback', [LearningRecordFeedbackController::class, 'parentUpsert'])
        ->middleware('throttle:20,1');
    Route::post('parent/learning-records/{learningRecord}/feedback/reply', [LearningRecordFeedbackController::class, 'parentReply'])
        ->middleware('throttle:20,1');

    // ── Parent: billing history (#401) ──────────────────────────────────
    Route::get('parent/billing-history', [ParentPortalController::class, 'billingHistory']);

    // ── Parent: 家長建議回饋（parent token 驗證在 Controller 內）────────────────
    Route::post('parent/feedback', [ParentFeedbackController::class, 'store'])
        ->middleware('throttle:20,1');

    // ── Super Admin: 家長回饋管理 ─────────────────────────────────────────────
    Route::middleware(['super_admin', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('parent-feedback/unread-count', [ParentFeedbackController::class, 'unreadCount']);
        Route::get('parent-feedback', [ParentFeedbackController::class, 'index']);
        Route::post('parent-feedback/{id}/mark-read', [ParentFeedbackController::class, 'markRead']);
    });

    // ── Teacher/Director: 家長回饋 inbox + 回覆 (#409, #410) ─────────────────
    // 安全收斂：原本這四個端點在任何 role/require_campus 群組之外，僅有全域 AttachAuthUser，
    // 等同未強制認證/授權。改納入 role:teacher,director,super_admin + require_campus，
    // 移除未認證即可呼叫的暴露。（per-row campus ownership 仍待補，記 TECH_DEBT）
    Route::middleware(['role:teacher,director,super_admin', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('parent-feedback/for-teacher', [ParentFeedbackController::class, 'forTeacher']);
        Route::post('parent-feedback/{id}/read', [ParentFeedbackController::class, 'markReadByTeacher']);
        Route::post('parent-feedback/{id}/reply', [ParentFeedbackController::class, 'reply']);
        Route::get('parent-feedback/{id}/replies', [ParentFeedbackController::class, 'replies']);
    });

    // ── Director: payment message for LINE copy ──────────────────────────
    Route::middleware(['role:director', 'require_password_change'])->group(function () {
        Route::get('parent/payment-message/{studentId}', [ParentPortalController::class, 'paymentMessage']);
        Route::get('line/status', [LineWebhookController::class, 'status']);
        Route::post('line/settings', [LineWebhookController::class, 'saveSettings']);
    });

    // ── Chat (director + teacher) ─────────────────────────────────────
    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('chat/threads', [ChatController::class, 'threads']);
        Route::post('chat/threads/dm', [ChatController::class, 'createDm']);
        Route::post('chat/threads/group', [ChatController::class, 'createGroup']);
        Route::get('chat/threads/{threadId}/messages', [ChatController::class, 'messages']);
        Route::post('chat/threads/{threadId}/messages', [ChatController::class, 'sendMessage']);
        Route::post('chat/threads/{threadId}/attachments', [ChatController::class, 'uploadAttachment']);
        Route::post('chat/threads/{threadId}/read', [ChatController::class, 'markRead']);
        Route::delete('chat/threads/{threadId}', [ChatController::class, 'deleteThread']);
        Route::get('chat/threads/{threadId}/members', [ChatController::class, 'getMembers']);
        Route::patch('chat/threads/{threadId}', [ChatController::class, 'updateThread']);
        Route::post('chat/threads/{threadId}/members', [ChatController::class, 'addMembers']);
        Route::delete('chat/threads/{threadId}/members/{userId}', [ChatController::class, 'removeMember']);
        Route::post('chat/threads/{threadId}/leave', [ChatController::class, 'leaveThread']);
        Route::post('chat/threads/{threadId}/transfer-owner', [ChatController::class, 'transferOwner']);
        Route::post('chat/threads/{threadId}/pin', [ChatController::class, 'pinThread']);
        Route::post('chat/threads/{threadId}/typing', [ChatController::class, 'typing']);
        Route::delete('chat/messages/{messageId}', [ChatController::class, 'deleteMessage']);
        Route::get('chat/unread-count', [ChatController::class, 'unreadCount']);
    });

    // ── Schedule Discrepancies (課表回報管理) ─────────────────────────────────
    // FR-008: Routes were accidentally removed by Claude Code on 2026-04-17. Re-added here.
    // NOTE: Static paths (/my, /summary, /active-for-session) MUST be registered before the
    // dynamic path (/{id}) to prevent Laravel's router from treating them as model IDs.
    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('schedule-discrepancies', [ScheduleDiscrepancyController::class, 'store']);
        Route::post('schedule-discrepancies/{id}/withdraw', [ScheduleDiscrepancyController::class, 'withdraw']);
        Route::get('schedule-discrepancies/my', [ScheduleDiscrepancyController::class, 'mine']);
        Route::get('schedule-discrepancies/active-for-session', [ScheduleDiscrepancyController::class, 'activeForSession']);
    });
    Route::middleware(['role:director', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('schedule-discrepancies', [ScheduleDiscrepancyController::class, 'index']);
        Route::get('schedule-discrepancies/summary', [ScheduleDiscrepancyController::class, 'summary']);
        Route::put('schedule-discrepancies/{id}', [ScheduleDiscrepancyController::class, 'updateStatus']);
        Route::get('reports/teacher-learning-fill-rates', [ClassSessionController::class, 'directorTeacherLearningFillRates']);
    });

    // ── Bug Reports (director + teacher: submit & view own; super_admin: branch queue + status) ──
    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('bugs', [BugReportController::class, 'store']);
        Route::get('bugs', [BugReportController::class, 'index']);
        Route::get('bugs/unread-badge', [BugReportController::class, 'unreadBadge']);
        Route::get('bugs/{id}', [BugReportController::class, 'show']);
        Route::post('bugs/{id}/comments', [BugReportController::class, 'addComment']);
        Route::post('bugs/{id}/reporter-verify', [BugReportController::class, 'reporterVerify']);
    });
    Route::middleware(['super_admin', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('bugs/{id}/status', [BugReportController::class, 'updateStatus']);
        Route::patch('bugs/{id}/comments/{commentId}/visibility', [BugReportController::class, 'updateCommentVisibility']);
        Route::post('bugs/mark-inbox-seen', [BugReportController::class, 'markInboxSeen']);
    });
});

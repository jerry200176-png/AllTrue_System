<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LearningRecordController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\AlertController;
use App\Http\Controllers\PendingSwipeController;
use App\Http\Controllers\ApiClientController;
use App\Http\Controllers\CampusController;
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
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetRequestController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ClassSessionController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\SubjectController;


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
                $table->string('status'); // scheduled, leave, rescheduled
                $table->string('type'); // normal, extra
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

Route::get('/health', fn() => response()->json(['ok' => true, 'message' => 'Laravel routing OK']));

// Debug: frontend sends log lines so we can read them on the server (storage/logs is writable)
Route::post('/debug-log', function (\Illuminate\Http\Request $req) {
    $path = storage_path('logs/debug-b5f8bc.log');
    $payload = $req->all();
    $payload['timestamp'] = $payload['timestamp'] ?? round(microtime(true) * 1000);
    $line = json_encode($payload) . "\n";
    try {
        file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
        // ignore
    }
    return response()->json(['ok' => true]);
});

Route::prefix('v1')->group(function () {
    // ── Auth (public) ───────────────────────────────────────────────
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/forgot-password', [PasswordResetRequestController::class, 'store']);

    // ── LINE Webhook (public, verified by campus channel secret) ──
    // Domain-based URL (no id): match Host to Campus.URL — must be registered before the {campusId} route
    Route::post('line/webhook', [LineWebhookController::class, 'handleDomainBased']);
    Route::post('line/webhook/{campusId}', [LineWebhookController::class, 'handle'])->where('campusId', '[0-9]+');

    // ── Parent Portal: LINE-based login ─────────────────────────────────
    Route::post('parent/login-line', [ParentPortalController::class, 'loginWithLine']);

    // ── Public Branch Data (No auth required) ───────────────────────
    Route::get('branches', [CampusController::class, 'listPublic']);
    Route::get('subjects-public', [SubjectController::class, 'indexPublic']);

    // ── Health / Perf Metrics (public, lightweight) ──────────────────
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'perf_flags' => [
                'throttle_notif_sync' => config('perfflags.throttle_notification_sync'),
                'lr_default_per_page' => config('perfflags.learning_records_default_per_page'),
                'lr_max_per_page'     => config('perfflags.learning_records_max_per_page'),
            ],
        ]);
    });

    // ── RFID 刷卡 (public，供讀卡機呼叫) ─────────────────────────────
    Route::post('swipe-rfid', [SwipeRfidController::class, 'swipe']);
    Route::post('auth/register', [AuthController::class, 'register']);

    // ── Director self-registration (public) ───────────────────────────────
    Route::post('directors/register', [DirectorAccountController::class, 'register']);

    // ── Current user profile (any authenticated user: director, teacher, super_admin) ──
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateMe']);
    Route::post('me/avatar', [AuthController::class, 'uploadAvatar']);
    Route::get('me/notification-preferences', [AuthController::class, 'notificationPreferences']);
    Route::put('me/notification-preferences', [AuthController::class, 'updateNotificationPreferences']);
    Route::get('me/security', [AuthController::class, 'security']);
    Route::post('me/security/logout-others', [AuthController::class, 'logoutOtherSessions']);

    Route::post('attendance/swipe', [AttendanceController::class, 'swipe'])
        ->middleware('api_key');

    // ── Super Admin only: 清空課程／學生／老師（保留 super_admin）────────────────
    Route::post('admin/reset-data', ResetDataController::class)->middleware(['role:director', 'require_password_change']);

    Route::middleware(['role:director', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::post('students/bulk-delete', [StudentController::class, 'bulkDestroy']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::put('students/{student}', [StudentController::class, 'update']);
        Route::delete('students/{student}', [StudentController::class, 'destroy']);
        Route::post('students/{student}/bind-card', [StudentController::class, 'bindCard']);

        Route::post('students/import', [ImportController::class, 'students']);
        Route::get('students/export', [ExportController::class, 'students']);

        Route::post('student-classes/import', [ImportController::class, 'studentClasses']);
        Route::get('student-classes/export', [ExportController::class, 'studentClasses']);
        Route::post('enrollments', [EnrollmentController::class, 'store']);

        Route::get('invoices', [BillingController::class, 'index']);
        Route::post('invoices', [BillingController::class, 'store']);
        Route::get('invoices/{invoice}/slip-data', [BillingController::class, 'slipData']);
        Route::post('invoices/{invoice}/payments', [BillingController::class, 'recordPayment']);
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
        Route::get('finance/teacher-payroll', [FinanceController::class, 'teacherPayroll']);
        Route::get('finance/subject-units', [FinanceController::class, 'subjectUnits']);
        Route::get('finance/branch-monthly-tuition', [FinanceController::class, 'branchMonthlyTuition']);

        Route::get('finance/parttime-payroll', [FinanceController::class, 'parttimePayroll']);
        Route::get('finance/parttime-payroll/rules', [FinanceController::class, 'parttimePayrollRules']);
        Route::put('finance/parttime-payroll/rules', [FinanceController::class, 'parttimePayrollRulesUpdate']);
        Route::get('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRules']);
        Route::put('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRulesUpdate']);
        Route::delete('finance/parttime-payroll/teacher-rules', [FinanceController::class, 'parttimePayrollTeacherRulesDelete']);
        Route::get('finance/parttime-payroll/export', [FinanceController::class, 'parttimePayrollExport']);
        Route::get('finance/parttime-payroll/{teacherId}/sessions', [FinanceController::class, 'parttimePayrollSessions'])->whereNumber('teacherId');
        Route::post('finance/parttime-payroll/lock', [FinanceController::class, 'parttimePayrollLock']);
        Route::post('finance/parttime-payroll/reopen', [FinanceController::class, 'parttimePayrollReopen']);
        Route::post('backfill/register-subject-units', [BackfillController::class, 'registerSubjectUnits']);

        Route::get('alerts/tuition', [AlertController::class, 'tuition']);
        Route::get('alerts/tuition-slip/{studentClassId}', [AlertController::class, 'tuitionSlipData']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/sync', [NotificationController::class, 'sync']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notificationId}/read', [NotificationController::class, 'markRead']);
        Route::post('notifications/{notificationId}/tuition-paid', [NotificationController::class, 'markTuitionPaid']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);

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
        Route::delete('directors/{id}', [DirectorAccountController::class, 'destroy'])->whereNumber('id');
        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::post('api-clients', [ApiClientController::class, 'store']);
        Route::post('api-clients/{apiClient}/revoke', [ApiClientController::class, 'revoke']);

        Route::post('subjects', [SubjectController::class, 'store']);
        Route::put('subjects/{id}', [SubjectController::class, 'update']);
        Route::delete('subjects/{id}', [SubjectController::class, 'destroy']);
    });

    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::get('students/{student}', [StudentController::class, 'show']);
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
        Route::post('student-classes/{studentClass}/purchase-batch', [StudentClassController::class, 'purchaseBatch']);
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
        Route::post('course-packages/{id}/bind-courses', [\App\Http\Controllers\CoursePackageController::class, 'bindCourses']);

        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/ended-sessions', [AttendanceController::class, 'endedSessions']);
        Route::post('attendance', [AttendanceController::class, 'store']);
        Route::post('attendance/batch-mark', [AttendanceController::class, 'batchMark']);
        Route::get('finance/subject-units', [FinanceController::class, 'subjectUnits']);

        Route::get('learning-records', [LearningRecordController::class, 'index']);
        Route::post('learning-records', [LearningRecordController::class, 'store']);
        Route::post('learning-records/{learningRecord}', [LearningRecordController::class, 'update']);
        Route::put('learning-records/{learningRecord}', [LearningRecordController::class, 'update']);
        Route::delete('learning-records/{learningRecord}', [LearningRecordController::class, 'destroy']);
        Route::get('class-sessions', [ClassSessionController::class, 'index']);
        Route::post('class-sessions/batch', [ClassSessionController::class, 'batchStore']);
        Route::patch('class-sessions/{id}', [ClassSessionController::class, 'update']);
        Route::post('class-sessions/{id}/substitute', [ClassSessionController::class, 'substitute']);

        Route::get('schedules', [\App\Http\Controllers\ScheduleController::class, 'index']);
        Route::post('schedules', [\App\Http\Controllers\ScheduleController::class, 'store']);
        Route::post('schedules/retro-leave', [\App\Http\Controllers\ScheduleController::class, 'retroLeave']);
        Route::post('schedules/leave-by-session', [\App\Http\Controllers\ScheduleController::class, 'leaveBySession']);
        Route::post('schedules/bulk-leave', [\App\Http\Controllers\ScheduleController::class, 'bulkHolidayLeave']);
        Route::put('schedules/{schedule}', [\App\Http\Controllers\ScheduleController::class, 'update']);
        Route::delete('schedules/{schedule}', [\App\Http\Controllers\ScheduleController::class, 'destroy']);

        // Dashboard Alerts
        Route::get('alerts/tuition', [\App\Http\Controllers\AlertController::class, 'tuition']);

        // ── Rooms ────────────────────────────────────────────────────
        Route::get('rooms', [RoomController::class, 'index']);
        Route::post('rooms', [RoomController::class, 'store']);
        Route::put('rooms/{room}', [RoomController::class, 'update']);
        Route::delete('rooms/{room}', [RoomController::class, 'destroy']);
    });

    // ── Teacher Management (Profiles) ────────────────────────────────
    Route::middleware(['role:director', 'require_campus', 'require_password_change'])->group(function () {
        Route::get('profiles', [ProfileController::class, 'index']);
        Route::post('profiles', [ProfileController::class, 'store']);
        Route::post('profiles/bulk-teachers', [ProfileController::class, 'bulkTeachers']);
        Route::post('profiles/{id}/reset-password', [ProfileController::class, 'resetPassword']);
        Route::put('profiles/{id}', [ProfileController::class, 'update']);
        Route::delete('profiles/{id}', [ProfileController::class, 'destroy']);

        Route::get('teacher_branches', [TeacherBranchController::class, 'index']);
        Route::post('teacher_branches', [TeacherBranchController::class, 'store']);
        Route::delete('teacher_branches', [TeacherBranchController::class, 'destroy']);
    });

    Route::post('parent/login', [ParentPortalController::class, 'login']);
    Route::get('parent/dashboard', [ParentPortalController::class, 'dashboard']);
    Route::post('parent/switch-student', [ParentPortalController::class, 'switchStudent']);
    Route::post('parent/sessions/{sessionId}/leave', [ParentPortalController::class, 'requestLeave']);

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
        Route::delete('chat/messages/{messageId}', [ChatController::class, 'deleteMessage']);
        Route::get('chat/unread-count', [ChatController::class, 'unreadCount']);
    });

    // ── Bug Reports (director + teacher: submit & view own; super_admin: branch queue + status) ──
    Route::middleware(['role:director,teacher', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('bugs', [BugReportController::class, 'store']);
        Route::get('bugs', [BugReportController::class, 'index']);
        Route::get('bugs/unread-badge', [BugReportController::class, 'unreadBadge']);
        Route::get('bugs/{id}', [BugReportController::class, 'show']);
        Route::post('bugs/{id}/comments', [BugReportController::class, 'addComment']);
    });
    Route::middleware(['super_admin', 'require_campus', 'require_password_change'])->group(function () {
        Route::post('bugs/{id}/status', [BugReportController::class, 'updateStatus']);
        Route::patch('bugs/{id}/comments/{commentId}/visibility', [BugReportController::class, 'updateCommentVisibility']);
        Route::post('bugs/mark-inbox-seen', [BugReportController::class, 'markInboxSeen']);
    });
});

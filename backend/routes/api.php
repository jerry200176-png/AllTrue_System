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
use App\Http\Controllers\RoomController;


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

    // ── LINE Webhook (public, verified by signature) ────────────────────
    Route::post('line/webhook', [LineWebhookController::class, 'handle']);

    // ── Parent Portal: LINE-based login ─────────────────────────────────
    Route::post('parent/login-line', [ParentPortalController::class, 'loginWithLine']);

    // ── Public Branch Data (No auth required) ───────────────────────
    Route::get('branches', [CampusController::class, 'listPublic']);

    // ── RFID 刷卡 (public，供讀卡機呼叫) ─────────────────────────────
    Route::post('swipe-rfid', [SwipeRfidController::class, 'swipe']);
    Route::post('auth/register', [AuthController::class, 'register']);

    // ── Director self-registration (public) ───────────────────────────────
    Route::post('directors/register', [DirectorAccountController::class, 'register']);

    // ── Current user profile (any authenticated user: director, teacher, super_admin) ──
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me', [AuthController::class, 'updateMe']);

    Route::post('attendance/swipe', [AttendanceController::class, 'swipe'])
        ->middleware('api_key');

    // ── Super Admin only: 清空課程／學生／老師（保留 super_admin）────────────────
    Route::post('admin/reset-data', ResetDataController::class)->middleware(['role:director']);

    Route::middleware(['role:director', 'require_campus'])->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::put('students/{student}', [StudentController::class, 'update']);
        Route::post('students/{student}/bind-card', [StudentController::class, 'bindCard']);

        Route::post('students/import', [ImportController::class, 'students']);
        Route::get('students/export', [ExportController::class, 'students']);

        Route::post('student-classes/import', [ImportController::class, 'studentClasses']);
        Route::get('student-classes/export', [ExportController::class, 'studentClasses']);

        Route::get('invoices', [BillingController::class, 'index']);
        Route::post('invoices', [BillingController::class, 'store']);
        Route::post('invoices/{invoice}/payments', [BillingController::class, 'recordPayment']);
        Route::get('invoices/export', [ExportController::class, 'invoices']);

        Route::post('learning-records/{learningRecord}/approve', [LearningRecordController::class, 'approve']);
        Route::post('learning-records/{learningRecord}/request-changes', [LearningRecordController::class, 'requestChanges']);
        Route::post('learning-records/{learningRecord}/reject', [LearningRecordController::class, 'reject']);
        Route::post('learning-records/backdoor-approve', [LearningRecordController::class, 'backdoorApprove']);
        Route::post('learning-records/bulk-backdoor-approve', [LearningRecordController::class, 'bulkBackdoorApprove']);
        Route::post('learning-records/batch-approve', [LearningRecordController::class, 'batchApprove']);
        Route::post('learning-records/reschedule-session', [LearningRecordController::class, 'rescheduleSession']);
        Route::post('learning-records/ensure-past', [LearningRecordController::class, 'ensurePastRecords']);

        Route::get('finance/summary', [FinanceController::class, 'summary']);
        Route::get('finance/revenue', [FinanceController::class, 'revenue']);
        Route::get('finance/outstanding', [FinanceController::class, 'outstanding']);
        Route::get('finance/teacher-payroll', [FinanceController::class, 'teacherPayroll']);
        Route::get('finance/subject-units', [FinanceController::class, 'subjectUnits']);
        Route::post('backfill/register-subject-units', [BackfillController::class, 'registerSubjectUnits']);

        Route::get('alerts/tuition', [AlertController::class, 'tuition']);

        Route::get('temp-rfid', [TempRfidController::class, 'show']);
        Route::post('temp-rfid/consume', [TempRfidController::class, 'consume']);

        Route::get('recent-unknown-rfids', [PendingSwipeController::class, 'recentUnknownRfids']);
        Route::get('pending-swipes', [PendingSwipeController::class, 'index']);
        Route::post('pending-swipes/{pendingSwipe}/assign-student', [PendingSwipeController::class, 'assignStudent']);
        Route::post('pending-swipes/{pendingSwipe}/match', [PendingSwipeController::class, 'match']);
        Route::delete('pending-swipes/{pendingSwipe}', [PendingSwipeController::class, 'destroy']);

        Route::get('campuses', [CampusController::class, 'index']);
        Route::get('directors/pending', [DirectorAccountController::class, 'pending']);
        Route::post('directors/{id}/approve', [DirectorAccountController::class, 'approve']);
        Route::post('directors/{id}/reject', [DirectorAccountController::class, 'reject']);
        Route::get('api-clients', [ApiClientController::class, 'index']);
        Route::post('api-clients', [ApiClientController::class, 'store']);
        Route::post('api-clients/{apiClient}/revoke', [ApiClientController::class, 'revoke']);
    });

    Route::middleware(['role:director,teacher', 'require_campus'])->group(function () {
        Route::get('students', [StudentController::class, 'index']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::get('profiles', [ProfileController::class, 'index']);
        // Alias: /api/v1/teachers → profiles filtered to type=T
        Route::get('teachers', function (\Illuminate\Http\Request $req) {
            $req->merge(['role' => 'teacher']);
            return app(\App\Http\Controllers\ProfileController::class)->index($req);
        });

        // Subjects list (for teacher manage subject dropdown, course form, etc.)
        Route::get('subjects', function () {
            if (!\Illuminate\Support\Facades\Schema::hasTable('Subject')) {
                return response()->json([]);
            }
            $rows = \Illuminate\Support\Facades\DB::table('Subject')->orderBy('Subject_Name')->get(['id', 'Subject_Name']);
            return response()->json($rows->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->Subject_Name]));
        });

        Route::get('student-classes/session-dates', [StudentClassController::class, 'sessionDates']);
        Route::post('student-classes/session-dates', [StudentClassController::class, 'sessionDates']);
        Route::post('student-classes/sync', [StudentClassController::class, 'sync']);
        Route::get('student-classes', [StudentClassController::class, 'index']);
        Route::post('student-classes', [StudentClassController::class, 'store']);
        Route::get('student-classes/{studentClass}', [StudentClassController::class, 'show']);
        Route::put('student-classes/{studentClass}', [StudentClassController::class, 'update']);
        Route::post('student-classes/{studentClass}/confirm-payment', [StudentClassController::class, 'confirmPayment']);
        Route::delete('student-classes/{studentClass}', [StudentClassController::class, 'destroy']);

        Route::get('attendance', [AttendanceController::class, 'index']);
        Route::get('attendance/ended-sessions', [AttendanceController::class, 'endedSessions']);
        Route::post('attendance', [AttendanceController::class, 'store']);

        Route::get('learning-records', [LearningRecordController::class, 'index']);
        Route::post('learning-records', [LearningRecordController::class, 'store']);
        Route::put('learning-records/{learningRecord}', [LearningRecordController::class, 'update']);
        Route::delete('learning-records/{learningRecord}', [LearningRecordController::class, 'destroy']);

        Route::get('schedules', [\App\Http\Controllers\ScheduleController::class, 'index']);
        Route::post('schedules', [\App\Http\Controllers\ScheduleController::class, 'store']);
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
    Route::middleware(['role:director', 'require_campus'])->group(function () {
        Route::get('profiles', [ProfileController::class, 'index']);
        Route::post('profiles', [ProfileController::class, 'store']);
        Route::put('profiles/{id}', [ProfileController::class, 'update']);

        Route::get('teacher_branches', [TeacherBranchController::class, 'index']);
        Route::post('teacher_branches', [TeacherBranchController::class, 'store']);
        Route::delete('teacher_branches', [TeacherBranchController::class, 'destroy']);
    });

    Route::post('parent/login', [ParentPortalController::class, 'login']);
    Route::get('parent/dashboard', [ParentPortalController::class, 'dashboard']);
    Route::post('parent/sessions/{sessionId}/leave', [ParentPortalController::class, 'requestLeave']);

    // ── Director: payment message for LINE copy ──────────────────────────
    Route::middleware(['role:director'])->group(function () {
        Route::get('parent/payment-message/{studentId}', [ParentPortalController::class, 'paymentMessage']);
    });
});

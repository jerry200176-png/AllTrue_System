<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddSessionConflictTest extends TestCase
{
    use RefreshDatabase;

    // --- add-session: locked by attendance ---
    public function test_add_session_returns_structured_409_when_attendance_exists(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonPath('conflict_type', 'locked_existing')
            ->assertJsonPath('has_attendance', true)
            ->assertJsonStructure(['message', 'suggested_actions', 'conflict_session_id']);
    }

    // --- add-session: locked by approved learning record ---
    public function test_add_session_returns_structured_409_when_approved_lr_exists(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('learning_record');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonPath('has_approved_learning_record', true);
    }

    // --- add-session: existing but unlocked → overwrite OK ---
    public function test_add_session_overwrites_unlocked_existing_session(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
            'auto_approve' => false,
        ]);

        $res->assertStatus(201);
    }

    /**
     * #253 regression: quick-add must use the same teacher/capacity guard as
     * schedule creation, so a one-on-one occupant blocks a new shared lesson.
     */
    public function test_add_session_rejects_teacher_capacity_conflict(): void
    {
        $token = $this->createDirectorToken([1]);
        $occupiedStudent = $this->createStudent(1);
        $targetStudent = $this->createStudent(1);
        $teacherId = 99;
        $occupied = $this->createStudentClass($occupiedStudent->id, [
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_one',
        ]);
        $target = $this->createStudentClass($targetStudent->id, [
            'TeacherID' => $teacherId,
            'ClassType' => 'one_on_two',
        ]);

        ClassSession::create([
            'StudentClassID' => $occupied->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$target->ID}/add-session/check", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
        ])->assertOk()
            ->assertJsonPath('can_add', false)
            ->assertJsonPath('error_code', 'TEACHER_CAPACITY_CONFLICT')
            ->assertJsonPath('conflict_type', 'teacher_capacity');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$target->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
            'auto_approve' => false,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'TEACHER_CAPACITY_CONFLICT')
            ->assertJsonPath('conflict_type', 'teacher_capacity');
        $this->assertDatabaseMissing('ClassSession', [
            'StudentClassID' => $target->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
        ]);
    }

    public function test_check_endpoint_identifies_existing_scheduled_occurrence_as_idempotent(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-08-29',
            'StartTime' => '13:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'scheduled',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-08-29',
            'start_time' => '13:00',
        ])->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('conflict_type', 'none')
            ->assertJsonPath('existing_session_id', $session->id);
    }

    // --- add-session: full capacity, no movable session ---
    public function test_add_session_returns_full_capacity_when_all_slots_used(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 2,
            'RemainingSessions' => 0,
            'UsedSessions' => 2,
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-22',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-04-01',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'auto_approve' => false,
        ]);

        $res->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSIONS_FULL')
            ->assertJsonPath('conflict_type', 'full_capacity');
    }

    public function test_date_mode_course_with_legacy_session_count_is_not_capacity_limited(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'ScheduleMode' => 'date',
            'SessionCount' => 4,
            'RemainingSessions' => 0,
            'UsedSessions' => 8,
            'StartDate' => Carbon::today()->subDays(10)->toDateString(),
            'EndDate' => Carbon::today()->addDays(10)->toDateString(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => Carbon::today()->addDays(2)->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'auto_approve' => false,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('student_class_id', $sc->ID);
    }

    public function test_shared_package_quick_add_uses_pool_capacity_when_member_is_full(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '共用方案快速補課',
            'billing_mode' => 'count', 'total_sessions' => 4, 'remaining_sessions' => 4,
            'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $course = $this->createStudentClass($student->id, [
            'PackageID' => $package->id,
            'SessionCount' => 2,
            'RemainingSessions' => 2,
            'UsedSessions' => 2,
        ]);
        $sibling = $this->createStudentClass($student->id, [
            'PackageID' => $package->id,
            'SessionCount' => 2,
            'RemainingSessions' => 2,
        ]);

        foreach (['2026-03-20', '2026-03-22'] as $date) {
            ClassSession::create([
                'StudentClassID' => $course->ID, 'SessionDate' => $date,
                'StartTime' => '19:00:00', 'EndTime' => '21:00:00', 'Status' => 'attended',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/add-session/check", [
            'session_date' => '2026-04-01', 'start_time' => '10:00',
        ]);

        $res->assertOk()->assertJsonPath('can_add', true);
        $this->assertSame(0, ClassSession::where('StudentClassID', $sibling->ID)->count());
    }

    public function test_shared_package_quick_add_allows_overplanned_sibling_without_blocking(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '共用方案超排提醒',
            'billing_mode' => 'count', 'total_sessions' => 1, 'remaining_sessions' => 1,
            'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $course = $this->createStudentClass($student->id, [
            'PackageID' => $package->id, 'SessionCount' => 1,
            'RemainingSessions' => 0, 'UsedSessions' => 1,
        ]);
        $sibling = $this->createStudentClass($student->id, [
            'PackageID' => $package->id, 'SessionCount' => 1,
            'RemainingSessions' => 0, 'UsedSessions' => 1,
        ]);

        foreach (range(1, 100) as $days) {
            ClassSession::create([
                'StudentClassID' => $sibling->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '18:00:00', 'EndTime' => '20:00:00', 'Status' => 'scheduled',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/add-session/check", [
            'session_date' => Carbon::today()->addDays(101)->toDateString(), 'start_time' => '10:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('package_planning.purchased_entitlement', 1)
            ->assertJsonPath('package_planning.actual_consumed', 0)
            ->assertJsonPath('package_planning.future_planned_sessions', 100)
            ->assertJsonPath('package_planning.overage_sessions', 100)
            ->assertJsonPath('package_planning.renewal_warning', true);
    }

    public function test_shared_package_quick_add_ignores_excused_future_occurrences(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1,
            'name' => 'Quick add package with excused occurrences',
            'billing_mode' => 'count', 'total_sessions' => 3, 'remaining_sessions' => 3,
            'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $course = $this->createStudentClass($student->id, [
            'PackageID' => $package->id,
            'SessionCount' => 1,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
        ]);

        foreach ([7, 9, 11] as $days) {
            ClassSession::create([
                'StudentClassID' => $course->ID,
                'SessionDate' => Carbon::today()->addDays($days)->toDateString(),
                'StartTime' => '19:00:00',
                'EndTime' => '20:00:00',
                'Status' => 'excused',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}", 'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/add-session/check", [
            'session_date' => Carbon::today()->addDays(14)->toDateString(), 'start_time' => '10:00',
        ]);

        $res->assertOk()->assertJsonPath('can_add', true);
    }

    // --- add-session: movable session exists → success with moved_from_date ---
    public function test_add_session_moves_last_future_session_and_returns_moved_from(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 2]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);
        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-12-30',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-04-05',
            'start_time' => '10:00',
            'duration_minutes' => 60,
            'auto_approve' => false,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('moved_from_date', '2026-12-30');
    }

    // --- add-session: repeated moves must not exceed Note varchar(255) ---
    // Regression: Sentry PHP-LARAVEL-29 — repeated 系統調整堂次（原 ...） appends
    // with no cap overflowed ClassSession.Note (varchar(255)) and threw
    // "Data too long for column 'Note'" on production (daan, StudentClass 2905).
    public function test_add_session_repeated_moves_keep_note_within_column_limit(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 2]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-09-01',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'scheduled',
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        for ($i = 0; $i < 15; $i++) {
            $res = $this->withHeaders($headers)
                ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
                    'session_date' => now()->addDays(30 + $i)->toDateString(),
                    'start_time' => '19:00',
                    'duration_minutes' => 120,
                    'auto_approve' => false,
                ]);
            $res->assertStatus(201);
        }

        $cs->refresh();
        $this->assertLessThanOrEqual(255, mb_strlen((string) $cs->Note));
    }

    // --- check endpoint: locked ---
    public function test_check_endpoint_returns_can_add_false_for_locked(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', false)
            ->assertJsonPath('conflict_type', 'locked_existing')
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonStructure(['suggested_actions', 'message']);
    }

    // --- check endpoint: no conflict ---
    public function test_check_endpoint_returns_can_add_true_when_no_conflict(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-05-01',
            'start_time' => '14:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('conflict_type', 'none');
    }

    /**
     * Regression: a count contract with seven attended sessions and one
     * remaining session must still accept its eighth occurrence. Cancelled
     * leave dates are historical exceptions, not additional active sessions.
     * This mirrors the Xindian 周芮緗 8-session contract reported on 2026-08-27.
     */
    public function test_eighth_session_is_not_rejected_as_full_after_cancelled_leave_dates(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $targetDate = Carbon::today()->addDays(2)->toDateString();
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 8,
            'RemainingSessions' => 1,
            'UsedSessions' => 7,
            'ClassType' => 'one_on_three',
        ]);

        foreach ([-23, -16, -9, -2, 5, 12, 19] as $offset) {
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => Carbon::parse($targetDate)->addDays($offset)->toDateString(),
                'StartTime' => '13:00:00',
                'EndTime' => '15:00:00',
                'Status' => 'attended',
            ]);
        }

        foreach ([-16, -9, -2, 5, 12] as $offset) {
            ClassSession::create([
                'StudentClassID' => $sc->ID,
                'SessionDate' => Carbon::parse($targetDate)->addDays($offset)->toDateString(),
                'StartTime' => '13:00:00',
                'EndTime' => '15:00:00',
                'Status' => 'cancelled',
            ]);
        }

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
                'session_date' => $targetDate,
                'start_time' => '13:00',
            ])
            ->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('conflict_type', 'none');

        $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
                'session_date' => $targetDate,
                'start_time' => '13:00',
                'duration_minutes' => 120,
                'auto_approve' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('student_class_id', $sc->ID);

        $this->assertSame(7, ClassSession::where('StudentClassID', $sc->ID)
            ->where('Status', 'attended')
            ->count());
        $this->assertSame(1, ClassSession::where('StudentClassID', $sc->ID)
            ->whereDate('SessionDate', $targetDate)
            ->where('Status', 'scheduled')
            ->count());
        $this->assertSame(1, (int) $sc->fresh()->RemainingSessions);
    }

    // --- check endpoint: full capacity ---
    public function test_check_endpoint_returns_full_capacity(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, [
            'SessionCount' => 1,
            'RemainingSessions' => 0,
            'UsedSessions' => 1,
        ]);

        ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-20',
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'attended',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => '2026-05-10',
            'start_time' => '10:00',
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', false)
            ->assertJsonPath('conflict_type', 'full_capacity')
            ->assertJsonPath('error_code', 'SESSIONS_FULL');
    }

    // --- check endpoint: is_ended flag for a slot already in the past ---
    // Regression: 黃奕暟 7/28 mis-add incident (2026-07-29) — quick-add defaulted
    // auto_approve=true and silently auto-approved the evaluation because the
    // picked slot had already ended. FE now needs is_ended to gate a confirm step.
    public function test_check_endpoint_returns_is_ended_true_for_past_slot(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $past = now()->subDay();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => $past->toDateString(),
            'start_time' => '10:00',
            'duration_minutes' => 60,
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('is_ended', true);
    }

    // --- check endpoint: is_ended flag for a slot still in the future ---
    public function test_check_endpoint_returns_is_ended_false_for_future_slot(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        // Y2: use a future date at 23:00 to avoid same-day isEnded ambiguity.
        $future = now()->addDays(2);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
            'session_date' => $future->toDateString(),
            'start_time' => '23:00',
            'duration_minutes' => 30,
        ]);

        $res->assertOk()
            ->assertJsonPath('can_add', true)
            ->assertJsonPath('is_ended', false);
    }

    // --- API contract: 409 still has message field (backward compat) ---
    public function test_409_response_always_contains_message_field(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
            'session_date' => '2026-03-25',
            'start_time' => '19:00',
            'duration_minutes' => 120,
        ]);

        $res->assertStatus(409);
        $json = $res->json();
        $this->assertArrayHasKey('message', $json, '409 response must include message for backward compat');
        $this->assertNotEmpty($json['message']);
    }

    // --- race condition: check passes but state changes before submit ---
    public function test_race_condition_check_passes_then_submit_gets_409(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-06-10',
            'StartTime' => '14:00:00',
            'EndTime' => '16:00:00',
            'Status' => 'scheduled',
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $checkRes = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", [
                'session_date' => '2026-06-10', 'start_time' => '14:00',
            ]);
        $checkRes->assertOk()->assertJsonPath('can_add', true);

        StudentSignIn::create([
            'StudentClassID' => $sc->ID,
            'StudentID' => $student->id,
            'ClassSessionID' => $cs->id,
            'SignInDT' => now(), 'MDT' => now(),
            'Status' => 'present', 'CampusID' => 1,
        ]);

        $submitRes = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", [
                'session_date' => '2026-06-10', 'start_time' => '14:00',
                'duration_minutes' => 120, 'auto_approve' => false,
            ]);

        $submitRes->assertStatus(409)
            ->assertJsonPath('error_code', 'SESSION_LOCKED')
            ->assertJsonStructure(['suggested_actions']);
    }

    // --- check and add-session error_code values must match ---
    public function test_check_and_add_session_return_same_error_code_for_locked(): void
    {
        [$token, $sc] = $this->seedCourseWithLockedSession('attendance');
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $params = ['session_date' => '2026-03-25', 'start_time' => '19:00'];

        $checkJson = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session/check", $params)
            ->json();
        $submitJson = $this->withHeaders($headers)
            ->postJson("/api/v1/student-classes/{$sc->ID}/add-session", array_merge($params, ['duration_minutes' => 120]))
            ->json();

        $this->assertSame($checkJson['error_code'], $submitJson['error_code']);
        $this->assertSame($checkJson['conflict_type'], $submitJson['conflict_type']);
    }

    // ── helpers ──

    private function seedCourseWithLockedSession(string $lockType): array
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent(1);
        $sc = $this->createStudentClass($student->id, ['SessionCount' => 4]);

        $cs = ClassSession::create([
            'StudentClassID' => $sc->ID,
            'SessionDate' => '2026-03-25',
            'StartTime' => '19:00:00',
            'EndTime' => '21:00:00',
            'Status' => 'attended',
        ]);

        if ($lockType === 'attendance') {
            StudentSignIn::create([
                'StudentClassID' => $sc->ID,
                'StudentID' => $student->id,
                'ClassSessionID' => $cs->id,
                'SignInDT' => now(),
                'MDT' => now(),
                'Status' => 'present',
                'CampusID' => 1,
            ]);
        } elseif ($lockType === 'learning_record') {
            LearningRecord::create([
                'StudentClassID' => $sc->ID,
                'ClassSessionID' => $cs->id,
                'TeacherID' => 99,
                'Content' => 'Test',
                'Subject' => 'Math',
                'SessionDate' => '2026-03-25',
                'StartTime' => '19:00:00',
                'EndTime' => '21:00:00',
                'Status' => 'approved',
                'ApprovedAt' => now(),
            ]);
        }

        return [$token, $sc];
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'dir-addsess-' . uniqid() . '@test.com',
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['CampusID' => $cid, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    private function createStudent(int $campusId): Student
    {
        return Student::create([
            'name' => '加課測試生-' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}

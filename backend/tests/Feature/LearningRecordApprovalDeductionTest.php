<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningRecordApprovalDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_lr_deducts_session_and_marks_class_session_attended(): void
    {
        $token = $this->createDirectorToken([1], 'director-approve-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-approve-a@example.com');
        $student = $this->createStudent(1, '核准扣堂測試A');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '待審評量',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame(5, (int) $after->RemainingSessions, 'approve should deduct one session');
        $this->assertSame(1, (int) $after->UsedSessions);

        $this->assertDatabaseHas('LearningRecord', ['id' => $record->id, 'Status' => 'approved']);

        $classSession->refresh();
        $this->assertSame('attended', $classSession->Status);

        $this->assertDatabaseHas('StudentSingIn', [
            'ClassSessionID' => $classSession->id,
            'Memo' => 'lr_approve',
            'SessionDeducted' => 1,
        ]);
    }

    public function test_approving_already_attended_session_does_not_double_deduct(): void
    {
        $token = $this->createDirectorToken([1], 'director-approve-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-approve-b@example.com');
        $student = $this->createStudent(1, '核准扣堂測試B');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 5,
            'remaining_sessions' => 5,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [2],
            'start_time' => '17:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'present',
        ])->assertCreated();

        $afterAttendance = StudentClass::findOrFail($courseId);
        $this->assertSame(4, (int) $afterAttendance->RemainingSessions);
        $this->assertSame(1, (int) $afterAttendance->UsedSessions);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '待審評量',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve", [])
            ->assertOk();

        $afterApprove = StudentClass::findOrFail($courseId);
        $this->assertSame(4, (int) $afterApprove->RemainingSessions, 'should NOT double-deduct');
        $this->assertSame(1, (int) $afterApprove->UsedSessions);
    }

    public function test_approving_lr_on_monthly_course_increments_used_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-monthly-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-monthly-a@example.com');
        $student = $this->createStudent(1, '月結制測試A');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 0,
            'remaining_sessions' => 0,
            'sessions_used' => 0,
            'schedule_mode' => 'monthly',
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '月結評量',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve")
            ->assertOk();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame(1, (int) $after->UsedSessions, 'monthly: UsedSessions should increment');
        $this->assertSame(0, (int) $after->RemainingSessions, 'monthly: RemainingSessions stays 0');
    }

    public function test_approving_lr_with_no_class_session_id_resolves_and_deducts(): void
    {
        $token = $this->createDirectorToken([1], 'director-orphan-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-orphan-a@example.com');
        $student = $this->createStudent(1, 'Orphan LR 測試');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '14:00',
        ]);
        $courseId = (int) $course->ID;
        $sessionDate = now()->subDay()->toDateString();

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $sessionDate,
            'StartTime' => '14:00',
            'EndTime' => '16:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => 0,
            'TeacherID' => $teacherId,
            'Content' => 'Orphan 評量',
            'Status' => 'pending',
            'SessionDate' => $sessionDate,
            'StartTime' => '14:00',
            'EndTime' => '16:00',
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        $this->assertSame(0, (int) $record->ClassSessionID);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve")
            ->assertOk();

        $record->refresh();
        $this->assertSame((int) $classSession->id, (int) $record->ClassSessionID, 'orphan LR should be bound');

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame(5, (int) $after->RemainingSessions, 'orphan LR approve should deduct');
    }

    public function test_attendance_after_approval_returns_409(): void
    {
        $token = $this->createDirectorToken([1], 'director-att409-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-att409-a@example.com');
        $student = $this->createStudent(1, '核准後點名409');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '15:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '先核准再點名',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}/approve")
            ->assertOk();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $classSession->id,
            'Status' => 'present',
        ]);

        $response->assertStatus(409);
    }

    public function test_backdoor_approve_does_not_deduct_attendance_deducts_once(): void
    {
        $directorLogin = 'director-backdoor-a@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backdoor-a@example.com');
        $student = $this->createStudent(1, '補登扣堂測試A');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '15:00',
        ]);
        $courseId = (int) $course->ID;
        $before = StudentClass::findOrFail($courseId);
        $sessionDate = now()->subDay()->toDateString();
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $sessionDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'SessionDate' => $sessionDate,
        ])->assertCreated();

        $recordId = (int) ($res->json('id') ?? 0);
        $this->assertTrue($recordId > 0);
        $record = LearningRecord::findOrFail($recordId);

        $afterApprove = StudentClass::findOrFail($courseId);
        $this->assertSame((int) $before->RemainingSessions, (int) $afterApprove->RemainingSessions);
        $this->assertSame((int) $before->UsedSessions, (int) $afterApprove->UsedSessions);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $record->ClassSessionID,
            'Status' => 'present',
        ])->assertCreated();

        $afterAttendance = StudentClass::findOrFail($courseId);
        $this->assertSame((int) $afterApprove->RemainingSessions - 1, (int) $afterAttendance->RemainingSessions);
        $this->assertSame((int) $afterApprove->UsedSessions + 1, (int) $afterAttendance->UsedSessions);
    }

    public function test_legacy_bulk_backdoor_approve_endpoint_returns_410(): void
    {
        $directorLogin = 'director-bulk-retired@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-bulk-retired@example.com');
        $student = $this->createStudent(1, 'bulk retired stub');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '15:00',
        ]);
        $courseId = (int) $course->ID;
        $d = now()->subDay()->toDateString();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => [$d],
        ])->assertStatus(410)
            ->assertJsonPath('code', 'legacy_bulk_backfill_retired');
    }

    public function test_learning_record_store_rejects_before_session_start_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-time-lock-store-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-time-lock-store-a@example.com');
        $student = $this->createStudent(1, '時間卡控測試A');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => now()->toDateString(),
            'days_of_week' => [3],
            'start_time' => '15:00',
        ]);
        $courseId = (int) $course->ID;

        $futureDate = now()->addDay()->toDateString();
        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $futureDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $futureDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Content' => '尚未開課不應可填',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '課程尚未開始，請於上課時間後再填寫評量表']);
    }

    public function test_learning_record_update_rejects_before_session_start_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-time-lock-update-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-time-lock-update-a@example.com');
        $student = $this->createStudent(1, '時間卡控測試B');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => now()->toDateString(),
            'days_of_week' => [3],
            'start_time' => '15:00',
        ]);
        $courseId = (int) $course->ID;

        $futureDate = now()->addDay()->toDateString();
        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $futureDate,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '舊內容',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/learning-records/{$record->id}", [
            'Content' => '尚未開課不應可修改',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '課程尚未開始，請於上課時間後再填寫評量表']);
    }

    public function test_learning_record_store_rejects_future_session_without_explicit_time_payload(): void
    {
        $token = $this->createDirectorToken([1], 'director-time-lock-store-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-time-lock-store-b@example.com');
        $student = $this->createStudent(1, '時間卡控測試C');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => now()->toDateString(),
            'days_of_week' => [3],
            'start_time' => '15:00',
        ]);
        $futureDate = now()->addDays(10)->toDateString();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records', [
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'Subject' => '數學',
            'SessionDate' => $futureDate,
            'Content' => '未開課不應可填（無開始時間）',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '課程尚未開始，請於上課時間後再填寫評量表']);
    }

    public function test_ensure_past_creates_record_bound_to_existing_class_session(): void
    {
        $token = $this->createDirectorToken([1], 'director-ensure-past-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ensure-past-a@example.com');
        $student = $this->createStudent(1, '補齊評量測試A');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/ensure-past', [
            'branch_id' => 1,
        ])->assertOk();

        $this->assertSame(1, (int) ($res->json('created') ?? 0));

        $record = LearningRecord::where('ClassSessionID', $classSession->id)->first();
        $this->assertNotNull($record, 'ensure-past should create a LearningRecord for existing ClassSession');
        $this->assertSame($classSession->SessionDate, $record->SessionDate);
        $this->assertSame(substr((string) $classSession->StartTime, 0, 5), substr((string) $record->StartTime, 0, 5));
        $this->assertSame(substr((string) $classSession->EndTime, 0, 5), substr((string) $record->EndTime, 0, 5));
        $this->assertSame('pending', $record->Status);
    }

    public function test_ensure_past_repairs_existing_record_when_session_date_drifted(): void
    {
        $token = $this->createDirectorToken([1], 'director-ensure-past-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ensure-past-b@example.com');
        $student = $this->createStudent(1, '補齊評量測試B');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [2],
            'start_time' => '17:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '日期漂移測試',
            'Status' => 'pending',
            'SessionDate' => now()->subDays(10)->toDateString(),
            'StartTime' => '00:00',
            'EndTime' => '00:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/ensure-past', [
            'branch_id' => 1,
        ])->assertOk();

        $this->assertSame(0, (int) ($res->json('created') ?? -1));

        $record->refresh();
        $this->assertSame($classSession->SessionDate, $record->SessionDate);
        $this->assertSame(substr((string) $classSession->StartTime, 0, 5), substr((string) $record->StartTime, 0, 5));
        $this->assertSame(substr((string) $classSession->EndTime, 0, 5), substr((string) $record->EndTime, 0, 5));

        $count = LearningRecord::where('ClassSessionID', $classSession->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_ensure_past_skips_leave_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-ensure-past-leave@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ensure-past-leave@example.com');
        $student = $this->createStudent(1, '請假不補評量');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        foreach (['leave', 'leave_adjusted'] as $status) {
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => now()->subDays(3)->toDateString(),
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => $status,
                'Note' => "test-{$status}",
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/ensure-past', [
            'branch_id' => 1,
        ])->assertOk();

        $this->assertSame(0, (int) ($res->json('created') ?? -1), 'ensure-past must not create LR for leave/leave_adjusted sessions');
    }

    public function test_ensure_past_does_not_recreate_voided_record(): void
    {
        $token = $this->createDirectorToken([1], 'director-ensure-past-voided@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ensure-past-voided@example.com');
        $student = $this->createStudent(1, '作廢不重建');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '被作廢的評量',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'VoidedAt' => now(),
            'VoidReason' => '一般請假',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/ensure-past', [
            'branch_id' => 1,
        ])->assertOk();

        // Session is attended-like (completed) and LR was voided by a leave cascade.
        // ensure-past should RESTORE (un-void) the existing record so the teacher can fill it in.
        // It must NOT insert a new row (unique constraint on ClassSessionID).
        $this->assertSame(1, (int) ($res->json('created') ?? -1), 'ensure-past should restore a voided LR for an attended session');
        $total = LearningRecord::where('ClassSessionID', $classSession->id)->count();
        $this->assertSame(1, $total, 'should still have exactly 1 record (the restored one)');
        $restored = LearningRecord::where('ClassSessionID', $classSession->id)->first();
        $this->assertNull($restored->VoidedAt, 'restored LR must have VoidedAt cleared');
        $this->assertSame('pending', $restored->Status, 'restored LR must be pending');
    }

    public function test_voided_record_excluded_from_index_and_batch(): void
    {
        $token = $this->createDirectorToken([1], 'director-voided-index@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-voided-index@example.com');
        $student = $this->createStudent(1, '作廢不顯示');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $cs = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'leave',
            'Note' => '',
        ]);

        $voided = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs->id,
            'TeacherID' => $teacherId,
            'Content' => '已作廢',
            'Status' => 'pending',
            'SessionDate' => $cs->SessionDate,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'VoidedAt' => now(),
            'VoidReason' => '一般請假',
        ]);

        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

        $indexRes = $this->withHeaders($headers)
            ->getJson('/api/v1/learning-records?branch_id=1&per_page=50&status=pending');
        $indexRes->assertOk();
        $ids = collect($indexRes->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotContains((int) $voided->id, $ids, 'voided record must not appear in index');

        $batchRes = $this->withHeaders($headers)
            ->postJson('/api/v1/learning-records/batch-approve', [
                'DirectorID' => 1,
                'branch_id' => 1,
            ]);
        $batchRes->assertOk();
        $this->assertSame(0, (int) $batchRes->json('approved'), 'batch-approve must skip voided records');

        $voided->refresh();
        $this->assertSame('pending', $voided->Status, 'voided record status must remain unchanged');
    }

    public function test_batch_reject_sets_status_and_review_note(): void
    {
        $token = $this->createDirectorToken([1], 'director-batch-reject@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-reject@example.com');
        $student = $this->createStudent(1, '批次退回測試');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $d1 = now()->subDay()->toDateString();
        $d2 = now()->subDays(2)->toDateString();
        $cs1 = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $cs2 = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d2,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $r1 = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs1->id,
            'TeacherID' => $teacherId,
            'Content' => '批次退回測試1',
            'Status' => 'pending',
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
        ]);
        $r2 = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs2->id,
            'TeacherID' => $teacherId,
            'Content' => '批次退回測試2',
            'Status' => 'pending',
            'SessionDate' => $d2,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/batch-reject', [
            'ids' => [$r1->id, $r2->id],
            'ReviewNote' => '內容不完整',
        ]);

        $res->assertOk();
        $this->assertSame(2, (int) $res->json('rejected'));

        $this->assertDatabaseHas('LearningRecord', ['id' => $r1->id, 'Status' => 'rejected', 'ReviewNote' => '內容不完整']);
        $this->assertDatabaseHas('LearningRecord', ['id' => $r2->id, 'Status' => 'rejected', 'ReviewNote' => '內容不完整']);
    }

    public function test_batch_request_changes_sets_status(): void
    {
        $token = $this->createDirectorToken([1], 'director-batch-changes@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-batch-changes@example.com');
        $student = $this->createStudent(1, '批次需修改測試');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;

        $d1 = now()->subDay()->toDateString();
        $cs1 = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $r1 = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs1->id,
            'TeacherID' => $teacherId,
            'Content' => '批次需修改測試',
            'Status' => 'pending',
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/batch-request-changes', [
            'ids' => [$r1->id],
            'ReviewNote' => '請補充進度內容',
        ]);

        $res->assertOk();
        $this->assertSame(1, (int) $res->json('changed'));
        $this->assertDatabaseHas('LearningRecord', ['id' => $r1->id, 'Status' => 'changes_requested', 'ReviewNote' => '請補充進度內容']);
    }

    public function test_teacher_cannot_batch_reject(): void
    {
        $teacherId = $this->createTeacher(1, 'teacher-batch-reject-fail@example.com');
        $teacherToken = bin2hex(random_bytes(16));
        $teacherUser = User::where('LoginName', 'teacher-batch-reject-fail@example.com')->first();
        AuthToken::create([
            'user_id' => $teacherUser->id,
            'token' => $teacherToken,
            'expires_at' => now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/batch-reject', [
            'ids' => [1],
            'ReviewNote' => 'should fail',
        ]);

        $res->assertStatus(403);
    }

    public function test_teacher_cannot_edit_approved_record(): void
    {
        $token = $this->createDirectorToken([1], 'director-teacher-edit-approved@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-edit-approved@example.com');
        $student = $this->createStudent(1, '老師不可改已核准');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseId = (int) $course->ID;
        $directorId = $this->getUserIdByLoginName('director-teacher-edit-approved@example.com');

        $d1 = now()->subDay()->toDateString();
        $cs1 = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $record = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $cs1->id,
            'TeacherID' => $teacherId,
            'Content' => '已核准內容',
            'Status' => 'approved',
            'SessionDate' => $d1,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'ApprovedBy' => $directorId,
            'ApprovedAt' => now(),
        ]);

        $teacherUser = User::where('LoginName', 'teacher-edit-approved@example.com')->first();
        $teacherToken = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $teacherUser->id,
            'token' => $teacherToken,
            'expires_at' => now()->addDay(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$teacherToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$record->id}", [
            'Content' => '老師想改已核准',
        ]);

        $res->assertStatus(409);
    }

    public function test_rollback_approval_reverses_deduction_but_not_independent_attendance(): void
    {
        $token = $this->createDirectorToken([1], 'director-rollback-full@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-rollback-full@example.com');
        $student = $this->createStudent(1, '退回待審完整測試');

        // --- Scenario A: approve then rollback → restored ---
        $courseA = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ]);
        $courseAId = (int) $courseA->ID;

        $csA = ClassSession::create([
            'StudentClassID' => $courseAId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $recordA = LearningRecord::create([
            'StudentClassID' => $courseAId,
            'ClassSessionID' => $csA->id,
            'TeacherID' => $teacherId,
            'Content' => '退回待審內容',
            'Status' => 'pending',
            'SessionDate' => $csA->SessionDate,
            'StartTime' => '16:00',
            'EndTime' => '18:00',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$recordA->id}/approve")
            ->assertOk();

        $afterApproveA = StudentClass::findOrFail($courseAId);
        $this->assertSame(5, (int) $afterApproveA->RemainingSessions);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$recordA->id}/rollback-approval", [
            'ReviewNote' => '需要重新檢查',
        ])->assertOk();

        $this->assertDatabaseHas('LearningRecord', [
            'id' => $recordA->id,
            'Status' => 'pending',
            'ReviewNote' => '需要重新檢查',
        ]);

        $afterRollbackA = StudentClass::findOrFail($courseAId);
        $this->assertSame(6, (int) $afterRollbackA->RemainingSessions, 'rollback should restore sessions');
        $this->assertSame(0, (int) $afterRollbackA->UsedSessions);

        $csA->refresh();
        $this->assertSame('scheduled', $csA->Status);

        // --- Scenario B: attendance then approve then rollback → attendance deduction intact ---
        $courseB = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [2],
            'start_time' => '17:00',
        ]);
        $courseBId = (int) $courseB->ID;

        $csB = ClassSession::create([
            'StudentClassID' => $courseBId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '17:00',
            'EndTime' => '19:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'StudentID' => $student->id,
            'StudentClassID' => $courseBId,
            'TeacherID' => $teacherId,
            'ClassSessionID' => $csB->id,
            'Status' => 'present',
        ])->assertCreated();

        $afterAttB = StudentClass::findOrFail($courseBId);
        $this->assertSame(5, (int) $afterAttB->RemainingSessions);

        $recordB = LearningRecord::create([
            'StudentClassID' => $courseBId,
            'ClassSessionID' => $csB->id,
            'TeacherID' => $teacherId,
            'Content' => '先點名再核准',
            'Status' => 'pending',
            'SessionDate' => $csB->SessionDate,
            'StartTime' => '17:00',
            'EndTime' => '19:00',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$recordB->id}/approve")
            ->assertOk();

        $afterApproveB = StudentClass::findOrFail($courseBId);
        $this->assertSame(5, (int) $afterApproveB->RemainingSessions, 'approve after attendance should not double-deduct');

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$recordB->id}/rollback-approval")
            ->assertOk();

        $afterRollbackB = StudentClass::findOrFail($courseBId);
        $this->assertSame(5, (int) $afterRollbackB->RemainingSessions, 'rollback should NOT undo independent attendance');
        $this->assertSame(1, (int) $afterRollbackB->UsedSessions);

        $csB->refresh();
        $this->assertSame('attended', $csB->Status, 'independent attendance keeps CS as attended');
    }

    public function test_paused_course_hides_pending_learning_record_from_index_but_keeps_approved_visible(): void
    {
        $token = $this->createDirectorToken([1], 'director-paused-lr-index@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-paused-lr-index@example.com');
        $student = $this->createStudent(1, '暫停課程評量索引測試');

        $course = $this->createStudentClassForTest($student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '10:00',
        ]);
        $courseId = (int) $course->ID;

        $sc = StudentClass::findOrFail($courseId);
        $sc->Stop = 1;
        $sc->save();

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => '2026-03-28',
            'StartTime' => '10:00',
            'EndTime' => '12:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $pendingRecord = LearningRecord::create([
            'StudentClassID' => $courseId,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherId,
            'Content' => '暫停後待審',
            'Status' => 'pending',
            'SessionDate' => $classSession->SessionDate,
            'StartTime' => $classSession->StartTime,
            'EndTime' => $classSession->EndTime,
        ]);

        $resPending = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $resPending->assertOk();
        $ids = collect($resPending->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $pendingRecord->id, $ids, '暫停課程的待審評量不應出現在列表');

        $pendingRecord->Status = 'approved';
        $pendingRecord->ApprovedAt = now();
        $pendingRecord->save();

        $resApproved = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

        $resApproved->assertOk();
        $idsAfter = collect($resApproved->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $pendingRecord->id, $idsAfter, '已核准評量在暫停課程仍應可查');
    }

    /**
     * 課程已暫停／結束（Stop=1）且 ClassSession 未及時改為已到（仍 scheduled）、但堂次結束時間已過：
     * 待審評量仍應出現在 index（避免「最後一堂」卡住無法審／退回）。
     */
    public function test_paused_course_shows_pending_when_past_session_still_scheduled(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-07 21:00:00', 'Asia/Taipei'));

        try {
            $token = $this->createDirectorToken([1], 'director-paused-stuck-lr@example.com');
            $teacherId = $this->createTeacher(1, 'teacher-paused-stuck-lr@example.com');
            $student = $this->createStudent(1, '暫停卡住評量索引');

            $course = $this->createStudentClassForTest($student->id, $teacherId, [
                'sessions_purchased' => 4,
                'remaining_sessions' => 0,
                'sessions_used' => 4,
                'first_class_date' => '2026-03-01',
                'days_of_week' => [1],
                'start_time' => '18:00',
            ]);
            $courseId = (int) $course->ID;

            $sc = StudentClass::findOrFail($courseId);
            $sc->Stop = 1;
            $sc->save();

            $classSession = ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => '2026-05-07',
                'StartTime' => '18:00',
                'EndTime' => '20:00',
                'Status' => 'scheduled',
                'Note' => '',
            ]);

            $pendingRecord = LearningRecord::create([
                'StudentClassID' => $courseId,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'Content' => '結束後仍待審',
                'Status' => 'pending',
                'SessionDate' => $classSession->SessionDate,
                'StartTime' => $classSession->StartTime,
                'EndTime' => $classSession->EndTime,
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson('/api/v1/learning-records?branch_id=1&per_page=50');

            $res->assertOk();
            $ids = collect($res->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->assertContains((int) $pendingRecord->id, $ids, '結束後仍 scheduled 的待審應可見以便處理');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createTeacher(int $campusId, string $loginName): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000000',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * POST /api/v1/student-classes is retired (410); tests seed StudentClass directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createStudentClassForTest(int $studentId, int $teacherId, array $overrides = []): StudentClass
    {
        $o = array_merge([
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 500,
            'duration_hours' => 2,
            'payment_type' => 'session',
            'sessions_purchased' => 8,
            'sessions_used' => 0,
            'remaining_sessions' => 8,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
            'Memo' => '測試課程',
        ], $overrides);

        $subjectKey = strtolower((string) $o['subject']);
        $subjectId = match ($subjectKey) {
            'math', '數學' => 1,
            default => 1,
        };

        $course = StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => $subjectId,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => $o['first_class_date'],
            'EndDate' => null,
            'TotalHours' => 40,
            'Memo' => $o['Memo'],
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => (float) $o['rate_per_30min'],
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => $o['schedule_mode'] ?? 'count',
            'SessionCount' => (int) $o['sessions_purchased'],
            'RemainingSessions' => (int) $o['remaining_sessions'],
            'UsedSessions' => (int) $o['sessions_used'],
            'SessionDuration' => max(30, (int) $o['duration_hours'] * 60),
            'ClassType' => $o['class_type'],
        ]);

        $this->assertTrue($course->ID > 0, 'Course ID should be available.');

        return $course;
    }

    private function getUserIdByLoginName(string $loginName): int
    {
        $userId = (int) (DB::table('User')->where('LoginName', $loginName)->value('id') ?? 0);
        $this->assertTrue($userId > 0, 'Director ID should be available.');
        return $userId;
    }
}

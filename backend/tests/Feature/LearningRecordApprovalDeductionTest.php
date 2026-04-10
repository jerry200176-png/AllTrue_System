<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningRecordApprovalDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_approving_learning_record_deducts_sessions_and_marks_record(): void
    {
        $token = $this->createDirectorToken([1], 'director-approve-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-approve-a@example.com');
        $student = $this->createStudent(1, '核准扣堂測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ])->assertCreated();

        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);
        $before = StudentClass::findOrFail($courseId);

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->subDay()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '18:00',
            'Status' => 'completed',
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
        $this->assertSame((int) $before->RemainingSessions - 1, (int) $after->RemainingSessions);
        $this->assertSame((int) $before->UsedSessions + 1, (int) $after->UsedSessions);

        $this->assertDatabaseHas('LearningRecord', [
            'id' => $record->id,
            'Status' => 'approved',
            'SessionDeducted' => 1,
        ]);
    }

    public function test_attendance_after_approved_record_does_not_deduct_twice(): void
    {
        $token = $this->createDirectorToken([1], 'director-approve-b@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-approve-b@example.com');
        $student = $this->createStudent(1, '核准扣堂測試B');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 5,
            'remaining_sessions' => 5,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [2],
            'start_time' => '17:00',
        ])->assertCreated();

        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

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
        $remainingAfterApprove = (int) $afterApprove->RemainingSessions;
        $usedAfterApprove = (int) $afterApprove->UsedSessions;

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
        $this->assertSame($remainingAfterApprove, (int) $afterAttendance->RemainingSessions);
        $this->assertSame($usedAfterApprove, (int) $afterAttendance->UsedSessions);

        $this->assertDatabaseHas('StudentSingIn', [
            'ClassSessionID' => $classSession->id,
            'SessionDeducted' => 1,
        ]);
    }

    public function test_backdoor_approve_deducts_and_attendance_does_not_deduct_twice(): void
    {
        $directorLogin = 'director-backdoor-a@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backdoor-a@example.com');
        $student = $this->createStudent(1, '補登扣堂測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '15:00',
        ])->assertCreated();

        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);
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
        $this->assertSame((int) $before->RemainingSessions - 1, (int) $afterApprove->RemainingSessions);
        $this->assertSame((int) $before->UsedSessions + 1, (int) $afterApprove->UsedSessions);
        $this->assertSame(1, (int) $record->SessionDeducted);

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
        $this->assertSame((int) $afterApprove->RemainingSessions, (int) $afterAttendance->RemainingSessions);
        $this->assertSame((int) $afterApprove->UsedSessions, (int) $afterAttendance->UsedSessions);
    }

    public function test_bulk_backdoor_approve_deducts_each_created_record(): void
    {
        $directorLogin = 'director-backdoor-b@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backdoor-b@example.com');
        $student = $this->createStudent(1, '補登扣堂測試B');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 8,
            'remaining_sessions' => 8,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '15:00',
        ])->assertCreated();

        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);
        $before = StudentClass::findOrFail($courseId);

        $d1 = now()->subDays(2)->toDateString();
        $d2 = now()->subDay()->toDateString();
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d1,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d2,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => [$d1, $d2],
        ])->assertCreated();

        $after = StudentClass::findOrFail($courseId);
        $this->assertSame((int) $before->RemainingSessions - 2, (int) $after->RemainingSessions);
        $this->assertSame((int) $before->UsedSessions + 2, (int) $after->UsedSessions);

        $deductedCount = LearningRecord::where('StudentClassID', $courseId)
            ->whereIn('SessionDate', [$d1, $d2])
            ->where('SessionDeducted', 1)
            ->count();
        $this->assertSame(2, $deductedCount);
    }

    public function test_bulk_backdoor_approve_skips_dates_without_effective_class_session(): void
    {
        $directorLogin = 'director-backdoor-skip-a@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backdoor-skip-a@example.com');
        $student = $this->createStudent(1, '補登略過測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [6],
            'start_time' => '15:00',
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $d1 = now()->subDays(7)->toDateString();
        $d2 = now()->subDays(6)->toDateString(); // leave（不應建立評量）
        $d3 = now()->subDays(5)->toDateString();
        $d4 = now()->subDays(4)->toDateString(); // 沒有 ClassSession（不應建立評量）

        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d1,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d2,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'leave',
            'Note' => '請假',
        ]);
        ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => $d3,
            'StartTime' => '15:00',
            'EndTime' => '17:00',
            'Status' => 'attended',
            'Note' => '',
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => [$d1, $d2, $d3, $d4],
            'auto_project_future' => false,
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('created') ?? 0));
        $this->assertSame(2, (int) ($res->json('skipped_missing_session_count') ?? 0));
        $this->assertEqualsCanonicalizing([$d2, $d4], $res->json('skipped_missing_session_dates') ?? []);

        $this->assertDatabaseHas('LearningRecord', ['StudentClassID' => $courseId, 'SessionDate' => $d1]);
        $this->assertDatabaseHas('LearningRecord', ['StudentClassID' => $courseId, 'SessionDate' => $d3]);
        $this->assertDatabaseMissing('LearningRecord', ['StudentClassID' => $courseId, 'SessionDate' => $d2]);
        $this->assertDatabaseMissing('LearningRecord', ['StudentClassID' => $courseId, 'SessionDate' => $d4]);
    }

    public function test_learning_record_store_rejects_before_session_end_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-time-lock-store-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-time-lock-store-a@example.com');
        $student = $this->createStudent(1, '時間卡控測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => now()->toDateString(),
            'days_of_week' => [3],
            'start_time' => '15:00',
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '00:00',
            'EndTime' => '23:59',
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
            'SessionDate' => now()->toDateString(),
            'StartTime' => '00:00',
            'EndTime' => '23:59',
            'Content' => '尚未下課不應可填',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '課程尚未結束，課程結束後開放填寫評量表']);
    }

    public function test_learning_record_update_rejects_before_session_end_time(): void
    {
        $token = $this->createDirectorToken([1], 'director-time-lock-update-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-time-lock-update-a@example.com');
        $student = $this->createStudent(1, '時間卡控測試B');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'first_class_date' => now()->toDateString(),
            'days_of_week' => [3],
            'start_time' => '15:00',
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $classSession = ClassSession::create([
            'StudentClassID' => $courseId,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '00:00',
            'EndTime' => '23:59',
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
            'Content' => '尚未下課不應可修改',
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => '課程尚未結束，課程結束後開放填寫評量表']);
    }

    public function test_bulk_backdoor_approve_still_projects_future_sessions_without_historical_class_sessions(): void
    {
        $directorLogin = 'director-backfill-project-d@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backfill-project-d@example.com');
        $student = $this->createStudent(1, '補登推算測試D');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'days_of_week' => [6], // 週六
            'start_time' => '15:00',
            'skip_auto_sessions' => true,
            'first_class_date' => now()->subWeeks(8)->toDateString(),
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $first = now()->copy()->subWeeks(8)->startOfDay();
        while ((int) $first->dayOfWeekIso !== 6) {
            $first->addDay();
        }
        $d1 = $first->toDateString();
        $d2 = $first->copy()->addWeek()->toDateString();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => [$d1, $d2],
            'auto_project_future' => true,
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('created') ?? -1));
        $this->assertSame(0, (int) ($res->json('approved') ?? -1));
        $this->assertSame(0, (int) ($res->json('skipped_missing_session_count') ?? -1));
        $this->assertSame(2, (int) ($res->json('projected_future_count') ?? -1));

        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'SessionDate' => $d1]);
        $this->assertDatabaseHas('ClassSession', ['StudentClassID' => $courseId, 'SessionDate' => $d2]);
        $this->assertSame(4, ClassSession::where('StudentClassID', $courseId)->count());
    }

    public function test_ensure_past_creates_record_bound_to_existing_class_session(): void
    {
        $token = $this->createDirectorToken([1], 'director-ensure-past-a@example.com');
        $teacherId = $this->createTeacher(1, 'teacher-ensure-past-a@example.com');
        $student = $this->createStudent(1, '補齊評量測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [1],
            'start_time' => '16:00',
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

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

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 6,
            'remaining_sessions' => 6,
            'sessions_used' => 0,
            'first_class_date' => '2026-03-01',
            'days_of_week' => [2],
            'start_time' => '17:00',
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

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

    public function test_bulk_backdoor_approve_auto_projects_remaining_sessions_into_future(): void
    {
        $directorLogin = 'director-backfill-project-a@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backfill-project-a@example.com');
        $student = $this->createStudent(1, '補登推算測試A');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 8,
            'remaining_sessions' => 8,
            'sessions_used' => 0,
            'days_of_week' => [3], // 週三
            'start_time' => '16:00',
            'skip_auto_sessions' => true,
            'first_class_date' => now()->subWeeks(8)->toDateString(),
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $base = now()->copy()->subWeeks(6)->startOfDay();
        while ((int) $base->dayOfWeekIso !== 3) {
            $base->addDay();
        }

        $historyDates = [];
        for ($i = 0; $i < 6; $i++) {
            $d = $base->copy()->addWeeks($i)->toDateString();
            $historyDates[] = $d;
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => $d,
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => 'completed',
                'Note' => '',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => $historyDates,
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('projected_future_count') ?? 0));

        $lastHistorical = \Carbon\Carbon::parse(end($historyDates));
        $expected1 = $lastHistorical->copy()->addWeek()->toDateString();
        $expected2 = $lastHistorical->copy()->addWeeks(2)->toDateString();

        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected1,
            'Status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected2,
            'Status' => 'scheduled',
        ]);

        $course = StudentClass::findOrFail($courseId);
        $this->assertSame(2, (int) $course->RemainingSessions);
        $this->assertSame(6, (int) $course->UsedSessions);

        $approvedCount = LearningRecord::where('StudentClassID', $courseId)
            ->where('Status', 'approved')
            ->count();
        $this->assertSame(6, $approvedCount);
    }

    public function test_bulk_backdoor_approve_projection_ignores_historical_gaps_and_moves_forward_only(): void
    {
        $directorLogin = 'director-backfill-project-b@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backfill-project-b@example.com');
        $student = $this->createStudent(1, '補登推算測試B');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 4,
            'remaining_sessions' => 4,
            'sessions_used' => 0,
            'days_of_week' => [3], // 週三
            'start_time' => '16:00',
            'skip_auto_sessions' => true,
            'first_class_date' => now()->subWeeks(10)->toDateString(),
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $last = now()->copy()->subWeek()->startOfDay();
        while ((int) $last->dayOfWeekIso !== 3) {
            $last->subDay();
        }
        $first = $last->copy()->subWeeks(7);
        $gapDate = $last->copy()->subWeek()->toDateString(); // 歷史中間空檔（不應回補）
        $historyDates = [$first->toDateString(), $last->toDateString()];

        foreach ($historyDates as $d) {
            ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => $d,
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => 'completed',
                'Note' => '',
            ]);
        }

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => $historyDates,
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('projected_future_count') ?? 0));

        $expected1 = $last->copy()->addWeek()->toDateString();
        $expected2 = $last->copy()->addWeeks(2)->toDateString();
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected1,
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected2,
        ]);
        $this->assertDatabaseMissing('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $gapDate,
        ]);
    }

    public function test_bulk_backdoor_projection_caps_by_derived_remaining_when_remaining_sessions_is_stale(): void
    {
        $directorLogin = 'director-backfill-project-c@example.com';
        $token = $this->createDirectorToken([1], $directorLogin);
        $directorId = $this->getUserIdByLoginName($directorLogin);
        $teacherId = $this->createTeacher(1, 'teacher-backfill-project-c@example.com');
        $student = $this->createStudent(1, '補登推算測試C');

        $courseRes = $this->createCourseViaApi($token, $student->id, $teacherId, [
            'sessions_purchased' => 8,
            'remaining_sessions' => 8,
            'sessions_used' => 0,
            'days_of_week' => [3], // 週三
            'start_time' => '16:00',
            'skip_auto_sessions' => true,
            'first_class_date' => now()->subWeeks(10)->toDateString(),
        ])->assertCreated();
        $courseId = $this->resolveCourseId($courseRes, $student->id, $teacherId);

        $base = now()->copy()->subWeeks(6)->startOfDay();
        while ((int) $base->dayOfWeekIso !== 3) {
            $base->addDay();
        }

        $historyDates = [];
        for ($i = 0; $i < 6; $i++) {
            $date = $base->copy()->addWeeks($i)->toDateString();
            $historyDates[] = $date;

            $classSession = ClassSession::create([
                'StudentClassID' => $courseId,
                'SessionDate' => $date,
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => 'completed',
                'Note' => '',
            ]);

            LearningRecord::create([
                'StudentClassID' => $courseId,
                'ClassSessionID' => $classSession->id,
                'TeacherID' => $teacherId,
                'Content' => '已核准歷史評量',
                'Subject' => '數學',
                'SessionDate' => $date,
                'StartTime' => '16:00',
                'EndTime' => '18:00',
                'Status' => 'approved',
                'SessionDeducted' => 1,
                'ApprovedBy' => $directorId,
                'ApprovedAt' => now(),
            ]);
        }

        // 模擬舊資料：RemainingSessions 未同步，仍維持購買堂數。
        StudentClass::where('ID', $courseId)->update([
            'RemainingSessions' => 8,
            'UsedSessions' => 6,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/learning-records/bulk-backdoor-approve', [
            'StudentClassID' => $courseId,
            'TeacherID' => $teacherId,
            'DirectorID' => $directorId,
            'session_dates' => $historyDates,
        ])->assertCreated();

        $this->assertSame(2, (int) ($res->json('projected_future_count') ?? 0));
        $this->assertCount(2, $res->json('projected_future_dates') ?? []);

        $lastHistorical = \Carbon\Carbon::parse(end($historyDates));
        $expected1 = $lastHistorical->copy()->addWeek()->toDateString();
        $expected2 = $lastHistorical->copy()->addWeeks(2)->toDateString();
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected1,
            'Status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('ClassSession', [
            'StudentClassID' => $courseId,
            'SessionDate' => $expected2,
            'Status' => 'scheduled',
        ]);

        $futureProjectedCount = ClassSession::where('StudentClassID', $courseId)
            ->where('SessionDate', '>', $lastHistorical->toDateString())
            ->count();
        $this->assertSame(2, $futureProjectedCount);
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
    private function createCourseViaApi(string $token, int $studentId, int $teacherId, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $payload = array_merge([
            'student_id' => $studentId,
            'subject' => 'Math',
            'teacher_id' => $teacherId,
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

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/student-classes', $payload);
    }

    private function resolveCourseId(\Illuminate\Testing\TestResponse $courseRes, int $studentId, int $teacherId): int
    {
        $courseId = (int) ($courseRes->json('ID') ?? $courseRes->json('id') ?? 0);
        if ($courseId <= 0) {
            $courseId = (int) (DB::table('StudentClass')
                ->where('StudentID', $studentId)
                ->where('TeacherID', $teacherId)
                ->max('ID') ?? 0);
        }
        $this->assertTrue($courseId > 0, 'Course ID should be available.');

        return $courseId;
    }

    private function getUserIdByLoginName(string $loginName): int
    {
        $userId = (int) (DB::table('User')->where('LoginName', $loginName)->value('id') ?? 0);
        $this->assertTrue($userId > 0, 'Director ID should be available.');
        return $userId;
    }
}

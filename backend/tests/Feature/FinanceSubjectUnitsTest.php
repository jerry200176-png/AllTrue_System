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

class FinanceSubjectUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_units_defaults_missing_range_to_current_taipei_month(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Taipei'));
        try {
            $director = $this->createDirector('director-subject-units-range@example.com', [1]);
            $teacherId = $this->createTeacher(1, 'teacher-subject-units-range@example.com', '月份邊界老師');
            $student = $this->createStudent(1, '月份邊界學生');
            $regular = $this->createSubjectUnitCourse($student, $teacherId, 'one_on_one');
            $tutoring = $this->createSubjectUnitCourse($student, $teacherId, 'tutoring');

            foreach (['2026-06-10', '2026-07-10'] as $date) {
                $regularSession = ClassSession::create([
                    'StudentClassID' => $regular->ID,
                    'SessionDate' => $date,
                    'StartTime' => '16:00:00',
                    'EndTime' => '18:00:00',
                    'Status' => 'completed',
                    'Note' => '',
                ]);
                LearningRecord::create([
                    'StudentClassID' => $regular->ID,
                    'ClassSessionID' => $regularSession->id,
                    'TeacherID' => $teacherId,
                    'Content' => '月份範圍評量',
                    'Subject' => 'Math',
                    'Status' => 'approved',
                    'ApprovedBy' => $director['user_id'],
                    'ApprovedAt' => now(),
                    'SessionDate' => $date,
                    'StartTime' => '16:00:00',
                    'EndTime' => '18:00:00',
                    'SessionDeducted' => true,
                ]);
                ClassSession::create([
                    'StudentClassID' => $tutoring->ID,
                    'SessionDate' => $date,
                    'StartTime' => '18:00:00',
                    'EndTime' => '20:00:00',
                    'Status' => 'attended',
                    'Note' => '',
                ]);
            }

            $teachers = $this->withHeaders([
                'Authorization' => "Bearer {$director['token']}",
                'Accept' => 'application/json',
            ])->getJson('/api/v1/finance/subject-units?branch_id=1')
                ->assertOk()
                ->json('teachers');

            $row = collect($teachers)->firstWhere('teacher_id', $teacherId);
            $this->assertNotNull($row);
            $this->assertSame(2.0, (float) $row['one_on_one_hours']);
            $this->assertSame(2.0, (float) $row['tutoring_hours']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_subject_units_rejects_partial_or_reversed_explicit_range(): void
    {
        $director = $this->createDirector('director-subject-units-invalid-range@example.com', [1]);
        $headers = [
            'Authorization' => "Bearer {$director['token']}",
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->getJson('/api/v1/finance/subject-units?branch_id=1&start=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('end');

        $this->withHeaders($headers)
            ->getJson('/api/v1/finance/subject-units?branch_id=1&end=2026-07-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors('start');

        $this->withHeaders($headers)
            ->getJson('/api/v1/finance/subject-units?branch_id=1&start=2026-07-31&end=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('end');
    }

    public function test_subject_units_follow_learning_record_teacher_after_teacher_change(): void
    {
        $director = $this->createDirector('director-subject-units-a@example.com', [1]);
        $token = $director['token'];
        $directorId = $director['user_id'];
        $teacherA = $this->createTeacher(1, 'teacher-subject-units-a@example.com', '老師甲');
        $teacherB = $this->createTeacher(1, 'teacher-subject-units-b@example.com', '老師乙');
        $student = $this->createStudent(1, '科目數老師轉移測試A');

        $studentClass = StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => $teacherA,
            'GradeID' => 7,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 7,
            'UsedSessions' => 1,
            'Rate' => 500,
            'Charge' => 4000,
            'Pay' => 0,
            'Paid' => 0,
            'Period' => 4,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'StartDate' => now()->subWeek()->toDateString(),
            'EndDate' => now()->addWeek()->toDateString(),
            'week' => 2,
            'time' => '16:00:00',
            'Stop' => 0,
            'MDate' => now(),
        ]);

        $sessionDate = now()->subDay()->toDateString();
        $classSession = ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        $record = LearningRecord::create([
            'StudentClassID' => $studentClass->ID,
            'ClassSessionID' => $classSession->id,
            'TeacherID' => $teacherA,
            'Content' => '已核准評量',
            'Subject' => 'Math',
            'Status' => 'approved',
            'ApprovedBy' => $directorId,
            'ApprovedAt' => now(),
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);

        User::where('id', $teacherA)->update(['TeachingSessionCount' => 1]);
        User::where('id', $teacherB)->update(['TeachingSessionCount' => 0]);

        $before = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/finance/subject-units?branch_id=1&start={$sessionDate}&end={$sessionDate}")
            ->assertOk()
            ->json('teachers');

        $this->assertSame('老師甲', $before[0]['teacher_name'] ?? null);

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/learning-records/{$record->id}/teacher", [
            'TeacherID' => $teacherB,
            'reason' => '單堂代課調整',
        ])->assertOk();

        $this->assertDatabaseHas('learning_record_teacher_changes', [
            'learning_record_id' => $record->id,
            'old_teacher_id' => $teacherA,
            'new_teacher_id' => $teacherB,
            'changed_by' => $directorId,
            'reason' => '單堂代課調整',
        ]);

        $this->assertSame(0, (int) User::findOrFail($teacherA)->TeachingSessionCount);
        $this->assertSame(1, (int) User::findOrFail($teacherB)->TeachingSessionCount);

        $after = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/finance/subject-units?branch_id=1&start={$sessionDate}&end={$sessionDate}")
            ->assertOk()
            ->json('teachers');

        $this->assertCount(1, $after);
        $this->assertSame('老師乙', $after[0]['teacher_name'] ?? null);
        $this->assertSame(2.0, (float) ($after[0]['one_on_one_hours'] ?? 0));
    }

    /**
     * 老師只能看自己的科目數，不能從舊版彙總端點取得同事姓名或排名。
     *
     * 根因修復：routes/api.php 曾將 GET finance/subject-units 登錄在
     * role:director-only 群組中（第 226 行），導致 role:director,teacher 群組
     * 的同名路由（第 328 行）永遠被 Laravel 的 first-match-wins 機制跳過，
     * 老師呼叫時得到 403。已移除重複登錄，保留 role:director,teacher 版本。
     * Ref: Bug #7 / B1 偵查報告 2026-04-22。
     */
    public function test_teacher_sees_only_own_subject_units(): void
    {

        $teacherA = $this->createTeacherWithToken(1, 'teacher-subject-units-self-a@example.com', '老師甲');
        $teacherB = $this->createTeacherWithToken(1, 'teacher-subject-units-self-b@example.com', '老師乙');
        $studentA = $this->createStudent(1, '老師甲學生');
        $studentB = $this->createStudent(1, '老師乙學生');
        $sessionDate = now()->subDay()->toDateString();

        $classA = StudentClass::create([
            'StudentID' => $studentA->id,
            'TeacherID' => $teacherA['user_id'],
            'GradeID' => 7,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 7,
            'UsedSessions' => 1,
            'Rate' => 500,
            'Charge' => 4000,
            'Pay' => 0,
            'Paid' => 0,
            'Period' => 4,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'StartDate' => now()->subWeek()->toDateString(),
            'EndDate' => now()->addWeek()->toDateString(),
            'week' => 2,
            'time' => '16:00:00',
            'Stop' => 0,
            'MDate' => now(),
        ]);
        $classB = StudentClass::create([
            'StudentID' => $studentB->id,
            'TeacherID' => $teacherB['user_id'],
            'GradeID' => 7,
            'SubjectID' => 1,
            'ClassType' => 'one_on_two',
            'by1' => 2,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 7,
            'UsedSessions' => 1,
            'Rate' => 500,
            'Charge' => 4000,
            'Pay' => 0,
            'Paid' => 0,
            'Period' => 4,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'StartDate' => now()->subWeek()->toDateString(),
            'EndDate' => now()->addWeek()->toDateString(),
            'week' => 2,
            'time' => '16:00:00',
            'Stop' => 0,
            'MDate' => now(),
        ]);

        $sessionA = ClassSession::create([
            'StudentClassID' => $classA->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $sessionB = ClassSession::create([
            'StudentClassID' => $classB->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        LearningRecord::create([
            'StudentClassID' => $classA->ID,
            'ClassSessionID' => $sessionA->id,
            'TeacherID' => $teacherA['user_id'],
            'Content' => '老師甲評量',
            'Subject' => 'Math',
            'Status' => 'approved',
            'ApprovedBy' => $teacherA['user_id'],
            'ApprovedAt' => now(),
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);
        LearningRecord::create([
            'StudentClassID' => $classB->ID,
            'ClassSessionID' => $sessionB->id,
            'TeacherID' => $teacherB['user_id'],
            'Content' => '老師乙評量',
            'Subject' => 'English',
            'Status' => 'approved',
            'ApprovedBy' => $teacherB['user_id'],
            'ApprovedAt' => now(),
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'SessionDeducted' => true,
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$teacherA['token']}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/finance/subject-units?branch_id=1&start={$sessionDate}&end={$sessionDate}")
            ->assertOk();

        $teachers = collect($response->json('teachers'));
        $this->assertCount(1, $teachers);
        $rowA = $teachers->firstWhere('teacher_name', '老師甲');
        $this->assertNotNull($rowA, '老師甲 should appear in teacher list');

        // Caller sees their own hours in full.
        $this->assertTrue((bool) ($rowA['is_self'] ?? false), 'Calling teacher row must be marked is_self=true');
        $this->assertSame(2.0, (float) ($rowA['one_on_one_hours'] ?? 0), 'Self one_on_one_hours must not be redacted');
        $this->assertArrayNotHasKey('rank', $rowA, 'Teacher must not receive a peer ranking side channel');
        $this->assertSame(1, $response->json('total_teachers'));

        // Branch-wide totals are not exposed to the teacher role.
        $this->assertNull($response->json('totals'), 'totals must be absent from teacher-role response');
    }

    public function test_subject_units_excludes_records_flagged_exclude_from_subject_count(): void
    {
        // #137/#575：主任補登的空白評量（ExcludeFromSubjectCount=1）不應計入科目數/薪資時數。
        $director = $this->createDirector('director-exclude-subj@example.com', [1]);
        $token = $director['token'];
        $directorId = $director['user_id'];
        $teacher = $this->createTeacher(1, 'teacher-exclude-subj@example.com', '排除老師');
        $student = $this->createStudent(1, '排除科目數測試');

        $studentClass = StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => $teacher,
            'GradeID' => 7,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'Rate' => 500,
            'Charge' => 4000,
            'Pay' => 0,
            'Paid' => 0,
            'Period' => 4,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'StartDate' => now()->subWeek()->toDateString(),
            'EndDate' => now()->addWeek()->toDateString(),
            'week' => 2,
            'time' => '16:00:00',
            'Stop' => 0,
            'MDate' => now(),
        ]);

        $sessionDate = now()->subDay()->toDateString();
        $countedSession = ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'Status' => 'completed',
            'Note' => '',
        ]);
        $excludedSession = ClassSession::create([
            'StudentClassID' => $studentClass->ID,
            'SessionDate' => $sessionDate,
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'Status' => 'completed',
            'Note' => '',
        ]);

        // Normal approved assessment (2h) — must count.
        LearningRecord::create([
            'StudentClassID' => $studentClass->ID,
            'ClassSessionID' => $countedSession->id,
            'TeacherID' => $teacher,
            'Content' => '正常評量',
            'Subject' => 'Math',
            'Status' => 'approved',
            'ApprovedBy' => $directorId,
            'ApprovedAt' => now(),
            'SessionDate' => $sessionDate,
            'StartTime' => '16:00:00',
            'EndTime' => '18:00:00',
            'SessionDeducted' => true,
            'ExcludeFromSubjectCount' => 0,
        ]);
        // Backfilled blank assessment (2h) flagged exclude — must NOT count.
        LearningRecord::create([
            'StudentClassID' => $studentClass->ID,
            'ClassSessionID' => $excludedSession->id,
            'TeacherID' => $teacher,
            'Content' => '補登空白評量',
            'Subject' => 'Math',
            'Status' => 'approved',
            'ApprovedBy' => $directorId,
            'ApprovedAt' => now(),
            'SessionDate' => $sessionDate,
            'StartTime' => '18:00:00',
            'EndTime' => '20:00:00',
            'SessionDeducted' => true,
            'ExcludeFromSubjectCount' => 1,
        ]);

        $teachers = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/finance/subject-units?branch_id=1&start={$sessionDate}&end={$sessionDate}")
            ->assertOk()
            ->json('teachers');

        $this->assertCount(1, $teachers);
        // Only the 2h normal assessment counts; the excluded one's 2h must be dropped.
        $this->assertSame(2.0, (float) ($teachers[0]['one_on_one_hours'] ?? 0));
    }

    /**
     * @param  array<int>  $campusIds
     * @return array{token: string, user_id: int}
     */
    private function createDirector(string $loginName, array $campusIds): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
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

        return ['token' => $token, 'user_id' => (int) $user->id];
    }

    private function createTeacher(int $campusId, string $loginName, string $name): int
    {
        $teacher = User::create([
            'LoginName' => $loginName,
            'Name' => $name,
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
            'MustChangePassword' => false,
            'TeachingSessionCount' => 0,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $teacher->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        return (int) $teacher->id;
    }

    /**
     * @return array{token: string, user_id: int}
     */
    private function createTeacherWithToken(int $campusId, string $loginName, string $name): array
    {
        $teacherId = $this->createTeacher($campusId, $loginName, $name);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $teacherId,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return ['token' => $token, 'user_id' => $teacherId];
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 7,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    private function createSubjectUnitCourse(Student $student, int $teacherId, string $classType): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => $teacherId,
            'GradeID' => 7,
            'SubjectID' => 1,
            'ClassType' => $classType,
            'by1' => 1,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'RemainingSessions' => 6,
            'UsedSessions' => 2,
            'Rate' => 500,
            'Charge' => 4000,
            'Pay' => 0,
            'Paid' => 0,
            'Period' => 4,
            'SessionDuration' => 120,
            'TotalHours' => 16,
            'StartDate' => now()->subMonth()->toDateString(),
            'EndDate' => now()->addMonth()->toDateString(),
            'week' => 2,
            'time' => '16:00:00',
            'Stop' => 0,
            'MDate' => now(),
        ]);
    }
}

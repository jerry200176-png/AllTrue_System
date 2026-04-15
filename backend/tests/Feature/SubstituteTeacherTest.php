<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubstituteTeacherTest extends TestCase
{
    use RefreshDatabase;

    public function test_substitute_creates_schedules_and_updates_lr(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session, $lr] = $this->seedSubstituteScenario();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
            'reason' => '正班老師請假',
        ]);

        $res->assertOk()
            ->assertJsonFragment(['substitute_teacher_id' => $subTeacherId]);

        // schedules: rescheduled + scheduled pair
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'rescheduled',
        ]);
        $this->assertDatabaseHas('schedules', [
            'student_course_id' => $session->StudentClassID,
            'schedule_date' => '2026-04-19',
            'status' => 'scheduled',
            'teacher_id' => $subTeacherId,
        ]);

        // LearningRecord updated to sub teacher
        $lr->refresh();
        $this->assertEquals($subTeacherId, (int) $lr->TeacherID);

        // Audit row
        $this->assertDatabaseHas('learning_record_teacher_changes', [
            'learning_record_id' => $lr->id,
            'old_teacher_id' => $regularTeacherId,
            'new_teacher_id' => $subTeacherId,
        ]);
    }

    public function test_substitute_is_idempotent(): void
    {
        [$dirToken, , $subTeacherId, $session] = $this->seedSubstituteScenario();

        $call = fn () => $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ]);

        $call()->assertOk();
        $call()->assertOk();

        // Only one pair of schedules
        $this->assertEquals(1, Schedule::where('student_course_id', $session->StudentClassID)
            ->where('schedule_date', '2026-04-19')->where('status', 'rescheduled')->count());
        $this->assertEquals(1, Schedule::where('student_course_id', $session->StudentClassID)
            ->where('schedule_date', '2026-04-19')->where('status', 'scheduled')->count());
    }

    public function test_substitute_teacher_can_see_learning_record(): void
    {
        [$dirToken, , $subTeacherId, $session, $lr] = $this->seedSubstituteScenario();

        // Apply substitute
        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ])->assertOk();

        // Now query learning-records as the substitute teacher
        $subToken = $this->createTeacherToken($subTeacherId);
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$subToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $lr->id, $ids, 'Substitute teacher should see the LR assigned to them');
    }

    public function test_same_teacher_substitute_rejected(): void
    {
        [$dirToken, $regularTeacherId, , $session] = $this->seedSubstituteScenario();

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $regularTeacherId,
        ])->assertStatus(422);
    }

    /** 代課後即使 LR.TeacherID 被還原成正班，列表仍應讓代課老師看到；ensure-past 會自癒回代課。 */
    public function test_substitute_teacher_sees_learning_record_and_ensure_past_heals_teacher_id(): void
    {
        Carbon::setTestNow('2026-04-20 12:00:00');
        try {
            [$dirToken, $regularTeacherId, $subTeacherId, $session, $lr] = $this->seedSubstituteScenario();

            $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
                'substitute_teacher_id' => $subTeacherId,
            ])->assertOk();

            DB::table('LearningRecord')->where('id', $lr->id)->update([
                'TeacherID' => $regularTeacherId,
            ]);

            $subToken = $this->createTeacherToken($subTeacherId);
            $list = $this->withHeaders([
                'Authorization' => "Bearer {$subToken}",
                'Accept' => 'application/json',
            ])->getJson('/api/v1/learning-records?per_page=50');
            $list->assertOk();
            $ids = collect($list->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
            $this->assertContains((int) $lr->id, $ids);

            $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson('/api/v1/learning-records/ensure-past', ['branch_id' => 1])->assertOk();

            $lr->refresh();
            $this->assertEquals($subTeacherId, (int) $lr->TeacherID);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 核准後科目數（subject-units）依 LearningRecord.TeacherID 歸代課老師。 */
    public function test_subject_units_credits_substitute_teacher_after_approve(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session, $lr] = $this->seedSubstituteScenario();
        $directorId = (int) User::where('LoginName', 'dir-sub@example.com')->value('id');

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ])->assertOk();

        $lr->refresh();
        $this->assertEquals($subTeacherId, (int) $lr->TeacherID);

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/learning-records/{$lr->id}/approve", ['DirectorID' => $directorId])
            ->assertOk();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/finance/subject-units?branch_id=1&start=2026-04-19&end=2026-04-19');
        $res->assertOk();
        $teachers = collect($res->json('teachers'));
        $subRow = $teachers->firstWhere('teacher_id', $subTeacherId);
        $this->assertNotNull($subRow, '代課老師應出現在科目數統計');
        $this->assertGreaterThan(0, (float) ($subRow['total_hours'] ?? 0));
        $regRow = $teachers->firstWhere('teacher_id', $regularTeacherId);
        $this->assertTrue(
            $regRow === null || (float) ($regRow['total_hours'] ?? 0) < 0.01,
            '正班老師不應因該筆已核可評量而計入同一堂之時數'
        );
    }

    /** 與正式環境一致：該堂尚無 LearningRecord 時仍應完成代課（只寫 schedules）。 */
    public function test_substitute_ok_when_no_learning_record_row(): void
    {
        [$dirToken, , $subTeacherId, $session] = $this->seedSubstituteScenarioWithoutLearningRecord();

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
            'reason' => '無評量列',
        ])->assertOk()
            ->assertJsonFragment(['substitute_teacher_id' => $subTeacherId, 'learning_record_id' => null]);

        $this->assertEquals(0, DB::table('LearningRecord')->where('ClassSessionID', $session->id)->count());
    }

    /** 點名列表：teacher_id / teacher_name 應為代課老師；代課老師可查到此堂。 */
    public function test_class_sessions_index_reflects_substitute_for_roll_call(): void
    {
        [$dirToken, $regularTeacherId, $subTeacherId, $session] = $this->seedSubstituteScenarioWithoutLearningRecord();

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ])->assertOk();

        $subToken = $this->createTeacherToken($subTeacherId);
        $idx = $this->withHeaders([
            'Authorization' => "Bearer {$subToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-04-20&end=2026-04-20&per_page=100');

        $idx->assertOk();
        $row = collect($idx->json('data'))->firstWhere('id', $session->id);
        $this->assertNotNull($row, 'Substitute teacher should see the session in class-sessions index');
        $this->assertSame($subTeacherId, (int) ($row['teacher_id'] ?? 0));
        $this->assertSame('代課2', $row['teacher_name'] ?? '');

        $regToken = $this->createTeacherToken($regularTeacherId);
        $idxReg = $this->withHeaders([
            'Authorization' => "Bearer {$regToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/class-sessions?start=2026-04-20&end=2026-04-20&per_page=100');
        $idxReg->assertOk();
        $rowReg = collect($idxReg->json('data'))->firstWhere('id', $session->id);
        $this->assertNotNull($rowReg, 'Regular teacher should still see the session');
        $this->assertSame($subTeacherId, (int) ($rowReg['teacher_id'] ?? 0));
        $this->assertSame('代課2', $rowReg['teacher_name'] ?? '');
    }

    /** 學習評量頁老師課表：依 GET student-classes 建 classIds；代課-only 課程須列入。 */
    public function test_substitute_teacher_student_classes_index_includes_substitute_course(): void
    {
        [$dirToken, , $subTeacherId, $session] = $this->seedSubstituteScenarioWithoutLearningRecord();
        $courseId = (int) $session->StudentClassID;

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ])->assertOk();

        $subToken = $this->createTeacherToken($subTeacherId);
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$subToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/student-classes?status=active&per_page=200');

        $res->assertOk();
        $ids = collect($res->json('data'))
            ->map(fn ($row) => (int) ($row['id'] ?? $row['ID'] ?? 0))
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $this->assertContains($courseId, $ids, 'Substitute-only course should appear in student-classes index for teacher');
    }

    /** 智慧排課老師端會帶 teacher_id=自己；不得與「僅代課」之 StudentClass.TeacherID（正班）衝突而漏列。 */
    public function test_teacher_student_classes_index_with_teacher_id_param_includes_substitute_only_course(): void
    {
        [$dirToken, , $subTeacherId, $session] = $this->seedSubstituteScenarioWithoutLearningRecord();
        $courseId = (int) $session->StudentClassID;

        $this->withHeaders([
            'Authorization' => "Bearer {$dirToken}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $subTeacherId,
        ])->assertOk();

        $subToken = $this->createTeacherToken($subTeacherId);
        $res = $this->withHeaders([
            'Authorization' => "Bearer {$subToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/student-classes?teacher_id={$subTeacherId}&status=active&per_page=200");

        $res->assertOk();
        $ids = collect($res->json('data'))
            ->map(fn ($row) => (int) ($row['id'] ?? $row['ID'] ?? 0))
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();
        $this->assertContains($courseId, $ids);
    }

    /** 歷史堂次（已上）即使代課老師同時段有既有一對一占用，仍應可代課（bypass guard）。 */
    public function test_substitute_allows_past_session_even_when_capacity_would_conflict(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        try {
            [$dirToken, $regularTeacherId, $subTeacherId, $scId] = $this->seedCapacityConflictScenario();

            $pastSession = ClassSession::create([
                'StudentClassID' => $scId,
                'SessionDate' => '2026-04-12',
                'StartTime' => '16:30',
                'EndTime' => '18:30',
                'Status' => 'attended',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson("/api/v1/class-sessions/{$pastSession->id}/substitute", [
                'substitute_teacher_id' => $subTeacherId,
                'reason' => '歷史堂次換代課',
            ]);

            $res->assertOk()
                ->assertJsonFragment(['substitute_teacher_id' => $subTeacherId]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 未來堂次遇到容量衝突時，仍應被擋（409），且回傳含 conflicts 明細。 */
    public function test_substitute_future_session_still_blocked_on_capacity_conflict(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        try {
            [$dirToken, $regularTeacherId, $subTeacherId, $scId] = $this->seedCapacityConflictScenario();

            $futureSession = ClassSession::create([
                'StudentClassID' => $scId,
                'SessionDate' => '2026-04-19',
                'StartTime' => '16:30',
                'EndTime' => '18:30',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson("/api/v1/class-sessions/{$futureSession->id}/substitute", [
                'substitute_teacher_id' => $subTeacherId,
            ]);

            $res->assertStatus(409)
                ->assertJsonStructure(['message', 'conflicts']);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 未來堂次無衝突時，代課應正常成功（不誤傷）。 */
    public function test_substitute_future_session_without_conflict_succeeds(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        try {
            [$dirToken, , $subTeacherId, $scId] = $this->seedCapacityConflictScenario();

            // Remove the conflicting other-student session so substitute teacher has no occupancy
            DB::table('ClassSession')
                ->where('StudentClassID', '!=', $scId)
                ->where('SessionDate', '2026-04-19')
                ->delete();
            DB::table('schedules')
                ->where('schedule_date', '2026-04-19')
                ->delete();

            $futureSession = ClassSession::create([
                'StudentClassID' => $scId,
                'SessionDate' => '2026-04-19',
                'StartTime' => '16:30',
                'EndTime' => '18:30',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson("/api/v1/class-sessions/{$futureSession->id}/substitute", [
                'substitute_teacher_id' => $subTeacherId,
            ]);

            $res->assertOk()
                ->assertJsonFragment(['substitute_teacher_id' => $subTeacherId]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** 409 回應應包含 overlap_details 讓 PM/客服可追查來源。 */
    public function test_substitute_conflict_response_contains_overlap_details(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        try {
            [$dirToken, , $subTeacherId, $scId] = $this->seedCapacityConflictScenario();

            $futureSession = ClassSession::create([
                'StudentClassID' => $scId,
                'SessionDate' => '2026-04-19',
                'StartTime' => '16:30',
                'EndTime' => '18:30',
                'Status' => 'scheduled',
            ]);

            $res = $this->withHeaders([
                'Authorization' => "Bearer {$dirToken}",
                'Accept' => 'application/json',
            ])->postJson("/api/v1/class-sessions/{$futureSession->id}/substitute", [
                'substitute_teacher_id' => $subTeacherId,
            ]);

            $res->assertStatus(409);
            $json = $res->json();
            $this->assertArrayHasKey('conflicts', $json);
            $this->assertNotEmpty($json['conflicts']);
            $first = $json['conflicts'][0];
            $this->assertArrayHasKey('overlap_details', $first);
            $this->assertNotEmpty($first['overlap_details']);
        } finally {
            Carbon::setTestNow();
        }
    }

    // ──────────────────────────────────────────────────────────

    /**
     * Seed a scenario where the substitute teacher already has a one_on_one student
     * at 16:30-18:30 on 2026-04-19 (and 2026-04-12 in the past), creating a capacity conflict.
     *
     * @return array{0: string, 1: int, 2: int, 3: int}  [dirToken, regularTeacherId, subTeacherId, targetStudentClassId]
     */
    private function seedCapacityConflictScenario(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-cap@example.com', 'Name' => '主任Cap', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900100001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular-cap@example.com', 'Name' => '正班Cap', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900100002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub-cap@example.com', 'Name' => '代課Cap', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900100003', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        // The student whose session we want to substitute
        $studentA = Student::create([
            'name' => '目標學生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scA = StudentClass::create([
            'StudentID' => $studentA->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        // Another student already taught by the substitute teacher at the same time slot
        $studentB = Student::create([
            'name' => '占用學生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $scB = StudentClass::create([
            'StudentID' => $studentB->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $sub->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        // The occupying sessions at the conflicting time
        ClassSession::create([
            'StudentClassID' => $scB->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '16:30', 'EndTime' => '18:30', 'Status' => 'scheduled',
        ]);
        ClassSession::create([
            'StudentClassID' => $scB->ID, 'SessionDate' => '2026-04-12',
            'StartTime' => '16:30', 'EndTime' => '18:30', 'Status' => 'attended',
        ]);

        return [$dirToken, (int) $regular->id, (int) $sub->id, (int) $scA->ID];
    }

    private function seedSubstituteScenario(): array
    {
        $campusId = 1;

        // Director
        $director = User::create([
            'LoginName' => 'dir-sub@example.com', 'Name' => '主任', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        // Regular teacher
        $regular = User::create([
            'LoginName' => 'regular@example.com', 'Name' => '正班老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        // Substitute teacher
        $sub = User::create([
            'LoginName' => 'sub@example.com', 'Name' => '代課老師', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000003', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        // Student + Course
        $student = Student::create([
            'name' => '代課測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-19',
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        $lr = LearningRecord::create([
            'StudentClassID' => $sc->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $regular->id, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => '2026-04-19', 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        return [$dirToken, (int) $regular->id, (int) $sub->id, $session, $lr];
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: ClassSession}
     */
    private function seedSubstituteScenarioWithoutLearningRecord(): array
    {
        $campusId = 1;

        $director = User::create([
            'LoginName' => 'dir-sub2@example.com', 'Name' => '主任2', 'PSW' => 'x',
            'type' => 'A', 'phone' => '0900000011', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $regular = User::create([
            'LoginName' => 'regular2@example.com', 'Name' => '正班2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000012', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $regular->id, 'Admin' => 0, 'Approved' => 1]);

        $sub = User::create([
            'LoginName' => 'sub2@example.com', 'Name' => '代課2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0900000013', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $sub->id, 'Admin' => 0, 'Approved' => 1]);

        $student = Student::create([
            'name' => '代課測無評量', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $regular->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 16,
            'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6, 'UsedSessions' => 2,
            'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'RoomID' => '1', 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $session = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => '2026-04-20',
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        return [$dirToken, (int) $regular->id, (int) $sub->id, $session];
    }

    private function createTeacherToken(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}

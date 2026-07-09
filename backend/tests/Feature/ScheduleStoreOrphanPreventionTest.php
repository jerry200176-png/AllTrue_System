<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-001 / FR-003 テスト：ScheduleController::store の ClassSession 存在性ガード
 *
 * Case 1: status=scheduled + original_schedule_id あり + ClassSession あり → 201 (Happy Path)
 * Case 2: status=scheduled + original_schedule_id あり + ClassSession なし  → 422 code=no_class_session
 * Case 3: status=leave（FR-003 不適用路徑）                                 → 201 (既存 leave フロー通過)
 */
class ScheduleStoreOrphanPreventionTest extends TestCase
{
    use RefreshDatabase;

    private string $dirToken;
    private int $teacherId;
    private int $studentId;
    private int $courseId;
    private int $campusId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $director = User::create([
            'LoginName' => 'dir-orphan@example.com',
            'Name' => '主任孤兒測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911222333',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->dirToken = $token;

        $teacher = User::create([
            'LoginName' => 'teacher-orphan@example.com',
            'Name' => '代課老師孤兒',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922333444',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $this->campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $this->teacherId = (int) $teacher->id;

        $student = Student::create([
            'name' => '孤兒學生',
            'CampusID' => $this->campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $this->studentId = (int) $student->id;

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacher->id,
            'ClassType' => 'one_on_one',
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 8,
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Charge' => 800,
            'Pay' => 3200,
            'Paid' => 0,
            'Rate' => 800,
            'Stop' => 0,
            'MDate' => now(),
            'ScheduleMode' => 'count',
        ]);
        $this->courseId = (int) $sc->ID;
    }

    /**
     * Case 1: ClassSession あり → 201
     * FR-001 ガードを通過して schedule 行が作成される。
     */
    public function test_store_scheduled_with_class_session_returns_201(): void
    {
        // ClassSession を先に作成する
        $anchorSchedule = Schedule::create([
            'student_id'    => $this->studentId,
            'teacher_id'    => $this->teacherId,
            'day_of_week'   => 3,
            'start_time'    => '10:00',
            'end_time'      => '12:00',
            'branch_id'     => $this->campusId,
            'schedule_date' => '2026-05-01',
            'status'        => 'rescheduled',
            'class_type'    => 'one_on_one',
        ]);

        ClassSession::create([
            'StudentClassID' => $this->courseId,
            'SessionDate'    => '2026-05-01',
            'StartTime'      => '10:00',
            'EndTime'        => '12:00',
            'Status'         => 'scheduled',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id'           => $this->studentId,
            'teacher_id'           => $this->teacherId,
            'day_of_week'          => 3,
            'start_time'           => '10:00',
            'end_time'             => '12:00',
            'branch_id'            => $this->campusId,
            'schedule_date'        => '2026-05-01',
            'status'               => 'scheduled',
            'class_type'           => 'one_on_one',
            'student_course_id'    => $this->courseId,
            'original_schedule_id' => $anchorSchedule->id,
        ]);

        $response->assertStatus(201);
    }

    /**
     * Case 2: ClassSession なし → 422 code=no_class_session
     * FR-001 ガードが孤兒行の書き込みを阻止する。
     */
    public function test_store_scheduled_without_class_session_returns_422(): void
    {
        // anchor schedule（rescheduled）は存在するが、ClassSession はない
        $anchorSchedule = Schedule::create([
            'student_id'    => $this->studentId,
            'teacher_id'    => $this->teacherId,
            'day_of_week'   => 3,
            'start_time'    => '10:00',
            'end_time'      => '12:00',
            'branch_id'     => $this->campusId,
            'schedule_date' => '2026-05-02',
            'status'        => 'rescheduled',
            'class_type'    => 'one_on_one',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id'           => $this->studentId,
            'teacher_id'           => $this->teacherId,
            'day_of_week'          => 3,
            'start_time'           => '10:00',
            'end_time'             => '12:00',
            'branch_id'            => $this->campusId,
            'schedule_date'        => '2026-05-02',
            'status'               => 'scheduled',
            'class_type'           => 'one_on_one',
            'student_course_id'    => $this->courseId,
            'original_schedule_id' => $anchorSchedule->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['code' => 'no_class_session']);

        // DB には書き込まれていないことを確認
        $this->assertDatabaseMissing('schedules', [
            'student_course_id' => $this->courseId,
            'schedule_date'     => '2026-05-02',
            'status'            => 'scheduled',
        ]);
    }

    /**
     * Case 3: status=leave は FR-001 の検証対象外（FR-003）
     * 既存の leave フローが通過することを確認。
     */
    public function test_store_leave_bypasses_class_session_guard(): void
    {
        // leave には ClassSession 存在性ガードを適用しない
        // ただし leave フローは ClassSession が存在することを前提とする（別ロジック）
        ClassSession::create([
            'StudentClassID' => $this->courseId,
            'SessionDate'    => '2026-05-10',
            'StartTime'      => '10:00',
            'EndTime'        => '12:00',
            'Status'         => 'scheduled',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->dirToken}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/schedules', [
            'student_id'        => $this->studentId,
            'teacher_id'        => $this->teacherId,
            'day_of_week'       => 6,
            'start_time'        => '10:00',
            'end_time'          => '12:00',
            'branch_id'         => $this->campusId,
            'schedule_date'     => '2026-05-10',
            'status'            => 'leave',
            'class_type'        => 'one_on_one',
            'student_course_id' => $this->courseId,
            // FR-003: leave 路徑無 original_schedule_id，不觸發 FR-001 ガード
        ]);

        // leave フローは 201 を返す（FR-001 ガードをスキップ）
        $response->assertStatus(201);
    }
}

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
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrueFitApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Taipei']);
        Carbon::setTestNow(Carbon::parse('2026-09-16 09:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_truefit_endpoint_hidden_when_feature_flag_off(): void
    {
        config(['perfflags.truefit_v1' => false]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'truefit-off@example.com', $campus);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertNotFound();
    }

    public function test_teacher_sees_only_own_today_sessions(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus('TrueFit 大安');
        $teacherA = $this->makeStaff('T', 'truefit-teacher-a@example.com', $campus);
        $teacherB = $this->makeStaff('T', 'truefit-teacher-b@example.com', $campus);
        $studentA = $this->makeStudent('學生甲', $campus);
        $studentB = $this->makeStudent('學生乙', $campus);
        $courseA = $this->makeClass($studentA->id, $teacherA['user']->id, $campus);
        $courseB = $this->makeClass($studentB->id, $teacherB['user']->id, $campus);

        $sessionA = $this->makeSession($courseA->ID, '2026-09-16', '10:00:00', '12:00:00');
        $this->makeSession($courseB->ID, '2026-09-16', '14:00:00', '16:00:00');

        $response = $this->withAuth($teacherA['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertOk()
            ->assertJsonPath('meta.date', '2026-09-16')
            ->assertJsonPath('meta.teacher_id', $teacherA['user']->id)
            ->assertJsonPath('meta.read_mode', 'pure_read')
            ->assertJsonPath('meta.completeness', 'materialized_plus_projected')
            ->assertJsonPath('meta.count', 1);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($sessionA->id, $data[0]['class_session_id']);
        $this->assertSame('materialized', $data[0]['session_kind']);
        $this->assertSame('學生甲', $data[0]['student_name']);
        $this->assertSame('10:00', $data[0]['start_time']);
        $this->assertSame('TrueFit 大安', $data[0]['campus_name']);
    }

    public function test_director_cannot_access_truefit_workspace_api(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'truefit-director@example.com', $campus);

        $this->withAuth($director['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertForbidden();
    }

    public function test_teacher_cannot_query_other_campus(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campusA = $this->makeCampus();
        $campusB = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'truefit-scope@example.com', $campusA);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/today-sessions?branch_id=' . $campusB)
            ->assertForbidden();
    }

    public function test_truefit_read_does_not_write_operational_tables(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'truefit-readonly@example.com', $campus);
        $student = $this->makeStudent('唯讀學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $this->makeSession($course->ID, '2026-09-16', '15:00:00', '17:00:00');

        $lrBefore = LearningRecord::query()->count();
        $sessionBefore = ClassSession::query()->count();
        $courseBefore = StudentClass::query()->where('ID', $course->ID)->value('RemainingSessions');

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.read_mode', 'pure_read');

        $this->assertSame($lrBefore, LearningRecord::query()->count());
        $this->assertSame($sessionBefore, ClassSession::query()->count());
        $this->assertSame($courseBefore, StudentClass::query()->where('ID', $course->ID)->value('RemainingSessions'));
    }

    public function test_truefit_skips_class_session_auto_materialization_while_class_sessions_index_may_materialize(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'truefit-pure-read@example.com', $campus);
        $student = $this->makeStudent('排程學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $course->ScheduleMode = 'count';
        $course->save();

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher['user']->id,
            'subject' => '數學',
            'day_of_week' => (int) Carbon::parse('2026-09-16')->dayOfWeekIso,
            'start_time' => '10:00',
            'end_time' => '12:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campus,
            'schedule_date' => '2026-09-16',
            'student_course_id' => $course->ID,
        ]);

        $this->assertSame(0, ClassSession::query()->where('StudentClassID', $course->ID)->count());

        $response = $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertOk()
            ->assertJsonPath('meta.read_mode', 'pure_read')
            ->assertJsonPath('meta.completeness', 'materialized_plus_projected')
            ->assertJsonPath('meta.count', 1);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertNull($data[0]['class_session_id']);
        $this->assertSame($course->ID, $data[0]['student_class_id']);
        $this->assertSame('projected', $data[0]['session_kind']);
        $this->assertSame('schedule_exception', $data[0]['source']);
        $this->assertSame('10:00', $data[0]['start_time']);

        $this->assertSame(0, ClassSession::query()->where('StudentClassID', $course->ID)->count());

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/class-sessions?start=2026-09-16&end=2026-09-16')
            ->assertOk();

        $this->assertSame(1, ClassSession::query()->where('StudentClassID', $course->ID)->count());
    }

    public function test_truefit_includes_monthly_projected_slot_without_materialization(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'truefit-monthly-proj@example.com', $campus);
        $student = $this->makeStudent('月結學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $course->ScheduleMode = 'date';
        $course->week = (int) Carbon::parse('2026-09-16')->dayOfWeekIso;
        $course->time = '14:00';
        $course->StartDate = '2026-08-01';
        $course->EndDate = '2026-12-31';
        $course->save();

        $this->assertSame(0, ClassSession::query()->where('StudentClassID', $course->ID)->count());

        $response = $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/today-sessions')
            ->assertOk()
            ->assertJsonPath('meta.count', 1);

        $data = $response->json('data');
        $this->assertNull($data[0]['class_session_id']);
        $this->assertSame('projected', $data[0]['session_kind']);
        $this->assertSame('contract_projection', $data[0]['source']);
        $this->assertSame('14:00', $data[0]['start_time']);
        $this->assertSame(0, ClassSession::query()->where('StudentClassID', $course->ID)->count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        config(['perfflags.truefit_v1' => true]);

        $this->getJson('/api/v1/truefit/today-sessions')
            ->assertUnauthorized();
    }

    private function makeCampus(string $name = null): int
    {
        return (int) CampusFactory::new()->create([
            'name' => $name ?: ('TrueFit 分校 ' . Str::random(5)),
        ])->id;
    }

    /** @return array{token:string,user:User} */
    private function makeStaff(string $type, string $login, int $campus): array
    {
        $user = User::create([
            'LoginName' => $login,
            'Name' => $type === 'A' ? 'TrueFit 主任' : 'TrueFit 老師',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $campus,
            'UserID' => $user->id,
            'Admin' => $type === 'A' ? 1 : 0,
            'Approved' => 1,
        ]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['token' => $token, 'user' => $user];
    }

    private function makeStudent(string $name, int $campus): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campus,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);
    }

    private function makeClass(int $studentId, int $teacherId, int $campus): StudentClass
    {
        $class = new StudentClass();
        $class->StudentID = $studentId;
        $class->TeacherID = $teacherId;
        $class->GradeID = 1;
        $class->SubjectID = 1;
        $class->by1 = $campus;
        $class->StartDate = '2026-08-01';
        $class->TotalHours = 20;
        $class->Charge = 1000;
        $class->Pay = 0;
        $class->Paid = 0;
        $class->Rate = 1000;
        $class->Stop = 0;
        $class->SessionCount = 10;
        $class->RemainingSessions = 10;
        $class->UsedSessions = 0;
        $class->SessionDuration = 60;
        $class->ClassType = 'one_on_one';
        $class->save();

        return $class->fresh();
    }

    private function makeSession(int $studentClassId, string $date, string $start, string $end): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId,
            'SessionDate' => $date,
            'StartTime' => $start,
            'EndTime' => $end,
            'Status' => 'scheduled',
        ]);
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}

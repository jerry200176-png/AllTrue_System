<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\TrueFitLessonPrep;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TrueFit\TrueFitTeacherBriefContract;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrueFitTeacherBriefApiTest extends TestCase
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

    public function test_material_units_hidden_when_flag_off(): void
    {
        config(['perfflags.truefit_v1' => false]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-brief-off@example.com', $campus);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/material-units')
            ->assertNotFound();
    }

    public function test_teacher_can_list_synthetic_material_units(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-brief-units@example.com', $campus);

        $response = $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/material-units')
            ->assertOk()
            ->assertJsonPath('meta.catalog', 'synthetic');

        $data = $response->json('data');
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('key', $data[0]);
        $this->assertArrayHasKey('title', $data[0]);
    }

    public function test_generate_fixture_teacher_brief_persists_structured_contract(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-brief-gen@example.com', $campus);
        $student = $this->makeStudent('學生甲', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '10:00:00', '11:00:00');

        $lrBefore = LearningRecord::query()->count();
        $scRemaining = (int) $course->fresh()->RemainingSessions;

        $response = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/lesson-preps/generate', [
                'class_session_id' => $session->id,
                'material_unit_key' => 'syn.math.fractions.v1',
            ])
            ->assertCreated();

        $brief = $response->json('data.brief');
        $this->assertIsArray($brief);
        foreach (TrueFitTeacherBriefContract::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $brief, "missing {$key}");
        }
        $this->assertSame('fixture', $response->json('data.brief_provider'));
        $this->assertDatabaseCount('truefit_lesson_preps', 1);
        $this->assertSame($lrBefore, LearningRecord::query()->count());
        $this->assertSame($scRemaining, (int) $course->fresh()->RemainingSessions);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/lesson-preps?class_session_id=' . $session->id)
            ->assertOk()
            ->assertJsonPath('data.material_unit_key', 'syn.math.fractions.v1');
    }

    public function test_teacher_cannot_generate_brief_for_other_teachers_session(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacherA = $this->makeStaff('T', 'tf-brief-a@example.com', $campus);
        $teacherB = $this->makeStaff('T', 'tf-brief-b@example.com', $campus);
        $student = $this->makeStudent('學生乙', $campus);
        $course = $this->makeClass($student->id, $teacherA['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '14:00:00', '15:00:00');

        $this->withAuth($teacherB['token'])
            ->postJson('/api/v1/truefit/lesson-preps/generate', [
                'class_session_id' => $session->id,
                'material_unit_key' => 'syn.math.fractions.v1',
            ])
            ->assertStatus(422);

        $this->assertSame(0, TrueFitLessonPrep::query()->count());
    }

    public function test_projected_session_can_generate_brief_without_class_session_id(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-brief-proj@example.com', $campus);
        $student = $this->makeStudent('學生丙', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);

        $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/lesson-preps/generate', [
                'student_class_id' => $course->ID,
                'session_date' => '2026-09-16',
                'start_time' => '16:00',
                'material_unit_key' => 'syn.eng.phonics.short-vowels.v1',
                'subject_name' => '英文',
            ])
            ->assertCreated()
            ->assertJsonPath('data.class_session_id', null)
            ->assertJsonPath('data.material_unit_key', 'syn.eng.phonics.short-vowels.v1');
    }

    public function test_director_cannot_access_teacher_brief_apis(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $director = $this->makeStaff('A', 'tf-brief-dir@example.com', $campus);

        $this->withAuth($director['token'])
            ->getJson('/api/v1/truefit/material-units')
            ->assertForbidden();
    }

    private function makeCampus(string $name = null): int
    {
        return (int) CampusFactory::new()->create([
            'name' => $name ?: ('TrueFit Brief ' . Str::random(5)),
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

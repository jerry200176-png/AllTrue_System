<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\TrueFitObservation;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TrueFit\TrueFitTeacherObservationContract;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrueFitObservationApiTest extends TestCase
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

    public function test_observations_hidden_when_flag_off(): void
    {
        config(['perfflags.truefit_v1' => false]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-obs-off@example.com', $campus);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/observations?class_session_id=1')
            ->assertNotFound();
    }

    public function test_teacher_can_upsert_and_read_structured_observation(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-obs-save@example.com', $campus);
        $student = $this->makeStudent('觀察學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '10:00:00', '11:00:00');

        $lrBefore = LearningRecord::query()->count();
        $remaining = (int) $course->fresh()->RemainingSessions;

        $response = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/observations', [
                'class_session_id' => $session->id,
                'observation' => $this->observationBody(),
            ])
            ->assertCreated();

        $obs = $response->json('data.observation');
        $this->assertIsArray($obs);
        foreach (TrueFitTeacherObservationContract::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $obs, "missing {$key}");
        }
        $this->assertSame(['class_session_id' => $session->id], $obs['session_ref']);
        $this->assertDatabaseCount('truefit_observations', 1);
        $this->assertSame($lrBefore, LearningRecord::query()->count());
        $this->assertSame($remaining, (int) $course->fresh()->RemainingSessions);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/observations?class_session_id=' . $session->id)
            ->assertOk()
            ->assertJsonPath('data.confidence', 'medium')
            ->assertJsonPath('data.observation.schema_version', TrueFitTeacherObservationContract::SCHEMA_VERSION);
    }

    public function test_teacher_cannot_observe_other_teachers_session(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacherA = $this->makeStaff('T', 'tf-obs-a@example.com', $campus);
        $teacherB = $this->makeStaff('T', 'tf-obs-b@example.com', $campus);
        $student = $this->makeStudent('觀察學生乙', $campus);
        $course = $this->makeClass($student->id, $teacherA['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '14:00:00', '15:00:00');

        $this->withAuth($teacherB['token'])
            ->postJson('/api/v1/truefit/observations', [
                'class_session_id' => $session->id,
                'observation' => $this->observationBody(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, TrueFitObservation::query()->count());
    }

    public function test_projected_session_observation_without_class_session_id(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-obs-proj@example.com', $campus);
        $student = $this->makeStudent('觀察學生丙', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);

        $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/observations', [
                'student_class_id' => $course->ID,
                'session_date' => '2026-09-16',
                'start_time' => '16:00',
                'observation' => $this->observationBody(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.class_session_id', null)
            ->assertJsonPath('data.observation.session_ref.student_class_id', $course->ID);
    }

    public function test_invalid_observation_payload_rejected(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-obs-bad@example.com', $campus);
        $student = $this->makeStudent('觀察學生丁', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '11:00:00', '12:00:00');

        $bad = $this->observationBody();
        unset($bad['confidence']);

        $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/observations', [
                'class_session_id' => $session->id,
                'observation' => $bad,
            ])
            ->assertStatus(422);

        $this->assertSame(0, TrueFitObservation::query()->count());
    }

    /** @return array<string, mixed> */
    private function observationBody(): array
    {
        return [
            'objectives_touched' => ['辨識分數加減'],
            'student_moves' => ['先通分再加減'],
            'struggle_signals' => [
                ['label' => '通分', 'signal' => '分母相加', 'linked_objective' => '辨識分數加減'],
            ],
            'strength_signals' => ['能口述題意'],
            'misconception_hypotheses' => [
                [
                    'label' => '分母規則混淆',
                    'what_student_seemed_to_believe' => '分母可以直接相加',
                    'what_to_check_next' => '給同分母對照題',
                ],
            ],
            'evidence_notes' => '課堂板書片段',
            'confidence' => 'medium',
        ];
    }

    private function makeCampus(string $name = null): int
    {
        return (int) CampusFactory::new()->create([
            'name' => $name ?: ('TrueFit Obs ' . Str::random(5)),
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

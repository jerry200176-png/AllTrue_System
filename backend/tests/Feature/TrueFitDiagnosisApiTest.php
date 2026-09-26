<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\TrueFitDiagnosis;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\TrueFit\TrueFitErrorDiagnosisContract;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrueFitDiagnosisApiTest extends TestCase
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

    public function test_diagnoses_hidden_when_flag_off(): void
    {
        config(['perfflags.truefit_v1' => false]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-diag-off@example.com', $campus);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/diagnoses?class_session_id=1')
            ->assertNotFound();
    }

    public function test_teacher_can_upsert_and_read_structured_diagnosis(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-diag-save@example.com', $campus);
        $student = $this->makeStudent('診斷學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '10:00:00', '11:00:00');

        $lrBefore = LearningRecord::query()->count();
        $remaining = (int) $course->fresh()->RemainingSessions;

        $response = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/diagnoses', [
                'class_session_id' => $session->id,
                'diagnosis' => $this->diagnosisBody(),
            ])
            ->assertCreated();

        $diag = $response->json('data.diagnosis');
        $this->assertIsArray($diag);
        foreach (TrueFitErrorDiagnosisContract::REQUIRED_KEYS as $key) {
            $this->assertArrayHasKey($key, $diag, "missing {$key}");
        }
        $this->assertSame(['class_session_id' => $session->id], $diag['session_ref']);
        $this->assertDatabaseCount('truefit_diagnoses', 1);
        $this->assertSame($lrBefore, LearningRecord::query()->count());
        $this->assertSame($remaining, (int) $course->fresh()->RemainingSessions);

        $this->withAuth($teacher['token'])
            ->getJson('/api/v1/truefit/diagnoses?class_session_id=' . $session->id)
            ->assertOk()
            ->assertJsonPath('data.confidence', 'medium')
            ->assertJsonPath('data.diagnosis.schema_version', TrueFitErrorDiagnosisContract::SCHEMA_VERSION);
    }

    public function test_teacher_cannot_diagnose_other_teachers_session(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacherA = $this->makeStaff('T', 'tf-diag-a@example.com', $campus);
        $teacherB = $this->makeStaff('T', 'tf-diag-b@example.com', $campus);
        $student = $this->makeStudent('診斷學生乙', $campus);
        $course = $this->makeClass($student->id, $teacherA['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '14:00:00', '15:00:00');

        $this->withAuth($teacherB['token'])
            ->postJson('/api/v1/truefit/diagnoses', [
                'class_session_id' => $session->id,
                'diagnosis' => $this->diagnosisBody(),
            ])
            ->assertStatus(422);

        $this->assertSame(0, TrueFitDiagnosis::query()->count());
    }

    public function test_projected_session_diagnosis_without_class_session_id(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-diag-proj@example.com', $campus);
        $student = $this->makeStudent('診斷學生丙', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);

        $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/diagnoses', [
                'student_class_id' => $course->ID,
                'session_date' => '2026-09-16',
                'start_time' => '16:00',
                'diagnosis' => $this->diagnosisBody(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.class_session_id', null)
            ->assertJsonPath('data.diagnosis.session_ref.student_class_id', $course->ID);
    }

    public function test_invalid_diagnosis_payload_rejected(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-diag-bad@example.com', $campus);
        $student = $this->makeStudent('診斷學生丁', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '11:00:00', '12:00:00');

        $bad = $this->diagnosisBody();
        unset($bad['confidence']);

        $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/diagnoses', [
                'class_session_id' => $session->id,
                'diagnosis' => $bad,
            ])
            ->assertStatus(422);

        $this->assertSame(0, TrueFitDiagnosis::query()->count());
    }

    /** @return array<string, mixed> */
    private function diagnosisBody(): array
    {
        return [
            'primary_misconception' => [
                'label' => '分母相加',
                'statement' => '分數加減時把分母直接相加',
                'why_it_fits_observation' => '學生在通分步驟把分母相加',
                'linked_brief_objective' => '辨識分數加減',
            ],
            'supporting_signals' => ['通分錯誤'],
            'ruled_out' => [],
            'recommended_checks' => ['給同分母對照題'],
            'confidence' => 'medium',
            'teacher_decision' => 'pending',
            'teacher_notes' => '',
        ];
    }

    private function makeCampus(string $name = null): int
    {
        return (int) CampusFactory::new()->create([
            'name' => $name ?: ('TrueFit Diag ' . Str::random(5)),
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

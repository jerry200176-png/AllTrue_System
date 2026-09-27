<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves same-session source_* auto-link across observation → diagnosis → remediation → mastery.
 */
class TrueFitLoopSourceLinkApiTest extends TestCase
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

    public function test_upsert_chain_auto_links_source_ids_when_omitted(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $campus = $this->makeCampus();
        $teacher = $this->makeStaff('T', 'tf-loop-link@example.com', $campus);
        $student = $this->makeStudent('迴路學生', $campus);
        $course = $this->makeClass($student->id, $teacher['user']->id, $campus);
        $session = $this->makeSession($course->ID, '2026-09-16', '10:00:00', '11:00:00');

        $obsId = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/observations', [
                'class_session_id' => $session->id,
                'observation' => [
                    'objectives_touched' => ['辨識分數加減'],
                    'student_moves' => ['先通分再加減'],
                    'struggle_signals' => [],
                    'strength_signals' => [],
                    'misconception_hypotheses' => [
                        [
                            'label' => '分母規則混淆',
                            'what_student_seemed_to_believe' => '分母可以直接相加',
                            'what_to_check_next' => '給同分母對照題',
                        ],
                    ],
                    'evidence_notes' => '課堂觀察備註',
                    'confidence' => 'medium',
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $diagResponse = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/diagnoses', [
                'class_session_id' => $session->id,
                'diagnosis' => [
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
                    'teacher_decision' => 'accepted',
                    'teacher_notes' => '',
                ],
            ])
            ->assertCreated();

        $this->assertSame((int) $obsId, (int) $diagResponse->json('data.source_observation_id'));
        $diagId = (int) $diagResponse->json('data.id');

        $remResponse = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/remediations', [
                'class_session_id' => $session->id,
                'remediation' => [
                    'target_misconception_label' => '分母相加',
                    'practice_moves' => ['同分母對照練習'],
                    'material_anchors' => ['syn.math.fractions.v1'],
                    'success_criteria' => ['能正確通分後加減'],
                    'follow_up_window' => 'next_session',
                    'teacher_decision' => 'accepted',
                    'teacher_notes' => '',
                ],
            ])
            ->assertCreated();

        $this->assertSame($diagId, (int) $remResponse->json('data.source_diagnosis_id'));
        $remId = (int) $remResponse->json('data.id');

        $masResponse = $this->withAuth($teacher['token'])
            ->postJson('/api/v1/truefit/mastery-evidence', [
                'class_session_id' => $session->id,
                'mastery' => [
                    'target_misconception_label' => '分母相加',
                    'retrieval_prompt' => '請再解一題同分母加減',
                    'student_response_summary' => '正確通分後作答',
                    'outcome' => 'mastered',
                    'evidence_notes' => '',
                    'next_review_window' => 'within_30_days',
                    'teacher_decision' => 'accepted',
                ],
            ])
            ->assertCreated();

        $this->assertSame($remId, (int) $masResponse->json('data.source_remediation_id'));
    }

    private function makeCampus(string $name = null): int
    {
        return (int) CampusFactory::new()->create([
            'name' => $name ?: ('TrueFit Loop ' . Str::random(5)),
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

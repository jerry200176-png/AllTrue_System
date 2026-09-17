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
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrueFitSessionProgressApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Asia/Taipei']);
        Carbon::setTestNow(Carbon::parse('2026-09-17 09:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_session_progress_hidden_when_flag_off(): void
    {
        config(['perfflags.truefit_v1' => false]);
        $t = $this->fixtureTeacher('tf-prog-off@example.com');
        $this->withAuth($t['token'])
            ->postJson('/api/v1/truefit/session-progress', ['sessions' => [['class_session_id' => 1]]])
            ->assertNotFound();
    }

    public function test_prep_only_reports_only_prep(): void
    {
        config(['perfflags.truefit_v1' => true]);
        [$token, $sid] = $this->ownedSession('tf-prog-prep@example.com', '10:00:00', '11:00:00');
        $this->withAuth($token)->postJson('/api/v1/truefit/lesson-preps/generate', [
            'class_session_id' => $sid,
            'material_unit_key' => 'syn.math.fractions.v1',
        ])->assertCreated();

        $response = $this->withAuth($token)->postJson('/api/v1/truefit/session-progress', [
            'sessions' => [['class_session_id' => $sid]],
        ])->assertOk();
        $row = $response->json('data.0');

        $this->assertSame(['class_session_id' => $sid], $row['session_ref']);
        $this->assertTrue($row['progress']['prep']);
        foreach (['observation', 'diagnosis', 'remediation', 'mastery'] as $k) {
            $this->assertFalse($row['progress'][$k]);
        }
        $this->assertSame('omit_inaccessible', $response->json('meta.contract'));
    }

    public function test_full_loop_reports_all_five_saved(): void
    {
        config(['perfflags.truefit_v1' => true]);
        [$token, $sid] = $this->ownedSession('tf-prog-full@example.com', '11:00:00', '12:00:00');
        $this->seedFullLoop($token, $sid);

        $progress = $this->withAuth($token)->postJson('/api/v1/truefit/session-progress', [
            'sessions' => [['class_session_id' => $sid]],
        ])->assertOk()->json('data.0.progress');

        foreach (['prep', 'observation', 'diagnosis', 'remediation', 'mastery'] as $stage) {
            $this->assertTrue($progress[$stage], "expected {$stage} saved");
        }
    }

    public function test_other_teachers_session_is_omitted_fail_closed(): void
    {
        config(['perfflags.truefit_v1' => true]);
        [$tokenA, $sid] = $this->ownedSession('tf-prog-a@example.com', '13:00:00', '14:00:00');
        $tB = $this->fixtureTeacher('tf-prog-b@example.com');
        $this->withAuth($tokenA)->postJson('/api/v1/truefit/lesson-preps/generate', [
            'class_session_id' => $sid,
            'material_unit_key' => 'syn.math.fractions.v1',
        ])->assertCreated();

        $response = $this->withAuth($tB['token'])->postJson('/api/v1/truefit/session-progress', [
            'sessions' => [['class_session_id' => $sid]],
        ])->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(1, $response->json('meta.requested'));
        $this->assertSame(0, $response->json('meta.returned'));
        $this->assertSame(1, $response->json('meta.omitted'));
    }

    public function test_unknown_and_invalid_refs_omitted(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $t = $this->fixtureTeacher('tf-prog-omit@example.com');
        $response = $this->withAuth($t['token'])->postJson('/api/v1/truefit/session-progress', [
            'sessions' => [
                ['class_session_id' => 999999],
                ['student_class_id' => 1],
                'not-an-object',
            ],
        ])->assertOk();
        $this->assertSame([], $response->json('data'));
        $this->assertSame(3, $response->json('meta.requested'));
        $this->assertSame(0, $response->json('meta.returned'));
    }

    public function test_progress_read_does_not_write(): void
    {
        config(['perfflags.truefit_v1' => true]);
        [$token, $sid, $course] = $this->ownedSession('tf-prog-ro@example.com', '15:00:00', '16:00:00', true);
        $lrBefore = LearningRecord::query()->count();
        $prepBefore = DB::table('truefit_lesson_preps')->count();
        $obsBefore = DB::table('truefit_observations')->count();
        $remaining = (int) $course->fresh()->RemainingSessions;

        $this->withAuth($token)->postJson('/api/v1/truefit/session-progress', [
            'sessions' => [['class_session_id' => $sid]],
        ])->assertOk();

        $this->assertSame($lrBefore, LearningRecord::query()->count());
        $this->assertSame($prepBefore, DB::table('truefit_lesson_preps')->count());
        $this->assertSame($obsBefore, DB::table('truefit_observations')->count());
        $this->assertSame($remaining, (int) $course->fresh()->RemainingSessions);
    }

    public function test_rejects_over_max_sessions(): void
    {
        config(['perfflags.truefit_v1' => true]);
        $t = $this->fixtureTeacher('tf-prog-max@example.com');
        $sessions = [];
        for ($i = 1; $i <= 41; $i++) {
            $sessions[] = ['class_session_id' => $i];
        }
        $this->withAuth($t['token'])
            ->postJson('/api/v1/truefit/session-progress', ['sessions' => $sessions])
            ->assertStatus(422);
    }

    private function seedFullLoop(string $token, int $sid): void
    {
        $this->withAuth($token)->postJson('/api/v1/truefit/lesson-preps/generate', [
            'class_session_id' => $sid,
            'material_unit_key' => 'syn.math.fractions.v1',
        ])->assertCreated();
        $this->withAuth($token)->postJson('/api/v1/truefit/observations', [
            'class_session_id' => $sid,
            'observation' => [
                'objectives_touched' => ['辨識分數加減'],
                'student_moves' => ['先通分'],
                'struggle_signals' => [],
                'strength_signals' => [],
                'misconception_hypotheses' => [[
                    'label' => '分母相加',
                    'what_student_seemed_to_believe' => '分母相加',
                    'what_to_check_next' => '對照題',
                ]],
                'evidence_notes' => '備註',
                'confidence' => 'medium',
            ],
        ])->assertCreated();
        $this->withAuth($token)->postJson('/api/v1/truefit/diagnoses', [
            'class_session_id' => $sid,
            'diagnosis' => [
                'primary_misconception' => [
                    'label' => '分母相加',
                    'statement' => '分數加減把分母相加',
                    'why_it_fits_observation' => '通分錯誤',
                    'linked_brief_objective' => '辨識分數加減',
                ],
                'supporting_signals' => ['通分'],
                'ruled_out' => [],
                'recommended_checks' => ['對照題'],
                'confidence' => 'medium',
                'teacher_decision' => 'accepted',
                'teacher_notes' => '',
            ],
        ])->assertCreated();
        $this->withAuth($token)->postJson('/api/v1/truefit/remediations', [
            'class_session_id' => $sid,
            'remediation' => [
                'target_misconception_label' => '分母相加',
                'practice_moves' => ['對照練習'],
                'material_anchors' => ['syn.math.fractions.v1'],
                'success_criteria' => ['能正確通分'],
                'follow_up_window' => 'next_session',
                'teacher_decision' => 'accepted',
                'teacher_notes' => '',
            ],
        ])->assertCreated();
        $this->withAuth($token)->postJson('/api/v1/truefit/mastery-evidence', [
            'class_session_id' => $sid,
            'mastery' => [
                'target_misconception_label' => '分母相加',
                'retrieval_prompt' => '再解一題',
                'student_response_summary' => '正確',
                'outcome' => 'mastered',
                'evidence_notes' => '',
                'next_review_window' => 'within_30_days',
                'teacher_decision' => 'accepted',
            ],
        ])->assertCreated();
    }

    /** @return array{0:string,1:int}|array{0:string,1:int,2:StudentClass} */
    private function ownedSession(string $login, string $start, string $end, bool $withCourse = false): array
    {
        $t = $this->fixtureTeacher($login);
        $campus = $t['campus'];
        $student = Student::create([
            'name' => '進度學生',
            'CampusID' => $campus,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);
        $class = new StudentClass();
        $class->StudentID = $student->id;
        $class->TeacherID = $t['user']->id;
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
        $course = $class->fresh();
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-09-17',
            'StartTime' => $start,
            'EndTime' => $end,
            'Status' => 'scheduled',
        ]);
        return $withCourse
            ? [$t['token'], $session->id, $course]
            : [$t['token'], $session->id];
    }

    /** @return array{token:string,user:User,campus:int} */
    private function fixtureTeacher(string $login): array
    {
        $campus = (int) CampusFactory::new()->create([
            'name' => 'TrueFit Prog ' . Str::random(5),
        ])->id;
        $user = User::create([
            'LoginName' => $login,
            'Name' => 'TrueFit 老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => (string) random_int(900000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['token' => $token, 'user' => $user, 'campus' => $campus];
    }

    private function withAuth(string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }
}

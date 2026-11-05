<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ApprovalSessionSyncService;
use App\Services\EnrollmentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #502: a past lesson entered at enrollment (已上過 / 系統判定補登) was marked taught, auto-approved and
 * debited, but got no sign-in, so part-time payroll (which reads sign-ins) never paid it.
 */
class EnrollmentConfirmedAttendancePayrollTest extends TestCase
{
    use RefreshDatabase;

    private const PAST_DATE = '2026-04-06'; // Monday, before "now"

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-08 08:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_confirmed_past_lesson_gets_one_sign_in_one_debit_and_is_paid(): void
    {
        [$token, $teacherId] = $this->actors('confirmed');
        $courseId = $this->enroll($token, $teacherId, [self::PAST_DATE], ['2026-04-13'], 1);

        $session = $this->assertTaughtOnce($courseId, $teacherId, self::PAST_DATE);
        $this->assertPaid($token, $teacherId);

        // Re-running the same sync (approve pipeline and the enrollment helper) changes nothing.
        $lr = LearningRecord::where('ClassSessionID', $session->id)->firstOrFail();
        $course = StudentClass::findOrFail($courseId);
        ApprovalSessionSyncService::syncOnApprove($lr, $course, 0);
        $sync = new \ReflectionMethod(EnrollmentService::class, 'syncApprovedLearningRecord');
        $sync->invoke(app(EnrollmentService::class), $course, $session->fresh(), $teacherId, 'Math', null, true);
        $this->assertTaughtOnce($courseId, $teacherId, self::PAST_DATE);
    }

    public function test_system_backfilled_past_lesson_gets_one_sign_in_one_debit_and_is_paid(): void
    {
        [$token, $teacherId] = $this->actors('backfill');
        // A "future" row for today whose lesson already ended at create time is the 系統判定補登 path.
        Carbon::setTestNow(Carbon::parse('2026-04-08 20:00:00', 'Asia/Taipei'));
        $courseId = $this->enroll($token, $teacherId, [], ['2026-04-08', '2026-04-15'], 3);

        $session = $this->assertTaughtOnce($courseId, $teacherId, '2026-04-08');
        $this->assertStringContainsString('系統判定補登', (string) $session->Note);
        $this->assertPaid($token, $teacherId);
    }

    /** In-app #504: a lesson left taught-but-unsigned by the old path shows in 待補點名, and 補登 there makes it paid. */
    public function test_taught_lesson_without_sign_in_is_listed_for_makeup_and_marking_it_pays_once(): void
    {
        [$token, $teacherId] = $this->actors('makeup');
        $courseId = $this->enroll($token, $teacherId, [self::PAST_DATE], ['2026-04-13'], 1);
        // The pre-fix state: completed, approved record and one debit, but no sign-in.
        $session = ClassSession::where('StudentClassID', $courseId)->whereDate('SessionDate', self::PAST_DATE)->firstOrFail();
        StudentSignIn::where('ClassSessionID', $session->id)->delete();
        $session->update(['Status' => 'completed']);
        $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
        $unmarked = fn () => array_column($this->withHeaders($headers)
            ->getJson('/api/v1/attendance/ended-sessions?branch_id=1&start_date=2026-04-01&end_date=2026-04-08')
            ->assertOk()->json('data'), 'session_status', 'class_session_id');

        $this->assertSame('completed', $unmarked()[$session->id] ?? null);
        $this->assertSame(1, (int) $this->withHeaders($headers)
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')->json('summary.unmarked_count'));

        $this->withHeaders($headers)->postJson('/api/v1/attendance', [
            'StudentID' => (int) StudentClass::findOrFail($courseId)->StudentID, 'StudentClassID' => $courseId,
            'TeacherID' => $teacherId, 'ClassSessionID' => $session->id, 'Status' => 'present',
        ])->assertCreated();

        $this->assertSame('attended', (string) $session->fresh()->Status);
        $this->assertTaughtOnce($courseId, $teacherId, self::PAST_DATE);
        $this->assertPaid($token, $teacherId);
        $this->assertArrayNotHasKey($session->id, $unmarked());
    }

    private function assertTaughtOnce(int $courseId, int $teacherId, string $date): ClassSession
    {
        $session = ClassSession::where('StudentClassID', $courseId)->whereDate('SessionDate', $date)->firstOrFail();
        $signIns = StudentSignIn::where('ClassSessionID', $session->id)->active()->get();
        $this->assertCount(1, $signIns);
        $this->assertSame('present', $signIns[0]->Status);
        $this->assertSame($teacherId, (int) $signIns[0]->TeacherID);
        $this->assertTrue((bool) $signIns[0]->SessionDeducted);

        $debits = DB::table('session_deduction_ledger')->where('class_session_id', $session->id)
            ->selectRaw("SUM(CASE WHEN event_type = 'deduct' THEN 1 ELSE 0 END) as d, COUNT(*) as n")->first();
        $this->assertSame(1, (int) $debits->d);
        $this->assertSame(1, (int) $debits->n);

        $course = StudentClass::findOrFail($courseId);
        $this->assertSame(1, (int) $course->UsedSessions);
        $this->assertSame(1, (int) $course->RemainingSessions); // 2 bought, 1 taught

        return $session;
    }

    private function assertPaid(string $token, int $teacherId): void
    {
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/parttime-payroll?month=2026-04&branch_id=1')
            ->assertOk();
        $row = collect($res->json('teachers'))->firstWhere('teacher_id', $teacherId);
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['session_count']);
        $this->assertGreaterThan(0, (int) $row['total_salary']);
        $this->assertSame(0, (int) $res->json('summary.unmarked_count'));
    }

    private function enroll(string $token, int $teacherId, array $confirmed, array $future, int $weekday): int
    {
        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->postJson('/api/v1/enrollments', [
                'branch_id' => 1,
                'student' => ['name' => '補登測試生', 'grade' => 'J1'],
                'teacher_id' => $teacherId,
                'subject' => 'Math',
                'class_type' => 'one_on_one',
                'confirmed_dates' => $confirmed,
                'future_dates' => $future,
                'days_of_week' => [$weekday],
                'start_time' => '16:00',
                'duration_minutes' => 120,
                'price_per_session' => 500,
                'payment_type' => 'session',
                'total_classes' => 2,
            ]);
        $res->assertCreated();

        return (int) $res->json('student_class_id');
    }

    /** @return array{0: string, 1: int} director token, part-time teacher id */
    private function actors(string $tag): array
    {
        $director = User::create([
            'LoginName' => "dir-502-{$tag}@test.com", 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => '0911000000', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $teacher = User::create([
            'LoginName' => "pt-502-{$tag}@test.com", 'Name' => '兼職', 'PSW' => 'secret',
            'type' => 'T', 'phone' => '0922111111', 'MustChangePassword' => false, 'employment_type' => 'part_time',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);

        return [$token, (int) $teacher->id];
    }
}

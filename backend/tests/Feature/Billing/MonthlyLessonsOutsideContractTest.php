<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\MonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #4765 (in-app #453/#454): a monthly contract whose two taught lessons (9/29, 9/30) lie outside its own dates
 * (10/10-10/31) was priced from the stored Charge as an empty October. Shared predicate + write guard + collection guard + ledger.
 */
class MonthlyLessonsOutsideContractTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        $user = User::create(['LoginName' => 'd4765@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_predicate_lists_only_countable_lessons_outside_the_dates(): void
    {
        $sc = $this->course(['2026-09-30' => 'attended', '2026-09-29' => 'attended', '2026-10-12' => 'attended', '2026-09-15' => 'leave']);
        $billing = app(MonthlyBillingService::class);
        $this->assertSame(['2026-09-29', '2026-09-30'], $billing->taughtLessonsOutsideContract($sc));
        $this->assertSame([], $billing->taughtLessonsOutsideContract($sc, '2026-09-29', '2026-10-31'));
        $this->assertSame([], $billing->taughtLessonsOutsideContract($this->course(['2026-09-29' => 'attended'], ['ScheduleMode' => 'count'])));
    }

    public function test_director_record_refuses_and_writes_nothing(): void
    {
        $sc = $this->course();
        $res = $this->record($sc, 3600)->assertStatus(422)->assertJsonPath('code', 'lessons_outside_contract')
            ->assertJsonPath('lesson_dates', ['2026-09-29', '2026-09-30'])
            ->assertJsonPath('contract_start', '2026-10-10')->assertJsonPath('contract_end', '2026-10-31');
        $this->assertStringContainsString('沒有登記任何款項。', $res->json('message'));
        // The stored Charge (1800) is not accepted as an October amount either.
        $this->record($sc, 1800)->assertStatus(422)->assertJsonPath('code', 'lessons_outside_contract');
        $this->assertSame([0, 0, 0], [Invoice::count(), Payment::count(), PaymentReport::count()]);
    }

    public function test_ledger_card_lists_the_dates_and_no_collection_amount(): void
    {
        $sc = $this->course();
        $card = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'))->firstWhere('id', $sc->ID);
        $this->assertSame(['2026-09-29', '2026-09-30'], $card['lessons_outside_contract']);
        $this->assertNull($card['collection_period']);
        $this->assertSame('2026-10-31', $card['end_date']);
    }

    public function test_update_refuses_stranding_a_taught_lesson_but_allows_covering_dates(): void
    {
        $sc = $this->course([], ['StartDate' => '2026-09-29', 'EndDate' => '2026-10-31']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-09-29', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => '2026-09-30', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);

        $res = $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['StudentID' => $sc->StudentID, 'StartDate' => '2026-10-10'])
            ->assertStatus(422)->assertJsonPath('code', 'contract_dates_strand_lessons')->assertJsonPath('lesson_dates', ['2026-09-29', '2026-09-30']);
        $this->assertStringContainsString('9/29、9/30', $res->json('message'));
        $this->assertSame('2026-09-29', substr((string) $sc->fresh()->StartDate, 0, 10));
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['end_date' => '2026-09-29'])
            ->assertStatus(422)->assertJsonPath('lesson_dates', ['2026-09-30']);

        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['end_date' => '2026-09-30'])
            ->assertOk();
        $this->assertSame('2026-09-30', substr((string) $sc->fresh()->EndDate, 0, 10));
    }

    public function test_after_the_dates_cover_the_lessons_the_month_is_september_at_3600(): void
    {
        $sc = $this->course();
        $sc->update(['StartDate' => '2026-09-29', 'EndDate' => '2026-09-30']);
        $billing = app(MonthlyBillingService::class);
        $this->assertSame('2026-09', $billing->collectionPeriodFor($sc->fresh()));
        $this->assertSame(3600, $billing->summarizePeriod($sc->fresh(), '2026-09')['charge']);
    }

    public function test_a_makeup_after_the_end_beside_lessons_inside_stays_collectable_and_editable(): void
    {
        // Common: a September contract's makeup taught 10/3. September is billable from its own lessons, so nothing is blocked.
        $sc = $this->course(['2026-09-12' => 'attended', '2026-09-19' => 'attended', '2026-10-03' => 'attended'],
            ['StartDate' => '2026-09-01', 'EndDate' => '2026-09-30']);
        $billing = app(MonthlyBillingService::class);
        $this->assertSame(['2026-10-03'], $billing->taughtLessonsOutsideContract($sc));
        $this->assertSame([], $billing->lessonsOnlyOutsideContract($sc));

        $card = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'))->firstWhere('id', $sc->ID);
        $this->assertSame([], $card['lessons_outside_contract']);
        $this->assertNotSame('lessons_outside_contract', $this->record($sc, 3600)->json('code'));
        // An edit that strands nothing new is allowed although 10/3 was already outside.
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['end_date' => '2026-09-29'])->assertOk();
    }

    private function course(array $lessons = ['2026-09-29' => 'attended', '2026-09-30' => 'attended'], array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 's'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($overrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-10', 'EndDate' => '2026-10-31', 'TotalHours' => 0, 'Charge' => 1800, 'Paid' => 0, 'Rate' => 1800,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 1, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        foreach ($lessons as $date => $status) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }

        return $sc;
    }

    private function api(string $method, string $uri, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->{$method}($uri, $body);
    }

    private function record(StudentClass $sc, int $amount)
    {
        return $this->api('postJson', '/api/v1/payment-reports/director-record', [
            'student_class_id' => $sc->ID, 'payment_date' => '2026-10-23', 'payment_method' => 'cash', 'amount' => $amount,
        ]);
    }
}

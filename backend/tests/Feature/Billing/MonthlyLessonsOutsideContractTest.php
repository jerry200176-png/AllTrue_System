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
 * #5702 (in-app #607, Founder 2026-11-28 option A): lessons before StartDate are now billed with the first month (October).
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

    /** #5702 (in-app #607, Founder 2026-11-28 option A): lessons taught before StartDate are billed with the first month, once. */
    public function test_pre_start_lessons_are_collected_with_the_first_month_once(): void
    {
        $sc = $this->course();
        $billing = app(MonthlyBillingService::class);
        $this->assertSame('2026-09-29', $billing->billingStart($sc));
        $this->assertSame([], $billing->lessonsOnlyOutsideContract($sc));
        $this->assertSame('2026-10', $billing->unbilledPeriodFor($sc));
        $october = $billing->summarizePeriod($sc, '2026-10');
        $this->assertSame([3600, 2, '2026-09-29'], [$october['charge'], $october['period_sessions'], $october['period_start']]);
        $this->assertSame(0, $billing->summarizePeriod($sc, '2026-09')['period_sessions'], 'the month before never bills them');

        // The stored Charge (1800) is not the October amount; the two lessons are.
        $this->record($sc, 1800)->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')->assertJsonPath('expected_amount', 3600);
        $this->assertSame([0, 0, 0], [Invoice::count(), Payment::count(), PaymentReport::count()]);
        $this->api('putJson', '/api/v1/payment-reports/' . $this->record($sc, 3600)->assertOk()->json('report_id') . '/confirm')->assertOk();

        $bill = Invoice::query()->where('StudentClassID', $sc->ID)->sole();
        $this->assertSame(['2026-10', 3600], [(string) $bill->billing_period, (int) $bill->TotalAmount]);
        $this->assertSame('2026-09-29', $bill->billing_snapshot['period_start'], 'the bill is dated from the first lesson');
        $this->assertSame(['2026-09-29', '2026-09-30'], array_column($bill->billing_snapshot['sessions'], 'date'));
        // Paid and frozen: still the same two lessons, nothing left to collect, and no second bill.
        $this->assertSame('2026-09-29', $billing->billingStart($sc->fresh()));
        $this->assertNull($billing->unbilledPeriodFor($sc->fresh()));
        $this->assertNull($billing->partlyBilledMonth($sc->fresh()));
        $this->assertSame(3600, $billing->summarizePeriod($sc->fresh(), '2026-10')['charge']);
        $this->record($sc->fresh(), 1800)->assertStatus(422);
        $this->assertSame([1, 1], [Invoice::count(), Payment::count()]);
    }

    public function test_ledger_card_offers_the_first_month_with_its_pre_start_lessons(): void
    {
        $sc = $this->course();
        $card = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'))->firstWhere('id', $sc->ID);
        $this->assertSame([], $card['lessons_outside_contract']);
        $this->assertSame(['billing_period' => '2026-10', 'amount' => 3600], $card['collection_period']);
        $this->assertNull($card['record_block']);
        $this->assertSame('2026-10-31', $card['end_date']);
    }

    /** #5702 + #5703: a first-month bill already paid without the pre-start lesson is not repriced and gets no second bill. */
    public function test_a_paid_first_month_bill_without_the_pre_start_lesson_blocks_and_writes_nothing(): void
    {
        $sc = $this->course(['2026-09-29' => 'attended', '2026-10-12' => 'attended']);
        $bill = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-10-12', 'TotalAmount' => 1800,
            'PaidAmount' => 1800, 'Status' => 'paid', 'billing_period' => '2026-10', 'billing_snapshot' => ['version' => 1, 'source' => 'monthly_billable_sessions',
                'billing_period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'period_sessions' => 1, 'charge' => 1800,
                'sessions' => [['class_session_id' => 0, 'date' => '2026-10-12']]]]);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 1800, 'PaidAt' => '2026-10-12', 'Method' => 'cash']);
        $billing = app(MonthlyBillingService::class);

        $this->assertSame('2026-10-10', $billing->billingStart($sc), 'a frozen bill that did not price it keeps the contract start');
        $this->assertSame(1, $billing->summarizePeriod($sc, '2026-10')['period_sessions']);
        $this->assertSame([], $billing->lessonsOnlyOutsideContract($sc));
        $block = $billing->partlyBilledMonth($sc);
        $this->assertSame(['month_partly_billed', '2026-10', ['2026-09-29']], [$block['code'], $block['billing_period'], $block['lesson_dates']]);
        // 更正帳單 cannot add a lesson before the contract start: the message only says 請回報.
        $this->assertStringContainsString('請回報', $block['message']);
        $this->assertStringNotContainsString('更正帳單', $block['message']);

        $before = [Invoice::query()->get()->toArray(), Payment::query()->get()->toArray()];
        $this->record($sc, 1800)->assertStatus(422)->assertJsonPath('code', 'month_partly_billed')->assertJsonPath('lesson_dates', ['2026-09-29']);
        $this->assertSame($before, [Invoice::query()->get()->toArray(), Payment::query()->get()->toArray()]);
        $this->assertSame(0, PaymentReport::count());
    }

    /** Review #3: a pre-start lesson the paid first month did not take never blocks a later month; it shows only when nothing else is due. */
    public function test_an_unpulled_pre_start_lesson_leaves_later_months_collectable_and_shows_only_when_nothing_else_is_due(): void
    {
        $sc = $this->course(['2026-09-29' => 'attended', '2026-10-12' => 'attended', '2026-11-09' => 'attended'], ['EndDate' => '2026-11-30']);
        $bill = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-10-12', 'TotalAmount' => 1800,
            'PaidAmount' => 1800, 'Status' => 'paid', 'billing_period' => '2026-10', 'billing_snapshot' => ['version' => 1, 'source' => 'monthly_billable_sessions',
                'billing_period' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'period_sessions' => 1, 'charge' => 1800,
                'sessions' => [['class_session_id' => 0, 'date' => '2026-10-12']]]]);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 1800, 'PaidAt' => '2026-10-12', 'Method' => 'cash']);
        $billing = app(MonthlyBillingService::class);

        $this->assertNull($billing->partlyBilledMonth($sc), 'November is still due: 9/29 does not hold it');
        $this->record($sc, 999)->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')
            ->assertJsonPath('billing_period', '2026-11')->assertJsonPath('expected_amount', 1800);
        $this->api('putJson', '/api/v1/payment-reports/' . $this->record($sc, 1800)->assertOk()->json('report_id') . '/confirm')->assertOk();
        $this->assertSame('2026-11', (string) Invoice::query()->where('StudentClassID', $sc->ID)->whereKeyNot($bill->id)->sole()->billing_period);

        // Nothing else due: 9/29 now shows, without claiming it is unpaid, and nothing is written.
        $block = $billing->partlyBilledMonth($sc->fresh());
        $this->assertSame(['month_partly_billed', '2026-10', ['2026-09-29']], [$block['code'], $block['billing_period'], $block['lesson_dates']]);
        $this->assertStringContainsString('不在任何帳單的日期內', $block['message']);
        $this->assertStringNotContainsString('還沒算進任何帳單', $block['message']);
        $before = [Invoice::query()->get()->toArray(), Payment::query()->get()->toArray(), PaymentReport::count()];
        $this->record($sc->fresh(), 1800)->assertStatus(422)->assertJsonPath('code', 'month_partly_billed');
        $this->assertSame($before, [Invoice::query()->get()->toArray(), Payment::query()->get()->toArray(), PaymentReport::count()]);
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
        // #5702 is about lessons before the start only: a makeup after the end keeps today's rule (not priced in September).
        $this->assertSame('2026-09-01', $billing->billingStart($sc));
        $this->assertSame(2, $billing->summarizePeriod($sc, '2026-09')['period_sessions']);

        $card = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'))->firstWhere('id', $sc->ID);
        $this->assertSame([], $card['lessons_outside_contract']);
        $this->assertNotSame('lessons_outside_contract', $this->record($sc, 3600)->json('code'));
        // An edit that strands nothing new is allowed although 10/3 was already outside.
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['end_date' => '2026-09-29'])->assertOk();
    }

    /** Review HIGH: a lesson moved in from the previous term (#5198) sits between a free pre-start lesson and the start. */
    public function test_a_covered_lesson_inside_the_pre_start_range_keeps_the_start_and_is_never_charged_twice(): void
    {
        $sc = $this->course(['2026-09-05' => 'attended', '2026-09-22' => 'attended']);
        $previous = StudentClass::create(array_merge($sc->only(['StudentID', 'GradeID', 'SubjectID', 'TeacherID', 'by1', 'Period', 'TotalHours', 'Rate', 'ClassType']),
            ['StartDate' => '2026-09-01', 'EndDate' => '2026-09-30', 'Charge' => 1800, 'Paid' => 0, 'Stop' => 1, 'ScheduleMode' => 'date',
             'SessionCount' => 0, 'RemainingSessions' => 0, 'UsedSessions' => 0, 'SessionDuration' => 120, 'MDate' => now()]));
        $september = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $previous->ID, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 1800, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-09']);
        // The previous term's line covers 9/15-9/30 only: 9/22 is billed there, 9/05 is free.
        \App\Models\InvoiceItem::create(['InvoiceID' => $september->id, 'StudentClassID' => $previous->ID, 'Description' => 'tuition', 'Amount' => 1800,
            'PeriodStart' => '2026-09-15', 'PeriodEnd' => '2026-09-30']);
        $billing = app(MonthlyBillingService::class);

        $this->assertSame('2026-10-10', $billing->billingStart($sc), '9/22 is on the September bill: October must not start at 9/05');
        $this->assertSame(0, $billing->summarizePeriod($sc, '2026-10')['period_sessions']);
        $block = $billing->partlyBilledMonth($sc);
        $this->assertSame(['month_partly_billed', ['2026-09-05']], [$block['code'], $block['lesson_dates']]);
        $this->record($sc, 1800)->assertStatus(422)->assertJsonPath('code', 'month_partly_billed');
        $this->assertSame([1, 0], [Invoice::count(), Payment::count()]);
    }

    /** Founder-delegated 2026-11-28: pull only from the 1st of the month before StartDate's month; one older lesson pulls none. */
    public function test_pre_start_lessons_are_pulled_only_from_the_month_before_the_start(): void
    {
        $billing = app(MonthlyBillingService::class);
        $this->assertSame('2026-09-01', $billing->billingStart($this->course(['2026-09-01' => 'attended'])), 'the 1st of the month before is in');

        $sc = $this->course(['2026-08-31' => 'attended', '2026-09-29' => 'attended']);
        $this->assertSame('2026-10-10', $billing->billingStart($sc), '8/31 is too old, so 9/29 is not pulled either');
        $this->assertSame(0, $billing->summarizePeriod($sc, '2026-10')['period_sessions']);
        $block = $billing->partlyBilledMonth($sc);
        $this->assertSame(['month_partly_billed', '2026-10', ['2026-08-31', '2026-09-29']], [$block['code'], $block['billing_period'], $block['lesson_dates']]);
        $this->assertStringNotContainsString('更正帳單', $block['message']);
        $this->record($sc, 1800)->assertStatus(422)->assertJsonPath('code', 'month_partly_billed');
        $this->assertSame([0, 0], [Invoice::count(), Payment::count()]);
    }

    public function test_lessons_whose_month_a_catch_up_billed_do_not_block_collection(): void
    {
        // Codex P2: a catch-up contract billed September; those lessons stay here but their money lives on the catch-up bill.
        $sc = $this->course();
        $catchUp = StudentClass::create(array_merge($sc->only(['StudentID', 'GradeID', 'SubjectID', 'TeacherID', 'by1', 'Period', 'TotalHours', 'Rate', 'ClassType']),
            ['StartDate' => '2026-09-16', 'EndDate' => '2026-09-30', 'Charge' => 1000, 'Paid' => 0, 'Stop' => 1, 'ScheduleMode' => 'date',
             'SessionCount' => 0, 'RemainingSessions' => 0, 'UsedSessions' => 0, 'SessionDuration' => 120, 'MDate' => now()]));
        $billing = app(MonthlyBillingService::class);
        // #5702: unbilled, the pre-start lessons belong to this contract's first month (they used to block as outside the dates).
        $this->assertSame([], $billing->lessonsOnlyOutsideContract($sc));
        $this->assertSame(2, $billing->summarizePeriod($sc, '2026-10')['period_sessions']);
        Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $catchUp->ID, 'IssueDate' => '2026-10-01',
            'TotalAmount' => 1000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-09']);

        $this->assertSame([], $billing->lessonsOnlyOutsideContract($sc));
        // Their money lives on the catch-up bill: the first month does not bill them a second time.
        $this->assertSame('2026-10-10', $billing->billingStart($sc));
        $this->assertSame(0, $billing->summarizePeriod($sc, '2026-10')['period_sessions']);
        $this->assertNotSame('lessons_outside_contract', $this->record($sc, 1800)->json('code'));
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

<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\MonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * in-app #432 (Founder 2026-10-28, option A): a monthly contract that spans calendar months is billed by calendar
 * month, oldest unbilled month first, in 登記收款, confirm, the slip and the drawer form. Shape of course 3458:
 * 2026-08-31 to 2026-09-30, one countable lesson in August, three in September, two leave days.
 */
class MonthlyCalendarMonthCollectionTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        $user = User::create(['LoginName' => 'd432@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_cross_month_contract_collects_august_then_september(): void
    {
        $sc = $this->course('2026-08-31', '2026-09-30', [
            '2026-08-31' => 'attended', '2026-09-07' => 'attended', '2026-09-20' => 'leave',
            '2026-09-21' => 'leave', '2026-09-23' => 'attended', '2026-09-29' => 'attended',
        ]);

        // The slip and the drawer form name August and its amount: the same server answer as the refusal below.
        $this->api('getJson', "/api/v1/alerts/tuition-slip/{$sc->ID}")->assertOk()
            ->assertJsonPath('billing_period', '2026-08')->assertJsonPath('charge', 1650);
        $ledger = collect($this->api('getJson', "/api/v1/accounting/ledger?student_class_id={$sc->ID}")->assertOk()->json('courses'));
        $this->assertSame(['billing_period' => '2026-08', 'amount' => 1650], $ledger->firstWhere('id', $sc->ID)['collection_period']);

        // A September amount first is refused, naming August.
        $refused = $this->record($sc, 4950)->assertStatus(422)->assertJsonPath('code', 'monthly_amount_stale')
            ->assertJsonPath('billing_period', '2026-08')->assertJsonPath('expected_amount', 1650);
        $this->assertStringContainsString('2026 年 8 月', $refused->json('message'));

        $this->confirm($this->record($sc, 1650)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1650]], $this->bills($sc));

        // August is paid; September still has countable lessons, so "already paid" must not refuse.
        $this->confirm($this->record($sc, 4950)->assertOk()->json('report_id'));
        $this->assertSame([['2026-08', 1650], ['2026-09', 4950]], $this->bills($sc));

        // Every month billed and paid: the already-paid refusal still fires.
        $this->record($sc, 1650)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    public function test_single_month_contract_keeps_the_payment_date_month(): void
    {
        $sc = $this->course('2026-10-01', '2026-10-31', ['2026-10-05' => 'attended', '2026-10-12' => 'attended']);

        $this->record($sc, 1650)->assertStatus(422)->assertJsonPath('billing_period', '2026-10')->assertJsonPath('expected_amount', 3300);
        $this->confirm($this->record($sc, 3300)->assertOk()->json('report_id'));
        $this->assertSame([['2026-10', 3300]], $this->bills($sc));
        $this->record($sc, 3300)->assertStatus(422)->assertJsonPath('code', 'course_already_paid');
    }

    public function test_rule_leaves_count_mode_packages_and_partly_billed_months_to_the_old_rule(): void
    {
        $billing = app(MonthlyBillingService::class);
        $lessons = ['2026-08-31' => 'attended', '2026-09-29' => 'attended'];
        $this->assertNull($billing->unbilledPeriodFor($this->course('2026-08-31', '2026-09-30', $lessons, ['ScheduleMode' => 'count'])));
        $this->assertNull($billing->unbilledPeriodFor($this->course('2026-08-31', '2026-09-30', $lessons, ['PackageID' => 7])));

        // A renewal-style bill labelled 2026-08 covering 8/28–9/27 leaves 9/29 uncovered: September is partly billed,
        // so pricing the whole calendar month would bill 9/1–9/27 twice. Keep today's rule.
        $sc = $this->course('2026-08-28', '2026-09-30', ['2026-08-31' => 'attended', '2026-09-07' => 'attended', '2026-09-29' => 'attended']);
        $bill = Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => '2026-08-28', 'TotalAmount' => 3300,
            'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08']);
        $bill->items()->create(['StudentClassID' => $sc->ID, 'Description' => 'cycle', 'Amount' => 3300, 'PeriodStart' => '2026-08-28', 'PeriodEnd' => '2026-09-27']);
        $this->assertNull($billing->unbilledPeriodFor($sc));
        $this->assertSame('2026-09', $billing->collectionPeriodFor($sc, '2026-10-23'));
    }

    private function course(string $start, string $end, array $lessons, array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 's'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($overrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => $start, 'EndDate' => $end, 'TotalHours' => 0, 'Charge' => 8250, 'Paid' => 0, 'Rate' => 1650,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
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

    private function confirm(int $reportId): void
    {
        $this->api('putJson', "/api/v1/payment-reports/{$reportId}/confirm")->assertOk();
    }

    private function bills(StudentClass $sc): array
    {
        return Invoice::query()->where('StudentClassID', $sc->ID)->orderBy('billing_period')->get()
            ->map(fn (Invoice $i) => [(string) $i->billing_period, (int) $i->TotalAmount])->all();
    }
}

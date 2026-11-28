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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server decides, screen obeys: the ledger card's record_block is exactly the refusal director-record (no bill named)
 * gives, because both come from RecordGate. Each case: block present <=> record refused, with the same code and sentence.
 */
class RecordGateAgreesTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        $user = User::create(['LoginName' => 'gate@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** E: a monthly bill whose stored Status is stale ("unpaid") but whose Payment rows cover it, beside a month not billed yet. */
    public function test_a_stale_status_bill_covered_by_payments_blocks_the_card_as_record_refuses(): void
    {
        // November is not billed yet, so the resolver does not read the course as paid (D passes) and E decides.
        $sc = $this->course(['2026-10-12' => 'attended', '2026-10-19' => 'attended', '2026-11-09' => 'attended'], ['StartDate' => '2026-10-01', 'EndDate' => '2026-11-30']);
        $bill = $this->bill($sc, '2026-10', 3600, 'unpaid', 0);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 3600, 'PaidAt' => '2026-10-12', 'Method' => 'cash']);

        $block = $this->assertAgrees($sc, 3600, true);
        $this->assertSame('course_already_paid', $block['code']);
    }

    /** D: the resolver reads the course as paid (Payment rows) while the stored Paid flag is 0. */
    public function test_a_course_the_resolver_reads_as_paid_with_paid_flag_zero_blocks_the_card_as_record_refuses(): void
    {
        $sc = $this->course([], ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Charge' => 7200, 'Paid' => 0]);
        $bill = $this->bill($sc, '2026-10', 7200, 'paid', 7200);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 7200, 'PaidAt' => '2026-10-05', 'Method' => 'cash']);

        $block = $this->assertAgrees($sc, 7200, true);
        $this->assertSame('course_already_paid', $block['code']);
    }

    /** F (paid): a paid monthly bill and no month left unbilled. */
    public function test_a_monthly_contract_whose_paid_bill_covers_every_lesson_blocks_the_card_as_record_refuses(): void
    {
        $sc = $this->course(['2026-10-12' => 'attended']);
        $bill = $this->bill($sc, '2026-10', 1800, 'paid', 1800);
        Payment::create(['InvoiceID' => $bill->id, 'Amount' => 1800, 'PaidAt' => '2026-10-12', 'Method' => 'cash']);

        $this->assertAgrees($sc, 1800, true);
    }

    /**
     * F (legacy Paid flag, no bill): Paid=1 on a count contract with an empty Charge column and a positive Rate. The resolver
     * skips its legacy-flag "paid" (that needs Charge > 0) and reads review_required, so D passes and F's Paid==1 branch refuses.
     */
    public function test_a_paid_flag_with_no_bill_the_resolver_does_not_read_as_paid_blocks_the_card_as_record_refuses(): void
    {
        $sc = $this->course([], ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Charge' => 0, 'Rate' => 800, 'Paid' => 1]);
        $status = app(\App\Services\BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $sc->ID])[(int) $sc->ID]['status'];
        $this->assertNotContains($status, ['paid', 'free'], 'fixture must pass D, so F decides');

        $block = $this->assertAgrees($sc, 800, true);
        $this->assertSame(['course_already_paid', '這門課的款項已收齊，不需要再登記；若收錯了，請先作廢原收款再重新登記。'], [$block['code'], $block['message']]);
    }

    /** Positive: an unbilled month (monthly) and an unbilled count contract: no block, and the record is accepted. */
    public function test_an_unbilled_month_and_an_unbilled_count_contract_have_no_block_and_record(): void
    {
        $this->assertAgrees($this->course(['2026-10-12' => 'attended']), 1800, false);
        $this->assertAgrees($this->course([], ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Charge' => 7200]), 7200, false);
    }

    /**
     * The ledger asks the resolver once for every settled contract: 4 settled count contracts cost what 1 does. By reasoning,
     * 4 queries: the resolver's bills + payments + items (3) and its shared-bill lookup (1); the per-course partly-billed
     * scan reads the loaded bills and returns early for count mode (0).
     */
    public function test_settled_contracts_are_read_in_one_batch(): void
    {
        $settled = function (int $n): array {
            $ids = [];
            for ($i = 0; $i < $n; $i++) {
                $sc = $this->course([], ['ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Charge' => 7200]);
                $bill = $this->bill($sc, '2026-10', 7200, 'paid', 7200);
                Payment::create(['InvoiceID' => $bill->id, 'Amount' => 7200, 'PaidAt' => '2026-10-05', 'Method' => 'cash']);
                $ids[] = (int) $sc->ID;
            }
            $courses = StudentClass::with('coursePackage')->whereIn('ID', $ids)->get();
            $invoices = Invoice::with(['items', 'payments'])->whereIn('StudentClassID', $ids)->get();
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $refusals = app(\App\Services\Billing\RecordGate::class)->refusals($courses, $invoices);
            $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $this->assertSame($ids, array_keys($refusals), 'every settled contract gets its refusal');

            return [$queries, $refusals];
        };
        [$one] = $settled(1);
        [$four] = $settled(4);
        $this->assertSame($one, $four, 'the resolver is asked once, not once per contract');
    }

    /** @return array<string, mixed>|null the card's record_block */
    private function assertAgrees(StudentClass $sc, int $amount, bool $blocked): ?array
    {
        $card = collect($this->api('getJson', "/api/v1/accounting/ledger?branch_id=1&student_class_id={$sc->ID}")->assertOk()->json('courses'))->firstWhere('id', $sc->ID);
        $block = $card['record_block'];
        $before = [Invoice::count(), Payment::count(), PaymentReport::count()];
        $res = $this->api('postJson', '/api/v1/payment-reports/director-record', [
            'student_class_id' => $sc->ID, 'payment_date' => '2026-10-23', 'payment_method' => 'cash', 'amount' => $amount,
        ]);
        if (!$blocked) {
            $this->assertNull($block);
            $res->assertOk()->assertJsonPath('status', 'pending');

            return null;
        }
        $this->assertNotNull($block, 'the card must say why 登記這筆 is refused');
        $res->assertStatus(422);
        $this->assertSame([$block['code'], $block['message']], [$res->json('code'), $res->json('message')]);
        $this->assertSame($before, [Invoice::count(), Payment::count(), PaymentReport::count()]);

        return $block;
    }

    private function course(array $lessons = [], array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 's'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($overrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-10', 'EndDate' => '2026-10-31', 'TotalHours' => 0, 'Charge' => 1800, 'Paid' => 0, 'Rate' => 1800,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        foreach ($lessons as $date => $status) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }

        return $sc;
    }

    private function bill(StudentClass $sc, string $period, int $total, string $status, int $paid): Invoice
    {
        return Invoice::create(['StudentID' => $sc->StudentID, 'StudentClassID' => $sc->ID, 'IssueDate' => "$period-01", 'DueDate' => "$period-15",
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $status, 'billing_period' => $period]);
    }

    private function api(string $method, string $uri, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->{$method}($uri, $body);
    }
}

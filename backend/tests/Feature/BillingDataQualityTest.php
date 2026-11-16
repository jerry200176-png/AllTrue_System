<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 帳務中心重整 G2 (Founder 2026-11-16): the billing data-quality scoreboard counts each known problem shape per campus. CI only. */
final class BillingDataQualityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 09:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_counts_each_problem_shape_per_campus_and_leaves_healthy_bills_out(): void
    {
        // Campus 1: one NT$0 bill marked paid, one bill 90 days old still unpaid, one ended contract with money pending.
        $zero = $this->course(1);
        $this->bill($zero, ['TotalAmount' => 0, 'Status' => 'paid', 'IssueDate' => '2026-11-01']);
        $old = $this->course(1);
        $this->bill($old, ['TotalAmount' => 6000, 'Status' => 'unpaid', 'IssueDate' => '2026-08-01']);
        $this->course(1, ['Stop' => 1, 'closed_reason' => 'settled_pending']);
        // Campus 2: a monthly bill with no month, and one issued before its period is over, both unpaid and recent.
        $noMonth = $this->course(2, ['ScheduleMode' => 'date']);
        $this->bill($noMonth, ['TotalAmount' => 4400, 'Status' => 'unpaid', 'IssueDate' => '2026-11-10', 'billing_period' => null]);
        $advance = $this->course(2, ['ScheduleMode' => 'date']);
        $early = $this->bill($advance, ['TotalAmount' => 8800, 'Status' => 'unpaid', 'IssueDate' => '2026-11-01', 'billing_period' => '2026-11']);
        InvoiceItem::create(['InvoiceID' => $early->id, 'StudentClassID' => $advance->ID, 'Description' => 'm', 'Amount' => 8800, 'PeriodStart' => '2026-11-01', 'PeriodEnd' => '2026-11-30']);
        // Healthy or explained, never counted: a marked free bill, a refunded bill (NT$0 after money moved), a recent unpaid bill.
        $free = $this->course(1);
        $this->bill($free, ['TotalAmount' => 0, 'Status' => 'paid', 'IssueDate' => '2026-11-01', 'zero_amount_reason' => Invoice::ZERO_REASON_FREE_GIFT]);
        $refunded = $this->course(1);
        $back = $this->bill($refunded, ['TotalAmount' => 0, 'Status' => 'paid', 'IssueDate' => '2026-11-01']);
        Payment::create(['InvoiceID' => $back->id, 'Amount' => 6000, 'PaidAt' => '2026-11-01', 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $back->id, 'Amount' => -6000, 'PaidAt' => '2026-11-05', 'Method' => 'refund']);
        $recent = $this->course(1);
        $this->bill($recent, ['TotalAmount' => 6000, 'Status' => 'unpaid', 'IssueDate' => '2026-11-10']);
        // Payment rows are the truth: an old bill and an ended contract whose stored flags are stale but fully covered.
        $covered = $this->course(1, ['Stop' => 1, 'closed_reason' => 'settled_pending']);
        $stale = $this->bill($covered, ['TotalAmount' => 6000, 'Status' => 'unpaid', 'IssueDate' => '2026-08-01']);
        Payment::create(['InvoiceID' => $stale->id, 'Amount' => 6000, 'PaidAt' => '2026-08-02', 'Method' => 'cash']);
        // A count-mode bill kept after the course became monthly has no month by design.
        $converted = $this->course(2, ['ScheduleMode' => 'date']);
        $kept = $this->bill($converted, ['TotalAmount' => 6000, 'PaidAmount' => 6000, 'Status' => 'paid', 'IssueDate' => '2026-10-01', 'ScheduleModeAtIssue' => 'count']);
        Payment::create(['InvoiceID' => $kept->id, 'Amount' => 6000, 'PaidAt' => '2026-10-01', 'Method' => 'cash']);
        // A campus with nothing wrong still has a row of zeros.
        $clean = 77;
        DB::table('Campus')->insert(['id' => $clean, 'name' => '乾淨分校', 'LineNotifyID' => '', 'Client_ID' => '', 'Client_Secret' => '', 'LIFFID' => '',
            'LIFF_URL' => '', 'URL' => '', 'TelegramURL' => '', 'TeachLIFFID' => '', 'TeachLIFF_URL' => '']);

        $data = $this->withToken($this->token('S'))->getJson('/api/v1/admin/billing-data-quality')->assertOk()->json('data');

        $this->assertSame(['zero_paid_bill', 'unpaid_over_60_days', 'ended_money_pending', 'paid_flag_vs_bill', 'monthly_bill_without_month', 'advance_monthly_bill'],
            array_column($data['rules'], 'key'));
        $byCampus = collect($data['campuses'])->keyBy('campus_id');
        $this->assertSame([1, 1, 1, 0, 0], array_values(array_intersect_key($byCampus[1]['counts'],
            array_flip(['zero_paid_bill', 'unpaid_over_60_days', 'ended_money_pending', 'monthly_bill_without_month', 'advance_monthly_bill']))));
        $this->assertSame([0, 0, 0, 1, 1], array_values(array_intersect_key($byCampus[2]['counts'],
            array_flip(['zero_paid_bill', 'unpaid_over_60_days', 'ended_money_pending', 'monthly_bill_without_month', 'advance_monthly_bill']))));
        foreach (['zero_paid_bill', 'unpaid_over_60_days', 'ended_money_pending', 'monthly_bill_without_month', 'advance_monthly_bill'] as $key) {
            $this->assertSame(1, $data['totals'][$key], $key);
        }
        $this->assertSame(0, array_sum($byCampus[$clean]['counts']));
        $this->assertSame('乾淨分校', $byCampus[$clean]['campus_name']);
        // Counts and campus names only: no student, no amount.
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('品質學生', $body);
        $this->assertStringNotContainsString('8800', $body);
    }

    public function test_is_super_admin_only_and_read_only(): void
    {
        $this->getJson('/api/v1/admin/billing-data-quality')->assertUnauthorized();
        $this->withToken($this->token('A'))->getJson('/api/v1/admin/billing-data-quality')->assertForbidden();
        $this->flushHeaders()->withToken($this->token('S'))->postJson('/api/v1/admin/billing-data-quality')->assertStatus(405);
        $this->assertSame(0, DB::table('Invoice')->count());
    }

    private function course(int $campusId, array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => '品質學生', 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-11-01', 'EndDate' => '2026-11-30', 'TotalHours' => 8, 'Charge' => 6000, 'Paid' => 0, 'Rate' => 1500,
            'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 4, 'SessionDuration' => 120,
            'RemainingSessions' => 4, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $overrides));
    }

    private function bill(StudentClass $course, array $overrides): Invoice
    {
        return Invoice::create(array_merge(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'DueDate' => '2026-11-30', 'PaidAmount' => 0], $overrides));
    }

    private function token(string $type): string
    {
        $user = User::create(['LoginName' => 'quality-' . uniqid() . '@test.com', 'Name' => 'Q', 'PSW' => 'secret', 'type' => $type, 'phone' => 912000003]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }
}

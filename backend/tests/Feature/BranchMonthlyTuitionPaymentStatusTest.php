<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Billing\BuildsMoneyFixtures;
use Tests\TestCase;

/** in-app #447: 當月學收 per-row month-scoped payment status + summary (existing Contract Money Verdict, no new rule). */
class BranchMonthlyTuitionPaymentStatusTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private const AUG = ['2026-08-03', '2026-08-10', '2026-08-17', '2026-08-24']; // 4 x 1500 = 6000

    private function token(): string
    {
        $user = User::create(['LoginName' => 'dir-447-' . uniqid() . '@test.com', 'Name' => 'D', 'PSW' => 'secret', 'type' => 'A', 'phone' => 900000447]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $t = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);

        return $t;
    }

    private function student(): Student
    {
        return Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function fetchReport(string $extra = '')
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30', 'Asia/Taipei'));

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8' . $extra);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_status_per_row_and_summary_for_monthly_count_and_package(): void
    {
        \Illuminate\Support\Facades\DB::table('Subject')->insertOrIgnore([['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1]]);
        $date = ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'];
        // monthly-billed: paid / partial / only a July invoice (verdict flags the uninvoiced August lessons => review)
        $paid = $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, self::AUG);
        $part = $this->course([['2026-08', 6000, 'partial', 1000, [[1000, 'cash']]]], $date, self::AUG);
        $none = $this->course([['2026-07', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, self::AUG);
        // count-mode prepaid contract: unpaid invoice vs paid invoice
        $cUnpaid = $this->course([['2026-08', 10000, 'unpaid', 0, []]], [], self::AUG);
        $cPaid = $this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]], [], self::AUG);
        // monthly package follows the package paid flag
        $pkgStudent = $this->student();
        CoursePackage::create(['student_id' => $pkgStudent->id, 'campus_id' => 1, 'name' => 'P', 'billing_mode' => CoursePackage::BILLING_MODE_MONTHLY, 'rate' => 1500,
            'rate_unit' => 'session', 'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
            'total_sessions' => 0, 'remaining_sessions' => 0, 'used_sessions' => 0]);

        $res = $this->fetchReport()->assertOk();
        $by = fn ($c) => collect($res->json('data'))->firstWhere('student_class_id', (int) $c->ID);

        $this->assertSame(['paid', 0], [$by($paid)['pay_status'], (int) $by($paid)['outstanding_amount']]);
        $this->assertSame(['partial', 5000, 6000], [$by($part)['pay_status'], (int) $by($part)['outstanding_amount'], (int) $by($part)['due_amount']]);
        $this->assertSame(['review', 0], [$by($none)['pay_status'], (int) $by($none)['outstanding_amount']]);
        $this->assertSame(['unpaid', 6000], [$by($cUnpaid)['pay_status'], (int) $by($cUnpaid)['outstanding_amount']]);
        $this->assertSame('paid', $by($cPaid)['pay_status']);
        $this->assertSame('unpaid', collect($res->json('data'))->firstWhere('row_type', 'package')['pay_status']);

        $s = $res->json('summary');
        $this->assertSame(24000, (int) $s['total_due']); // 4 confirmed course rows x 6000 (review row excluded), package 0 sessions
        // collected: paid 6000 + partial 1000 + prior-month-only 0 + count unpaid 0 + count paid 6000
        $this->assertSame(13000, (int) $s['total_collected']);
        $this->assertSame(24000 - (int) $s['total_collected'], (int) $s['total_outstanding']);
        $this->assertSame([2, 1, 6000], [(int) $s['unpaid_students'], (int) $s['review_students'], (int) $s['unconfirmed_amount']]); // part, cUnpaid; none = review
    }

    public function test_unpaid_only_filters_rows_but_summary_stays_whole_month(): void
    {
        $date = ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'];
        $paid = $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, self::AUG);
        $none = $this->course([], $date, self::AUG);

        $res = $this->fetchReport('&unpaid_only=1')->assertOk();
        $ids = collect($res->json('data'))->pluck('student_class_id')->map(fn ($i) => (int) $i)->all();
        $this->assertSame([(int) $none->ID], $ids);
        $this->assertSame(1, $res->json('meta.total'));
        $this->assertSame(12000, (int) $res->json('summary.total_due'));
    }

    public function test_all_collected_has_zero_outstanding(): void
    {
        $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'], self::AUG);
        $s = $this->fetchReport()->assertOk()->json('summary');
        $this->assertSame([0, 0], [(int) $s['total_outstanding'], (int) $s['unpaid_students']]);
    }

    public function test_export_has_status_columns(): void
    {
        $this->course([['2026-08', 6000, 'partial', 1000, [[1000, 'cash']]]], ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'], self::AUG);
        Carbon::setTestNow(Carbon::parse('2026-10-30', 'Asia/Taipei'));
        $csv = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token()])
            ->get('/api/v1/finance/branch-monthly-tuition/export?branch_id=1&year=2026&month=8')->streamedContent();
        $this->assertStringContainsString('收款狀態', $csv);
        $this->assertStringContainsString('部分,5000', $csv);
    }

    /** Codex P2 round: review scoped to the month, estimates kept out of confirmed totals, FIFO partial, legacy flag. */
    public function test_review_scope_unconfirmed_totals_fifo_partial_and_legacy_flag(): void
    {
        $date = ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'];
        // paid August invoice + uninvoiced September lessons: August stays 已收
        $augPaid = $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, self::AUG);
        foreach (['2026-09-07', '2026-09-14'] as $d) {
            \App\Models\ClassSession::create(['StudentClassID' => $augPaid->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        // count-mode partial: 4 Aug lessons (6000) after 6 Jul lessons (9000); 10000 applied => Jul covered, Aug owes 5000
        $partial = $this->course([['2026-08', 15000, 'partial', 10000, [[10000, 'cash']]]], ['Charge' => 15000], self::AUG);
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06'] as $d) {
            \App\Models\ClassSession::create(['StudentClassID' => $partial->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        // Paid flag only, no invoice/payment evidence
        $legacy = $this->course([], ['Paid' => 1, 'Charge' => 6000], self::AUG);

        $res = $this->fetchReport()->assertOk();
        $by = fn ($c) => collect($res->json('data'))->firstWhere('student_class_id', (int) $c->ID);
        $this->assertSame('paid', $by($augPaid)['pay_status']);
        $this->assertSame(['partial', 5000], [$by($partial)['pay_status'], (int) $by($partial)['outstanding_amount']]);
        $this->assertSame(['review', 0, 6000], [$by($legacy)['pay_status'], (int) $by($legacy)['outstanding_amount'], (int) $by($legacy)['estimated_amount']]);
        $s = $res->json('summary');
        $this->assertSame(5000, (int) $s['total_outstanding']); // review estimate is not debt
        $this->assertSame([6000, 1, 1], [(int) $s['unconfirmed_amount'], (int) $s['review_students'], (int) $s['unpaid_students']]);
    }

    /** Codex P1 round: invoice total below gross lesson value bounds FIFO; a same-month discrepancy stays review. */
    public function test_discounted_contract_bounds_fifo_and_same_month_discrepancy_is_review(): void
    {
        // 6 Jul + 4 Aug lessons at 1500 (gross 15000), invoice total 10000, applied 9000 => only 1000 owed in total
        $disc = $this->course([['2026-08', 10000, 'partial', 9000, [[9000, 'cash']]]], ['Charge' => 10000], self::AUG);
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06'] as $d) {
            \App\Models\ClassSession::create(['StudentClassID' => $disc->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        // paid 6000 August invoice while 5 August lessons imply 7500
        $date = ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'];
        $mism = $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, [...self::AUG, '2026-08-31']);

        $res = $this->fetchReport()->assertOk();
        $by = fn ($c) => collect($res->json('data'))->firstWhere('student_class_id', (int) $c->ID);
        $this->assertSame(['partial', 1000, 4000], [$by($disc)['pay_status'], (int) $by($disc)['outstanding_amount'], (int) $by($disc)['due_amount']]);
        $this->assertSame('review', $by($mism)['pay_status']);
    }

    /** Codex P1 round 3: settled discounted contract, same-month coverage gap, waived contract. */
    public function test_settled_discount_coverage_gap_and_waived(): void
    {
        // discounted contract fully paid: collected stays at the 4000 August share of the 10000 ledger value
        $settled = $this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]], ['Charge' => 10000], self::AUG);
        foreach (['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04', '2026-07-05', '2026-07-06'] as $d) {
            \App\Models\ClassSession::create(['StudentClassID' => $settled->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        }
        // August invoice only covers Aug 1-15 while lessons also fall on Aug 17 and 24
        $date = ['ScheduleMode' => 'date', 'Charge' => 6000, 'StartDate' => '2026-08-01'];
        $gap = $this->course([['2026-08', 6000, 'paid', 6000, [[6000, 'cash']]]], $date, self::AUG);
        \App\Models\InvoiceItem::query()->where('StudentClassID', $gap->ID)->delete();
        \App\Models\InvoiceItem::create(['InvoiceID' => \App\Models\Invoice::where('StudentClassID', $gap->ID)->value('id'), 'StudentClassID' => $gap->ID,
            'Description' => 'gap', 'Amount' => 6000, 'PeriodStart' => '2026-08-01', 'PeriodEnd' => '2026-08-15']);
        // waived contract with attended lessons
        $waived = $this->course([], ['closed_reason' => 'waived', 'Stop' => 1, 'Paid' => 1], self::AUG);

        $res = $this->fetchReport()->assertOk();
        $by = fn ($c) => collect($res->json('data'))->firstWhere('student_class_id', (int) $c->ID);
        $this->assertSame(['paid', 4000], [$by($settled)['pay_status'], (int) $by($settled)['due_amount']]);
        $this->assertSame('review', $by($gap)['pay_status']);
        $this->assertSame(['waived', 0], [$by($waived)['pay_status'], (int) $by($waived)['due_amount']]);
        $this->assertSame(0, (int) $res->json('summary.total_outstanding'));
        $this->assertNotContains((int) $waived->ID, collect($this->fetchReport('&unpaid_only=1')->json('data'))->pluck('student_class_id')->map(fn ($i) => (int) $i)->all());
    }
}

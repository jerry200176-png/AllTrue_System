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
        $this->assertSame(['review', 6000], [$by($none)['pay_status'], (int) $by($none)['outstanding_amount']]);
        $this->assertSame(['unpaid', 6000], [$by($cUnpaid)['pay_status'], (int) $by($cUnpaid)['outstanding_amount']]);
        $this->assertSame('paid', $by($cPaid)['pay_status']);
        $this->assertSame('unpaid', collect($res->json('data'))->firstWhere('row_type', 'package')['pay_status']);

        $s = $res->json('summary');
        $this->assertSame(30000, (int) $s['total_due']); // 5 course rows x 6000, package 0 sessions
        // collected: paid 6000 + partial 1000 + prior-month-only 0 + count unpaid 0 + count paid 6000
        $this->assertSame(13000, (int) $s['total_collected']);
        $this->assertSame(30000 - (int) $s['total_collected'], (int) $s['total_outstanding']);
        $this->assertSame(3, (int) $s['unpaid_students']); // part, none, cUnpaid (package has 0 due)
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
}

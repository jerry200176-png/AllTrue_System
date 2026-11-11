<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\Billing\PackagePaidStatusRule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** in-app #537: 當月學收 package rows and AlertController::tuition settle a monthly package by the same rule (#3985). */
class PackagePaidStatusRuleParityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_rule_truth_table(): void
    {
        $s = fn (string ...$x) => collect($x);
        $this->assertFalse(PackagePaidStatusRule::settled($s('paid', 'unpaid'), true));
        $this->assertFalse(PackagePaidStatusRule::settled($s('paid', 'partial'), true));
        $this->assertFalse(PackagePaidStatusRule::settled($s('paid', 'unbilled'), true));
        $this->assertTrue(PackagePaidStatusRule::settled($s('paid', 'free'), false));
        $this->assertTrue(PackagePaidStatusRule::settled($s('review_required'), true));
        $this->assertFalse(PackagePaidStatusRule::settled($s('review_required'), false));
        $this->assertTrue(PackagePaidStatusRule::settled($s(), true));
        $this->assertFalse(PackagePaidStatusRule::settled($s(), false));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('billCases')]
    public function test_alert_and_report_agree_on_paid(int $paidAmount): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Asia/Taipei'));
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
        ]);
        $user = User::create(['LoginName' => 'dir-par-' . uniqid() . '@test.com', 'Name' => 'D', 'PSW' => 'secret', 'type' => 'A', 'phone' => 900000538]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $tok = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Accept' => 'application/json'];
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $teacher = User::create(['LoginName' => 't-par-' . uniqid() . '@test.com', 'Name' => 'T' . uniqid(), 'PSW' => 'secret', 'type' => 'T', 'phone' => 911000538]);
        $pkgId = (int) $this->withHeaders($h)->postJson('/api/v1/course-packages/create-multi-subject', [
            'student_id' => $student->id, 'branch_id' => 1, 'name' => 'pkg-par', 'payment_type' => 'monthly', 'rate' => 800, 'settlement_day' => 30,
            'subjects' => [['subject_id' => 1, 'teacher_id' => $teacher->id, 'duration_hours' => 2], ['subject_id' => 2, 'teacher_id' => $teacher->id, 'duration_hours' => 2]],
        ])->assertStatus(201)->json('package_id');
        foreach (StudentClass::where('PackageID', $pkgId)->get() as $member) {
            $inv = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $member->ID, 'IssueDate' => '2026-10-30', 'DueDate' => '2026-11-09',
                'TotalAmount' => 1600, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-10']);
            if ($paidAmount > 0) {
                Payment::create(['InvoiceID' => $inv->id, 'Amount' => $paidAmount, 'PaidAt' => '2026-10-30', 'Method' => 'cash']);
            }
        }

        $alert = collect($this->withHeaders($h)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())->firstWhere('package_id', $pkgId);
        $row = collect($this->withHeaders($h)->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=10')->assertOk()->json('data'))->firstWhere('package_id', $pkgId);

        $this->assertNotNull($alert);
        $this->assertSame((bool) $alert['paid'], $row['pay_status'] === 'paid');
    }

    public static function billCases(): array
    {
        return ['fully paid' => [1600], 'unpaid' => [0], 'partly paid' => [600]];
    }
}

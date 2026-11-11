<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * in-app #537 / #5199: a monthly-billing package row on 當月學收 must follow the month-scoped bills of its member
 * contracts (not the package-level flag), and a package with no lesson that month is not listed (same as regular rows).
 */
class BranchMonthlyTuitionPackageRowTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 700;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function token(): string
    {
        $user = User::create(['LoginName' => 'dir-537-' . uniqid() . '@test.com', 'Name' => 'D', 'PSW' => 'secret', 'type' => 'A', 'phone' => 900000537]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $t = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);

        return $t;
    }

    /** Monthly package with two members; $sessions lessons in 2026-08 on the first member. */
    private function package(int $sessions, bool $flag = false): array
    {
        DB::table('Subject')->insertOrIgnore([
            ['id' => 1, 'Subject_Name' => 'Math', 'School_id' => 1, 'Grade_no' => 1],
            ['id' => 2, 'Subject_Name' => 'English', 'School_id' => 1, 'Grade_no' => 1],
        ]);
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $teacher = User::create(['LoginName' => 't537-' . uniqid() . '@test.com', 'Name' => 'T' . uniqid(), 'PSW' => 'secret', 'type' => 'T', 'phone' => 911000537]);
        $pkgId = (int) $this->withHeaders(['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'])
            ->postJson('/api/v1/course-packages/create-multi-subject', [
                'student_id' => $student->id, 'branch_id' => 1, 'name' => 'pkg-537', 'payment_type' => 'monthly', 'rate' => self::RATE,
                'settlement_day' => 5,
                'subjects' => [['subject_id' => 1, 'teacher_id' => $teacher->id, 'duration_hours' => 2], ['subject_id' => 2, 'teacher_id' => $teacher->id, 'duration_hours' => 2]],
            ])->assertStatus(201)->json('package_id');
        CoursePackage::query()->whereKey($pkgId)->update(['paid' => $flag ? 1 : 0]);
        $members = StudentClass::where('PackageID', $pkgId)->orderBy('ID')->get();
        StudentClass::where('PackageID', $pkgId)->update(['StartDate' => '2026-08-01']);
        for ($i = 0; $i < $sessions; $i++) {
            ClassSession::create(['StudentClassID' => $members[0]->ID, 'SessionDate' => sprintf('2026-08-%02d', 3 + 7 * $i), 'StartTime' => '16:00', 'EndTime' => '18:00', 'Status' => 'attended']);
        }

        return [$pkgId, $student, $members];
    }

    private function bill(Student $student, StudentClass $member, int $total, int $paid): void
    {
        $invoice = Invoice::create(['StudentID' => $student->id, 'StudentClassID' => $member->ID, 'IssueDate' => '2026-08-01', 'DueDate' => '2026-08-10',
            'TotalAmount' => $total, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-08']);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => '2026-08-05', 'Method' => 'cash']);
        }
    }

    private function report()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30', 'Asia/Taipei'));

        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token(), 'Accept' => 'application/json'])
            ->getJson('/api/v1/finance/branch-monthly-tuition?branch_id=1&year=2026&month=8')->assertOk();
    }

    private function pkgRow($res, int $pkgId): ?array
    {
        return collect($res->json('data'))->firstWhere('package_id', $pkgId);
    }

    public function test_package_with_no_lesson_in_the_month_is_not_listed_or_counted(): void
    {
        [$pkgId] = $this->package(0);
        $res = $this->report();

        $this->assertNull($this->pkgRow($res, $pkgId));
        $this->assertSame(0, (int) $res->json('summary.total_students'));
        $this->assertSame(0, (int) $res->json('summary.unpaid_students'));
        $this->assertSame(0, (int) $res->json('meta.total'));
    }

    public function test_package_whose_member_bill_for_the_month_is_paid_reads_paid_despite_unset_flag(): void
    {
        [$pkgId, $student, $m] = $this->package(2);
        $this->bill($student, $m[0], 2 * self::RATE, 2 * self::RATE);
        $row = $this->pkgRow($this->report(), $pkgId);

        $this->assertSame(['paid', 0], [$row['pay_status'], (int) $row['outstanding_amount']]);
        $this->assertSame(2 * self::RATE, (int) $row['due_amount']);
    }

    public function test_package_with_an_unpaid_month_bill_stays_unpaid_even_if_flag_says_paid(): void
    {
        [$pkgId, $student, $m] = $this->package(2, true);
        $this->bill($student, $m[0], 2 * self::RATE, 0);
        $row = $this->pkgRow($this->report(), $pkgId);

        $this->assertSame(['unpaid', 2 * self::RATE], [$row['pay_status'], (int) $row['outstanding_amount']]);
    }

    public function test_package_with_a_partial_payment_reads_partial(): void
    {
        [$pkgId, $student, $m] = $this->package(2);
        $this->bill($student, $m[0], 2 * self::RATE, 400);
        $res = $this->report();
        $row = $this->pkgRow($res, $pkgId);

        $this->assertSame(['partial', 2 * self::RATE - 400], [$row['pay_status'], (int) $row['outstanding_amount']]);
        $this->assertSame(1, (int) $res->json('summary.unpaid_students'));
    }

    public function test_package_with_lessons_and_no_bill_keeps_following_the_package_flag(): void
    {
        [$pkgA] = $this->package(1, false);
        [$pkgB] = $this->package(1, true);
        $res = $this->report();

        $this->assertSame('unpaid', $this->pkgRow($res, $pkgA)['pay_status']);
        $this->assertSame('paid', $this->pkgRow($res, $pkgB)['pay_status']);
    }
}

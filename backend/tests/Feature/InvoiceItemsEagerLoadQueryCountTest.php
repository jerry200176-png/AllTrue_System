<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #3454 — InvoiceAmountReconciliationService::resolve() lazy-loads invoice items
 * per invoice unless the caller eager-loads them. InvoiceItem query count must
 * stay constant as the number of invoices grows.
 */
class InvoiceItemsEagerLoadQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_list_invoice_item_queries_do_not_grow_with_course_count(): void
    {
        $this->assertConstant(fn (int $n) => $this->seedCourses($n), fn () => '/api/v1/alerts/tuition?branch_id=1');
    }

    public function test_settled_courses_invoice_item_queries_do_not_grow_with_course_count(): void
    {
        $this->assertConstant(fn (int $n) => $this->seedCourses($n, ['Paid' => 1]), fn () => '/api/v1/accounting/settled-courses?branch_id=1');
    }

    public function test_payment_report_list_invoice_item_queries_do_not_grow_with_report_count(): void
    {
        $this->assertConstant(function (int $n) {
            foreach ($this->seedCourses($n) as $cid) {
                $this->seedReport($cid);
            }
        }, fn () => '/api/v1/payment-reports');
    }

    public function test_ledger_invoice_item_queries_do_not_grow_with_invoice_count(): void
    {
        $cid = 0;
        $this->assertConstant(function (int $n) use (&$cid) {
            $cid = $this->seedOneCourseWithInvoices($n);
        }, function () use (&$cid) {
            return "/api/v1/accounting/ledger?student_class_id={$cid}";
        });
    }

    public function test_student_class_invoices_item_queries_do_not_grow_with_invoice_count(): void
    {
        $cid = 0;
        $this->assertConstant(function (int $n) use (&$cid) {
            $cid = $this->seedOneCourseWithInvoices($n);
        }, function () use (&$cid) {
            return "/api/v1/student-classes/{$cid}/invoices";
        });
    }

    /** Not covered here: AccountingController::waiveCourse (write path with lock), UnpaidHiddenClosuresStrategy (ops manifest). */
    private function assertConstant(\Closure $seed, \Closure $url): void
    {
        $token = $this->token();
        $small = $this->itemQueries(fn () => $seed(2), $url, $token);
        $large = $this->itemQueries(fn () => $seed(8), $url, $token);

        $this->assertGreaterThanOrEqual(1, $small, 'seeded invoices never reached InvoiceItem loading (vacuous test)');
        $this->assertSame($small, $large, "InvoiceItem queries grew with row count: 2={$small}, 8={$large}");
    }

    private function seedOneCourseWithInvoices(int $n): int
    {
        $cid = $this->seedCourses(1)[0];
        for ($i = 1; $i < $n; $i++) {
            $this->seedInvoice($cid, sprintf('2026-%02d', $i + 1));
        }

        return $cid;
    }

    private function seedReport(int $courseId): void
    {
        $c = DB::table('StudentClass')->where('ID', $courseId)->first();
        DB::table('payment_reports')->insert([
            'StudentID' => $c->StudentID, 'StudentClassID' => $courseId,
            'InvoiceID' => DB::table('Invoice')->where('StudentClassID', $courseId)->value('id'),
            'reported_by_name' => 'x', 'payment_date' => '2026-09-10', 'payment_method' => 'cash', 'reported_amount' => 1000,
            'status' => 'pending', 'report_token_hash' => hash('sha256', "r{$courseId}"), 'token_expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param string|\Closure $url */
    private function itemQueries(\Closure $seed, $url, string $token): int
    {
        $seed();
        $url = $url instanceof \Closure ? $url() : $url;
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url, ['Authorization' => "Bearer {$token}"])->assertOk();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn ($q) => str_contains((string) $q['query'], '`InvoiceItem`')));
    }

    /** @return int[] course ids (date-mode, settlement day set, one unpaid invoice with one item each) */
    private function seedCourses(int $n, array $extra = []): array
    {
        DB::table('payment_reports')->delete();
        DB::table('InvoiceItem')->delete();
        DB::table('Invoice')->delete();
        DB::table('StudentClass')->delete();
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $studentId = DB::table('Student')->insertGetId(['name' => "iie-{$n}-{$i}", 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
            $ids[] = $cid = DB::table('StudentClass')->insertGetId([
                'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
                'Period' => 4, 'TotalHours' => 0, 'Charge' => 1000, 'Pay' => 0, 'Rate' => 1000,
                'ClassType' => 'one_on_one', 'StartDate' => '2026-09-01 00:00:00', 'EndDate' => '2027-09-01 00:00:00',
                'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8, 'UsedSessions' => 0,
                'Stop' => 0, 'ScheduleMode' => 'date', 'settlement_day' => 5,
            ] + $extra + ['Paid' => 0]);
            $this->seedInvoice($cid, '2026-09');
        }

        return $ids;
    }

    private function seedInvoice(int $courseId, string $period): void
    {
        $studentId = DB::table('StudentClass')->where('ID', $courseId)->value('StudentID');
        $invoiceId = DB::table('Invoice')->insertGetId([
            'StudentID' => $studentId, 'StudentClassID' => $courseId, 'billing_period' => $period,
            'IssueDate' => "{$period}-01", 'DueDate' => "{$period}-10", 'TotalAmount' => 1000, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);
        DB::table('InvoiceItem')->insert([
            'InvoiceID' => $invoiceId, 'StudentClassID' => $courseId, 'Description' => 'tuition', 'Amount' => 1000,
            'PeriodStart' => "{$period}-01", 'PeriodEnd' => "{$period}-28",
        ]);
    }

    private function token(): string
    {
        $user = User::create(['LoginName' => 'iie@example.com', 'Name' => 'iie', 'PSW' => bcrypt('x'), 'type' => 'D', 'status' => 'active']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }
}

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
        $token = $this->token();
        $small = $this->itemQueries(fn () => $this->seedCourses(2), '/api/v1/alerts/tuition?branch_id=1', $token);
        $large = $this->itemQueries(fn () => $this->seedCourses(8), '/api/v1/alerts/tuition?branch_id=1', $token);

        $this->assertSame($small, $large, "InvoiceItem queries grew with course count: 2={$small}, 8={$large}");
    }

    public function test_ledger_invoice_item_queries_do_not_grow_with_invoice_count(): void
    {
        $token = $this->token();
        $cid = 0;
        $run = function (int $n) use ($token, &$cid) {
            return $this->itemQueries(function () use ($n, &$cid) {
                $cid = $this->seedCourses(1)[0];
                for ($i = 1; $i < $n; $i++) {
                    $this->seedInvoice($cid, sprintf('2026-%02d', $i + 1));
                }
            }, function () use (&$cid) { return "/api/v1/accounting/ledger?student_class_id={$cid}"; }, $token);
        };

        $small = $run(2);
        $large = $run(8);

        $this->assertSame($small, $large, "InvoiceItem queries grew with invoice count: 2={$small}, 8={$large}");
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
    private function seedCourses(int $n): array
    {
        DB::table('InvoiceItem')->delete();
        DB::table('Invoice')->delete();
        DB::table('StudentClass')->delete();
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $studentId = DB::table('Student')->insertGetId(['name' => "iie-{$n}-{$i}", 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
            $ids[] = $cid = DB::table('StudentClass')->insertGetId([
                'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
                'Period' => 4, 'TotalHours' => 0, 'Charge' => 1000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1000,
                'ClassType' => 'one_on_one', 'StartDate' => '2026-09-01 00:00:00', 'EndDate' => '2027-09-01 00:00:00',
                'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8, 'UsedSessions' => 0,
                'Stop' => 0, 'ScheduleMode' => 'date', 'settlement_day' => 5,
            ]);
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

<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AssertsQueryBudget;
use Tests\TestCase;

/**
 * Query budgets for the director endpoints behind the Sentry N+1 alerts (cluster F).
 * Seeds ROWS rows per list; a per-row query would add >= ROWS queries and blow the budget.
 */
class DirectorEndpointQueryBudgetTest extends TestCase
{
    use AssertsQueryBudget;
    use RefreshDatabase;

    private const ROWS = 8;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'qb@example.com', 'Name' => 'qb', 'PSW' => bcrypt('x'), 'type' => 'D', 'status' => 'active']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token = bin2hex(random_bytes(16)), 'expires_at' => now()->addDay()]);
        $this->seed8();
    }

    public function test_duplicate_courses_budget(): void
    {
        $this->assertBudget(10, '/api/v1/finance/duplicate-courses');
    }

    public function test_attendance_list_budget(): void
    {
        $this->assertBudget(8, '/api/v1/attendance');
    }

    public function test_class_sessions_index_budget(): void
    {
        $this->assertBudget(7, '/api/v1/class-sessions?from=2026-09-01&to=2026-09-30');
    }

    public function test_accounting_ledger_budget(): void
    {
        $cid = (int) DB::table('StudentClass')->value('ID');
        // Measured 19 at 2 courses x 8 invoices (was 66-83 with per-invoice session and subject lookups).
        $this->assertBudget(21, "/api/v1/accounting/ledger?student_class_id={$cid}");
    }

    public function test_students_index_budget(): void
    {
        $this->assertBudget(8, '/api/v1/students');
    }

    private function assertBudget(int $max, string $url): void
    {
        $this->assertQueryCountAtMost($max, fn () => $this->getJson($url, ['Authorization' => "Bearer {$this->token}"])->assertOk());
    }

    private function seed8(): void
    {
        for ($i = 0; $i < self::ROWS; $i++) {
            $sid = DB::table('Student')->insertGetId(['name' => "qb-{$i}", 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
            // Two active courses with the same subject per student => a duplicate-courses group each.
            foreach ([0, 1] as $k) {
                $cid = DB::table('StudentClass')->insertGetId([
                    'StudentID' => $sid, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1,
                    'Period' => 4, 'TotalHours' => 0, 'Charge' => 1000, 'Pay' => 0, 'Rate' => 1000, 'Paid' => 0,
                    'ClassType' => 'one_on_one', 'StartDate' => '2026-09-01 00:00:00', 'EndDate' => '2027-09-01 00:00:00',
                    'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8, 'UsedSessions' => 0,
                    'Stop' => 0, 'ScheduleMode' => 'date', 'settlement_day' => 5,
                ]);
                $sessionId = DB::table('ClassSession')->insertGetId([
                    'StudentClassID' => $cid, 'SessionDate' => '2026-09-'.sprintf('%02d', 1 + $i),
                    'StartTime' => sprintf('%02d:00:00', 10 + $k), 'EndTime' => sprintf('%02d:00:00', 11 + $k),
                    'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('StudentSingIn')->insert([
                    'StudentID' => $sid, 'StudentClassID' => $cid, 'ClassSessionID' => $sessionId, 'TeacherID' => 1,
                    'CampusID' => 1, 'SubjectID' => 1, 'Status' => 'attended', 'SignInDT' => now(),
                ]);
                // Every course carries ROWS invoices so the ledger endpoint is exercised with a long history.
                for ($m = 1; $m <= self::ROWS; $m++) {
                    $period = sprintf('2026-%02d', $m);
                    $invoiceId = DB::table('Invoice')->insertGetId([
                        'StudentID' => $sid, 'StudentClassID' => $cid, 'billing_period' => $period,
                        'IssueDate' => "{$period}-01", 'DueDate' => "{$period}-10", 'TotalAmount' => 1000, 'PaidAmount' => 0, 'Status' => 'unpaid',
                    ]);
                    DB::table('InvoiceItem')->insert([
                        'InvoiceID' => $invoiceId, 'StudentClassID' => $cid, 'Description' => 'tuition', 'Amount' => 1000,
                        'PeriodStart' => "{$period}-01", 'PeriodEnd' => "{$period}-28",
                    ]);
                }
            }
        }
    }
}

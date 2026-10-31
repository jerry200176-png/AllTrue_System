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
 * Pins the GET accounting/ledger payload for a multi-contract student byte-for-byte, so the
 * query batching (sessions, pricing amendments, subject names) cannot change an amount,
 * status or field. Regenerate only for an intended payload change: LEDGER_SNAPSHOT_UPDATE=1.
 */
class AccountingLedgerPayloadSnapshotTest extends TestCase
{
    use AssertsQueryBudget;
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../Fixtures/accounting_ledger_multi_contract.json';

    public function test_ledger_payload_is_unchanged_and_within_query_budget(): void
    {
        $user = User::create(['LoginName' => 'snap@example.com', 'Name' => 'snap', 'PSW' => bcrypt('x'), 'type' => 'D', 'status' => 'active']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => 'snap-token', 'expires_at' => now()->addDay()]);
        $this->seedStudent();

        $payload = null;
        $this->assertQueryCountAtMost(26, function () use (&$payload) {
            $payload = $this->getJson('/api/v1/accounting/ledger?student_class_id=9101', ['Authorization' => 'Bearer snap-token'])
                ->assertOk()->json();
        });
        // Compact one-line fixture (keeps the PR under the 700-line presubmit limit); compared as decoded data.
        if (getenv('LEDGER_SNAPSHOT_UPDATE') === '1') {
            file_put_contents(self::FIXTURE, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), $payload);
    }

    public function test_each_course_carries_no_payment_obligation_with_the_scope_rule(): void
    {
        $user = User::create(['LoginName' => 'snap2@example.com', 'Name' => 'snap2', 'PSW' => bcrypt('x'), 'type' => 'D', 'status' => 'active']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        AuthToken::create(['user_id' => $user->id, 'token' => 'snap-token-2', 'expires_at' => now()->addDay()]);
        $this->seedStudent();
        DB::table('StudentClass')->where('ID', 9102)->update(['ClassType' => 'tutoring']);

        $json = $this->getJson('/api/v1/accounting/ledger?student_class_id=9101', ['Authorization' => 'Bearer snap-token-2'])->assertOk()->json();
        $byId = collect($json['courses'])->pluck('no_payment_obligation', 'id')->sortKeys()->all();
        $this->assertSame([9101 => false, 9102 => true, 9103 => false], $byId);
        $this->assertFalse($json['scope']['no_payment_obligation']);

        // Opened on the tutoring course, scope and its own course row agree.
        $opened = $this->getJson('/api/v1/accounting/ledger?student_class_id=9102', ['Authorization' => 'Bearer snap-token-2'])->assertOk()->json();
        $this->assertTrue($opened['scope']['no_payment_obligation']);
        $this->assertTrue(collect($opened['courses'])->firstWhere('id', 9102)['no_payment_obligation']);
    }

    private function seedStudent(): void
    {
        DB::table('Student')->insert(['id' => 9001, 'name' => 'snap', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        $base = [
            'StudentID' => 9001, 'GradeID' => 1, 'SubjectID' => 9601, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4, 'rate_unit' => 'session', 'TotalHours' => 0,
            'Pay' => 0, 'Paid' => 0, 'ClassType' => 'one_on_one', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'Stop' => 0, 'settlement_day' => 5,
        ];
        DB::table('Subject')->insert(['id' => 9601, 'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Chinese']);
        DB::table('BaseData')->insert(['id' => 9501, 'Name' => '課程', 'Val' => 'Piano', 'OrderID' => 1]);
        DB::table('StudentClass')->insert([
            ['ID' => 9101, 'Charge' => 4000, 'Rate' => 1000, 'ScheduleMode' => 'date', 'StartDate' => '2026-07-01 00:00:00', 'EndDate' => '2027-07-01 00:00:00'] + $base,
            // Subject only in the legacy BaseData table, hourly rate.
            ['ID' => 9102, 'Charge' => 3000, 'Rate' => 500, 'rate_unit' => 'hour', 'SubjectID' => 9501, 'ScheduleMode' => 'date', 'StartDate' => '2026-08-01 00:00:00', 'EndDate' => '2027-08-01 00:00:00'] + $base,
            // Count mode, unknown subject id.
            ['ID' => 9103, 'Charge' => 8000, 'Rate' => 1000, 'SubjectID' => 99999, 'ScheduleMode' => 'count', 'StartDate' => '2026-06-01 00:00:00', 'EndDate' => '2027-06-01 00:00:00'] + $base,
        ]);
        $sessions = [];
        foreach ([9101, 9102, 9103] as $cid) {
            foreach (['2026-07-06' => 'attended', '2026-08-04' => 'attended', '2026-08-18' => 'late', '2026-08-25' => 'leave', '2026-09-01' => 'completed', '2026-09-08' => 'scheduled', '2026-10-06' => 'cancelled', '2026-10-13' => 'scheduled'] as $d => $st) {
                $sessions[] = ['StudentClassID' => $cid, 'SessionDate' => $d, 'StartTime' => '13:00:00', 'EndTime' => '15:00:00', 'Status' => $st, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        DB::table('ClassSession')->insert($sessions);
        DB::table('student_class_pricing_amendments')->insert([
            ['student_class_id' => 9101, 'effective_from' => '2026-08-10', 'rate' => 1250, 'rate_unit' => 'session', 'source_reference' => 's', 'reason' => 't', 'created_at' => now(), 'voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null],
            ['student_class_id' => 9101, 'effective_from' => '2026-08-12', 'rate' => 9999, 'rate_unit' => 'session', 'source_reference' => 's', 'reason' => 't', 'created_at' => now(), 'voided_at' => now(), 'voided_by_user_id' => 4, 'void_reason' => 'x'],
            ['student_class_id' => 9102, 'effective_from' => '2026-09-01', 'rate' => 700, 'rate_unit' => 'hour', 'source_reference' => 's', 'reason' => 't', 'created_at' => now(), 'voided_at' => null, 'voided_by_user_id' => null, 'void_reason' => null],
        ]);

        $n = 9200;
        foreach ([9101, 9102, 9103] as $cid) {
            foreach (['2026-07' => 'paid', '2026-08' => 'unpaid', '2026-09' => 'partial', '2026-10' => 'unpaid', '2026-11' => 'void'] as $period => $status) {
                $id = ++$n;
                DB::table('Invoice')->insert([
                    'id' => $id, 'StudentID' => 9001, 'StudentClassID' => $cid, 'billing_period' => $period,
                    'IssueDate' => "{$period}-01", 'DueDate' => "{$period}-10", 'TotalAmount' => 3333, 'PaidAmount' => $status === 'paid' ? 3333 : ($status === 'partial' ? 1000 : 0), 'Status' => $status,
                ]);
                // Cross-month service range on one invoice, plain month range on the rest.
                $cross = $id === 9202;
                DB::table('InvoiceItem')->insert([
                    'InvoiceID' => $id, 'StudentClassID' => $cid, 'Description' => 'tuition', 'Amount' => 3333,
                    'PeriodStart' => $cross ? '2026-08-28' : "{$period}-01", 'PeriodEnd' => $cross ? '2026-09-27' : "{$period}-28",
                ]);
                if ($status === 'paid' || $status === 'partial') {
                    DB::table('Payment')->insert(['id' => $id + 1000, 'InvoiceID' => $id, 'Amount' => $status === 'paid' ? 3333 : 1000, 'PaidAt' => "{$period}-05 10:00:00", 'Method' => 'cash']);
                }
            }
        }
    }
}

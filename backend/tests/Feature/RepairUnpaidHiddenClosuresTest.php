<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairUnpaidHiddenClosuresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 164, 'name' => 'S', 'CampusID' => 16, 'ClassID' => 1, 'enable' => 1]);
        $base = [
            'StudentID' => 164, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'TotalHours' => 0, 'StartDate' => '2026-09-01 00:00:00', 'EndDate' => '2026-09-30 00:00:00',
            'Charge' => 6600, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1650, 'rate_unit' => 'session', 'SessionDuration' => 120,
            'ScheduleMode' => 'date', 'SessionCount' => 4, 'UsedSessions' => 0, 'RemainingSessions' => 0, 'Stop' => 1,
        ];
        DB::table('StudentClass')->insert([
            array_merge($base, ['ID' => 3516, 'closed_reason' => 'settled']),          // unpaid, hidden -> candidate
            array_merge($base, ['ID' => 3517, 'closed_reason' => 'completed']),        // unpaid, hidden -> candidate
            array_merge($base, ['ID' => 3518, 'closed_reason' => 'settled', 'Paid' => 1]), // paid flag
            array_merge($base, ['ID' => 3519, 'closed_reason' => 'settled']),          // paid by invoice
            array_merge($base, ['ID' => 3520, 'closed_reason' => 'settled_pending']),  // already visible
            array_merge($base, ['ID' => 3521, 'closed_reason' => 'converted_trial']),  // not in scope
            array_merge($base, ['ID' => 3522, 'closed_reason' => 'settled', 'Charge' => 0]), // nothing owed
        ]);
        DB::table('Invoice')->insert([
            'id' => 900, 'StudentID' => 164, 'StudentClassID' => 3519, 'IssueDate' => '2026-09-01',
            'TotalAmount' => 6600, 'PaidAmount' => 6600, 'Status' => 'paid', 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function digest(): string
    {
        Artisan::call('repair:unpaid-hidden-closures');
        preg_match('/DIGEST=([0-9a-f]+)/', Artisan::output(), $m);

        return $m[1];
    }

    private function reason(int $id): ?string
    {
        return DB::table('StudentClass')->where('ID', $id)->value('closed_reason');
    }

    public function test_dry_run_lists_only_unpaid_hidden_closures_and_changes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures'));
        $out = Artisan::output();
        $this->assertStringContainsString('course=3516', $out);
        $this->assertStringContainsString('course=3517', $out);
        foreach ([3518, 3519, 3520, 3521, 3522] as $id) {
            $this->assertStringNotContainsString("course={$id}", $out);
        }
        $this->assertStringContainsString('CANDIDATES=2 OUTSTANDING_TOTAL=13200', $out);
        $this->assertSame('settled', $this->reason(3516));
    }

    public function test_execute_requires_matching_digest(): void
    {
        $this->assertSame(1, Artisan::call('repair:unpaid-hidden-closures', ['--execute' => true, '--expect-digest' => 'deadbeef']));
        $this->assertSame('settled', $this->reason(3516));
    }

    public function test_execute_moves_candidates_to_pending_and_rollback_restores(): void
    {
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--execute' => true, '--expect-digest' => $this->digest()]));
        $this->assertSame('settled_pending', $this->reason(3516));
        $this->assertSame('settled_pending', $this->reason(3517));
        $this->assertSame('settled', $this->reason(3518));
        $this->assertSame(0, (int) DB::table('StudentClass')->where('ID', 3516)->value('Paid'));
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--verify' => true]));
        $this->assertSame(1, DB::table('session_corrections')->where('decision_reference', 'repair-unpaid-hidden-closures')->count());

        // Rollback skips a row a director already reconciled.
        DB::table('StudentClass')->where('ID', 3517)->update(['Paid' => 1, 'closed_reason' => 'settled']);
        $this->assertSame(0, Artisan::call('repair:unpaid-hidden-closures', ['--rollback' => true, '--execute' => true]));
        $this->assertSame('settled', $this->reason(3516));
        $this->assertSame(1, (int) DB::table('StudentClass')->where('ID', 3517)->value('Paid'));
    }
}

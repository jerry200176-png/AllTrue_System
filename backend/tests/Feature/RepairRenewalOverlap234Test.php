<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairRenewalOverlap234Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert(['id' => 900234, 'name' => 'Case234 Fixture', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        foreach ([[1050, 8, 0, 8800], [3226, 1, 7, 10400]] as [$id, $used, $remaining, $charge]) {
            DB::table('StudentClass')->insert(['ID' => $id, 'StudentID' => 900234, 'GradeID' => 1, 'SubjectID' => 53, 'TeacherID' => 53, 'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'StartDate' => '2026-07-01 00:00:00', 'EndDate' => '2026-09-30 00:00:00', 'Charge' => $charge, 'Pay' => 0, 'Paid' => 0, 'Rate' => $id === 1050 ? 1100 : 1300, 'SessionDuration' => 120, 'ScheduleMode' => 'count', 'SessionCount' => 8, 'UsedSessions' => $used, 'RemainingSessions' => $remaining, 'Stop' => 0]);
        }
        foreach ([[30430, 1050], [30421, 3226]] as [$id, $class]) {
            DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => $class, 'SessionDate' => '2026-08-08', 'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        // The old contract already has seven legitimate completed sessions;
        // 30430 is its eighth, duplicated renewal-overlap attendance.
        foreach (range(1, 7) as $id) {
            DB::table('ClassSession')->insert(['id' => 30300 + $id, 'StudentClassID' => 1050, 'SessionDate' => '2026-07-' . str_pad((string) $id, 2, '0', STR_PAD_LEFT), 'StartTime' => '15:00:00', 'EndTime' => '17:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (array_merge([[1050, 30430], [3226, 30421]], array_map(fn (int $id) => [1050, 30300 + $id], range(1, 7))) as [$class, $session]) {
            DB::table('session_deduction_ledger')->insert(['student_class_id' => $class, 'class_session_id' => $session, 'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_dry_run_is_fail_closed_and_does_not_mutate(): void
    {
        $this->assertSame(0, Artisan::call('repair:renewal-overlap-234'));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 30430)->value('Status'));
        $this->assertSame(8800, (int) DB::table('StudentClass')->where('ID', 1050)->value('Charge'));
    }

    public function test_invoice_presence_blocks_repair(): void
    {
        DB::table('Invoice')->insert(['StudentID' => 900234, 'StudentClassID' => 1050, 'IssueDate' => '2026-08-01', 'TotalAmount' => 8800, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, Artisan::call('repair:renewal-overlap-234'));
        $this->assertStringContainsString('FINANCIAL_GATE_BLOCKED', Artisan::output());
    }

    public function test_invoice_item_presence_blocks_repair(): void
    {
        DB::table('InvoiceItem')->insert(['InvoiceID' => 999234, 'StudentClassID' => 1050, 'Description' => 'contract evidence', 'Amount' => 8800, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(1, Artisan::call('repair:renewal-overlap-234'));
        $this->assertStringContainsString('FINANCIAL_GATE_BLOCKED', Artisan::output());
    }

    public function test_execute_appends_reversal_and_recomputes_unpaid_contract(): void
    {
        $snapshot = storage_path('app/repair-snapshots/test-234.json');
        @unlink($snapshot);
        $this->assertSame(0, Artisan::call('repair:renewal-overlap-234', ['--execute' => true, '--snapshot' => $snapshot]));
        $this->assertFileExists($snapshot);
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', 30430)->value('Status'));
        $this->assertSame(7, (int) DB::table('StudentClass')->where('ID', 1050)->value('UsedSessions'));
        $this->assertSame(1, (int) DB::table('StudentClass')->where('ID', 1050)->value('RemainingSessions'));
        $this->assertSame(7700, (int) DB::table('StudentClass')->where('ID', 1050)->value('Charge'));
        $this->assertSame(1, DB::table('session_deduction_ledger')->where('student_class_id', 1050)->where('class_session_id', 30430)->where('event_type', 'reverse')->count());
        $this->assertSame('retro_leave', DB::table('session_deduction_ledger')->where('student_class_id', 1050)->where('class_session_id', 30430)->where('event_type', 'reverse')->value('source'));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 30421)->value('Status'));
    }

    public function test_rollback_requires_execute_and_restores_counter_projection(): void
    {
        $this->assertSame(0, Artisan::call('repair:renewal-overlap-234', ['--execute' => true]));
        $this->assertSame(0, Artisan::call('repair:renewal-overlap-234', ['--rollback' => true]));
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', 30430)->value('Status'));
        $this->assertSame(0, Artisan::call('repair:renewal-overlap-234', ['--rollback' => true, '--execute' => true]));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', 30430)->value('Status'));
        $this->assertSame(8, (int) DB::table('StudentClass')->where('ID', 1050)->value('UsedSessions'));
        $this->assertSame(0, (int) DB::table('StudentClass')->where('ID', 1050)->value('RemainingSessions'));
        $this->assertSame('retro_leave', DB::table('session_deduction_ledger')->where('student_class_id', 1050)->where('class_session_id', 30430)->where('event_type', 'deduct')->latest('id')->value('source'));
    }
}

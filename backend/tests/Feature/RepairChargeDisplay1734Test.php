<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepairChargeDisplay1734Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('Student')->insert([
            'id' => 72, 'name' => '陳姝彣', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'ID' => 1052, 'StudentID' => 72, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0,
            'StartDate' => '2026-05-04 00:00:00', 'EndDate' => '2026-06-01 00:00:00',
            'Charge' => 24750, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1650, 'rate_unit' => 'session',
            'SessionDuration' => 120, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'UsedSessions' => 0, 'RemainingSessions' => 8, 'Stop' => 0,
        ]);
        DB::table('Invoice')->insert([
            'id' => 539, 'StudentID' => 72, 'StudentClassID' => 1052,
            'IssueDate' => '2026-05-13', 'TotalAmount' => 13200, 'PaidAmount' => 13200,
            'Status' => 'paid', 'Note' => '', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('Payment')->insert([
            'id' => 514, 'InvoiceID' => 539, 'Amount' => 13200, 'PaidAt' => '2026-05-13 00:00:00',
            'Method' => 'cash', 'created_at' => now(),
        ]);
    }

    public function test_dry_run_does_not_mutate_charge_or_invoice(): void
    {
        $this->assertSame(0, Artisan::call('repair:charge-display-1734'));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame(24750, (int) DB::table('StudentClass')->where('ID', 1052)->value('Charge'));
        $this->assertSame(13200, (int) DB::table('Invoice')->where('id', 539)->value('TotalAmount'));
        $this->assertSame(13200, (int) DB::table('Payment')->where('id', 514)->value('Amount'));
    }

    public function test_execute_aligns_charge_and_leaves_settlement_alone(): void
    {
        $snap = storage_path('app/repair-snapshots/1734-test.json');
        $this->assertSame(0, Artisan::call('repair:charge-display-1734', ['--execute' => true, '--snapshot' => $snap]));
        $this->assertSame(13200, (int) DB::table('StudentClass')->where('ID', 1052)->value('Charge'));
        $this->assertSame(13200, (int) DB::table('Invoice')->where('id', 539)->value('TotalAmount'));
        $this->assertSame(13200, (int) DB::table('Payment')->where('id', 514)->value('Amount'));
        $this->assertSame(0, Artisan::call('repair:charge-display-1734', ['--execute' => true]));
        $this->assertStringContainsString('already_applied', Artisan::output());
    }

    public function test_rollback_restores_stale_charge(): void
    {
        $this->assertSame(0, Artisan::call('repair:charge-display-1734', ['--execute' => true]));
        $this->assertSame(0, Artisan::call('repair:charge-display-1734', ['--rollback' => true, '--execute' => true]));
        $this->assertSame(24750, (int) DB::table('StudentClass')->where('ID', 1052)->value('Charge'));
        $this->assertSame(13200, (int) DB::table('Invoice')->where('id', 539)->value('TotalAmount'));
    }

    public function test_refuses_when_invoice_amount_drifted(): void
    {
        DB::table('Invoice')->where('id', 539)->update(['TotalAmount' => 24750]);
        $this->assertSame(1, Artisan::call('repair:charge-display-1734', ['--execute' => true]));
        $this->assertSame(24750, (int) DB::table('StudentClass')->where('ID', 1052)->value('Charge'));
    }
}

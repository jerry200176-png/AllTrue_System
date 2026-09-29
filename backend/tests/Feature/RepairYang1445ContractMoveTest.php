<?php

namespace Tests\Feature;

use App\Models\SessionCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** One-case repair student 1452: move attended 36285 from renewal 3777 to closed 1445. */
class RepairYang1445ContractMoveTest extends TestCase
{
    use RefreshDatabase;

    private const CMD = 'repair:yang-1445-contract-move';
    private const SNAP = '{"kind":"contract_amended","before":{"session_count":8},"after":{"session_count":7},"actor_user_id":4}';

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('env');
        Artisan::output();
        DB::table('Student')->insert(['id' => 1452, 'name' => 'Yang fixture', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1]);
        $this->sc(1445, ['SessionCount' => 7, 'UsedSessions' => 7, 'RemainingSessions' => 0, 'Stop' => 1, 'Charge' => 7200,
            'closed_reason' => 'contract_amended', 'StartDate' => '2026-07-04 00:00:00', 'EndDate' => '2026-09-29 00:00:00',
            'settlement_snapshot' => self::SNAP]);
        $this->sc(3777, ['SessionCount' => 8, 'UsedSessions' => 1, 'RemainingSessions' => 7, 'Stop' => 0, 'Charge' => 8800,
            'StartDate' => '2026-09-19 00:00:00', 'EndDate' => '2026-11-14 00:00:00']);
        $this->cs(36285, 3777, '2026-09-19', 'attended', '');
        $this->cs(42097, 1445, '2026-09-19', 'scheduled', 'auto-extended-after-leave:ld=2026-07-11:ls=11747');
        $this->cs(36292, 3777, '2026-11-07', 'scheduled', '');
        DB::table('StudentSingIn')->insert(['id' => 13108, 'StudentClassID' => 3777, 'ClassSessionID' => 36285, 'StudentID' => 1452,
            'TeacherID' => 146, 'Status' => 'present', 'SessionDeducted' => 1, 'SignInDT' => '2026-09-19 13:00:00']);
        DB::table('LearningRecord')->insert(['id' => 20203, 'StudentClassID' => 3777, 'ClassSessionID' => 36285, 'TeacherID' => 146,
            'Content' => 'x', 'Status' => 'approved', 'created_at' => now(), 'updated_at' => now()]
            + (Schema::hasColumn('LearningRecord', 'Subject') ? ['Subject' => 'Math'] : [])
            + (Schema::hasColumn('LearningRecord', 'SessionDate') ? ['SessionDate' => '2026-09-19'] : [])
            + (Schema::hasColumn('LearningRecord', 'StartTime') ? ['StartTime' => '13:00:00'] : [])
            + (Schema::hasColumn('LearningRecord', 'EndTime') ? ['EndTime' => '15:00:00'] : []));
        DB::table('session_deduction_ledger')->insert(['id' => 15877, 'student_class_id' => 3777, 'class_session_id' => 36285,
            'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $before = $this->state();
        $this->assertSame(0, Artisan::call(self::CMD));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertEquals($before, $this->state());
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    public function test_apply_verify_idempotent_and_rollback(): void
    {
        $before = $this->state();
        $this->assertSame(0, Artisan::call(self::CMD, ['--execute' => true, '--actor' => 'phpunit']));
        $this->assertStringContainsString('REPAIR_STATE=APPLIED_AND_VERIFIED', Artisan::output());

        $old = DB::table('StudentClass')->where('ID', 1445)->first();
        $this->assertSame([8, 8, 0, 1, 'settled', '2026-09-19'],
            [(int) $old->SessionCount, (int) $old->UsedSessions, (int) $old->RemainingSessions, (int) $old->Stop, $old->closed_reason, substr($old->EndDate, 0, 10)]);
        $this->assertStringContainsString('"kind":"contract_amended"', $old->settlement_snapshot);
        $this->assertStringContainsString('repair-yang-1445', $old->settlement_snapshot);
        $new = DB::table('StudentClass')->where('ID', 3777)->first();
        $this->assertSame([8, 0, 8, '2026-11-21'], [(int) $new->SessionCount, (int) $new->UsedSessions, (int) $new->RemainingSessions, substr($new->EndDate, 0, 10)]);
        $this->assertSame([7200, 8800], [(int) $old->Charge, (int) $new->Charge]);
        $this->assertSame(1445, (int) DB::table('ClassSession')->where('id', 36285)->value('StudentClassID'));
        $this->assertSame(1445, (int) DB::table('StudentSingIn')->where('id', 13108)->value('StudentClassID'));
        $this->assertSame(1445, (int) DB::table('LearningRecord')->where('id', 20203)->value('StudentClassID'));
        $this->assertSame(1445, (int) DB::table('session_deduction_ledger')->where('id', 15877)->value('student_class_id'));
        $ghost = DB::table('ClassSession')->where('id', 42097)->first();
        $this->assertSame('cancelled', $ghost->Status);
        $this->assertStringEndsWith('; superseded-by-36285 repair', $ghost->Note);
        $tail = DB::table('ClassSession')->where('StudentClassID', 3777)->where('SessionDate', '2026-11-21')->first();
        $this->assertSame(['scheduled', '13:00', '15:00'], [$tail->Status, substr($tail->StartTime, 0, 5), substr($tail->EndTime, 0, 5)]);
        $this->assertSame(1, DB::table('class_session_reassignments')->where('class_session_id', 36285)->count());
        $this->assertSame(1, SessionCorrection::query()->where('decision_reference', 'repair-yang-1445')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'repair.yang_1445_contract_move')->count());

        // verify-only and idempotency
        $this->assertSame(0, Artisan::call(self::CMD, ['--verify' => true]));
        $this->assertSame(0, Artisan::call(self::CMD, ['--execute' => true]));
        $this->assertStringContainsString('ALREADY APPLIED', Artisan::output());
        $this->assertSame(1, SessionCorrection::query()->count());
        $this->assertSame(1, DB::table('ClassSession')->where('SessionDate', '2026-11-21')->count());

        // rollback dry-run is a no-op, execute restores every old row
        $applied = $this->state();
        $this->assertSame(0, Artisan::call(self::CMD, ['--rollback' => true]));
        $this->assertEquals($applied, $this->state());
        $this->assertSame(0, Artisan::call(self::CMD, ['--rollback' => true, '--execute' => true]));
        $this->assertEquals($before, $this->state());
        $this->assertSame(2, DB::table('class_session_reassignments')->where('class_session_id', 36285)->count());
        $this->assertNotNull(SessionCorrection::query()->value('rolled_back_at'));
    }

    public function test_drift_aborts_without_writes(): void
    {
        DB::table('StudentClass')->where('ID', 3777)->update(['UsedSessions' => 2]);
        $before = $this->state();
        $this->assertSame(1, Artisan::call(self::CMD, ['--execute' => true]));
        $this->assertStringContainsString('DRIFT: new.UsedSessions', Artisan::output());
        $this->assertEquals($before, $this->state());
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    public function test_occupied_tail_slot_aborts(): void
    {
        $this->cs(50001, 3777, '2026-11-21', 'scheduled', '');
        $before = $this->state();
        $this->assertSame(1, Artisan::call(self::CMD, ['--execute' => true]));
        $this->assertStringContainsString('tail_slot_clash_count', Artisan::output());
        $this->assertEquals($before, $this->state());
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $strip = fn ($rows) => $rows->map(fn ($r) => (array) $r)->all();

        return [
            'sc' => $strip(DB::table('StudentClass')->orderBy('ID')->get()),
            'cs' => $strip(DB::table('ClassSession')->orderBy('id')->get()),
            'si' => $strip(DB::table('StudentSingIn')->get()),
            'lr' => $strip(DB::table('LearningRecord')->get()),
            'ld' => $strip(DB::table('session_deduction_ledger')->get()),
        ];
    }

    private function sc(int $id, array $extra): void
    {
        $row = array_merge(['ID' => $id, 'StudentID' => 1452, 'GradeID' => 1, 'SubjectID' => 65, 'TeacherID' => 146,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 1500,
            'SessionDuration' => 120, 'ScheduleMode' => 'count'], $extra);
        if (Schema::hasColumn('StudentClass', 'RoomID')) {
            $row['RoomID'] = '0';
        }
        if (Schema::hasColumn('StudentClass', 'ClassType')) {
            $row['ClassType'] = 'one_on_one';
        }
        DB::table('StudentClass')->insert($row);
    }

    private function cs(int $id, int $scId, string $date, string $status, string $note): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => $scId, 'SessionDate' => $date, 'StartTime' => '13:00:00',
            'EndTime' => '15:00:00', 'Status' => $status, 'Note' => $note, 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
    }
}

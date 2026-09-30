<?php

namespace Tests\Feature;

use App\Models\SessionCorrection;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** One-case repair student 2175: restore 3499 to 英文/Ruth, then paid 轉課 9/30+ to 數學/李維. */
class RepairYuShengrui3499TransferTest extends TestCase
{
    use RefreshDatabase;

    private const CMD = 'repair:yu-shengrui-3499-transfer';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
        DB::table('Student')->insert(['id' => 2175, 'name' => 'Yu fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        foreach ([29, 146] as $id) {
            User::forceCreate(['id' => $id, 'LoginName' => "t{$id}@test.com", 'Name' => "T{$id}", 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922222222']);
            UserCampus::create(['CampusID' => 1, 'UserID' => $id, 'Admin' => 0, 'Approved' => 1]);
        }
        DB::table('StudentClass')->insert(['ID' => 3499, 'StudentID' => 2175, 'GradeID' => 1, 'SubjectID' => 66, 'TeacherID' => 29,
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-02', 'TotalHours' => 16, 'Charge' => 12000, 'Pay' => 12000, 'Disconunt' => 0,
            'Paid' => 1, 'PayDate' => '2026-09-09', 'Rate' => 1500, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 4, 'UsedSessions' => 4,
            'ClassType' => 'one_on_two', 'week' => 3, 'time' => '15:00:00'] + (Schema::hasColumn('StudentClass', 'RoomID') ? ['RoomID' => '0'] : []));
        foreach ([33693 => ['2026-09-02', 'attended'], 33694 => ['2026-09-09', 'attended'], 33695 => ['2026-09-16', 'attended'],
            33696 => ['2026-09-23', 'late']] as $id => [$d, $st]) {
            $this->cs($id, $d, '13:00:00', '15:00:00', $st);
        }
        $this->cs(33697, '2026-09-30', '13:00:00', '15:00:00', 'cancelled');
        foreach ([42098 => '2026-09-30', 33698 => '2026-10-07', 33699 => '2026-10-14', 33700 => '2026-10-21'] as $id => $d) {
            $this->cs($id, $d, '15:00:00', '17:00:00', 'scheduled');
        }
        foreach ([12347 => ['2026-09-02', 146], 12349 => ['2026-09-09', 146], 12351 => ['2026-09-16', 146], 12353 => ['2026-09-23', 146],
            12355 => ['2026-09-30', 29]] as $id => [$d, $t]) {
            DB::table('schedules')->insert(['id' => $id, 'student_id' => 2175, 'teacher_id' => $t, 'subject' => '英文', 'day_of_week' => 3,
                'start_time' => '15:00', 'end_time' => '17:00', 'branch_id' => 1, 'schedule_date' => $d, 'student_course_id' => 3499]);
        }
        DB::table('Invoice')->insert(['id' => 1777, 'StudentID' => 2175, 'StudentClassID' => 3499, 'IssueDate' => '2026-09-09',
            'TotalAmount' => 12000, 'PaidAmount' => 12000, 'Status' => 'paid', 'Note' => '']);
        DB::table('InvoiceItem')->insert(['InvoiceID' => 1777, 'StudentClassID' => 3499, 'Description' => '8 堂', 'Amount' => 12000]);
        DB::table('Payment')->insert(['id' => 1750, 'InvoiceID' => 1777, 'Amount' => 12000, 'PaidAt' => '2026-09-09', 'Method' => 'cash', 'Note' => '']);
        DB::table('payment_reports')->insert(['id' => 1741, 'StudentID' => 2175, 'StudentClassID' => 3499, 'reported_amount' => 12000,
            'status' => 'confirmed', 'reported_by_name' => 'T', 'report_token_hash' => hash('sha256', 'x'),
            'token_expires_at' => now()->addDay(), 'payment_date' => '2026-09-09', 'payment_method' => 'cash']);
        DB::table('LearningRecord')->insert(['StudentClassID' => 3499, 'ClassSessionID' => 42098, 'TeacherID' => 29, 'CreatedByUserID' => 29,
            'Content' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $before = $this->state();
        $code = Artisan::call(self::CMD);
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('DRY RUN', $out);
        $this->assertStringContainsString('AFTER (simulated', $out);
        $this->assertEquals($before, $this->state());
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    public function test_execute_verify_idempotent_and_rollback(): void
    {
        $before = $this->state();
        $code = Artisan::call(self::CMD, ['--execute' => true, '--actor' => 'phpunit']);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertStringContainsString('REPAIR_STATE=APPLIED_AND_VERIFIED', Artisan::output());

        $sc = DB::table('StudentClass')->where('ID', 3499)->first();
        $this->assertSame([65, 146, 3, '13:00', 4, 6000], [(int) $sc->SubjectID, (int) $sc->TeacherID, (int) $sc->week, substr($sc->time, 0, 5), (int) $sc->SessionCount, (int) $sc->Charge]);
        $new = DB::table('StudentClass')->where('ID', '!=', 3499)->first();
        $this->assertSame([66, 29, 3, '15:00', 4, 6000, 1], [(int) $new->SubjectID, (int) $new->TeacherID, (int) $new->week, substr($new->time, 0, 5), (int) $new->SessionCount, (int) $new->Charge, (int) $new->Paid]);
        $this->assertSame([42098, 33698, 33699, 33700], DB::table('ClassSession')->where('StudentClassID', $new->ID)->orderByRaw('SessionDate')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame((int) $new->ID, (int) DB::table('LearningRecord')->where('ClassSessionID', 42098)->value('StudentClassID'));
        $this->assertSame([6000, 6000], [(int) DB::table('Invoice')->where('id', 1777)->value('TotalAmount'), (int) DB::table('Invoice')->where('id', 1777)->value('PaidAmount')]);
        $this->assertSame(-6000, (int) DB::table('Payment')->where('InvoiceID', 1777)->where('Method', 'transfer_out')->value('Amount'));
        $this->assertSame(6000, (int) DB::table('Payment')->where('Method', 'transfer_in')->value('Amount'));
        $this->assertSame([12000, 'cash'], [(int) DB::table('Payment')->where('id', 1750)->value('Amount'), DB::table('Payment')->where('id', 1750)->value('Method')]);
        $this->assertSame(2, DB::table('course_contract_group_members')->count());
        $this->assertSame(1, SessionCorrection::query()->where('decision_reference', 'repair-yu-shengrui-3499')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'repair.yu_shengrui_3499_transfer')->count());

        $this->assertSame(0, Artisan::call(self::CMD, ['--verify' => true]));
        Artisan::call(self::CMD, ['--execute' => true]);
        $this->assertStringContainsString('ALREADY APPLIED', Artisan::output());
        $this->assertSame(2, DB::table('StudentClass')->count());

        $applied = $this->state();
        $this->assertSame(0, Artisan::call(self::CMD, ['--rollback' => true]));
        $this->assertEquals($applied, $this->state());
        $this->assertSame(0, Artisan::call(self::CMD, ['--rollback' => true, '--execute' => true]));
        $this->assertEquals($before, $this->state());
        $this->assertNotNull(SessionCorrection::query()->value('rolled_back_at'));
        $this->assertSame(0, DB::table('course_contract_groups')->count());
    }

    public function test_state_mismatch_aborts_without_writes(): void
    {
        DB::table('StudentClass')->where('ID', 3499)->update(['Charge' => 11000]);
        $before = $this->state();
        $this->assertSame(1, Artisan::call(self::CMD, ['--execute' => true]));
        $this->assertStringContainsString('DRIFT: sc.Charge', Artisan::output());
        $this->assertEquals($before, $this->state());
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $out = [];
        foreach (['StudentClass' => 'ID', 'ClassSession' => 'id', 'StudentSingIn' => 'id', 'LearningRecord' => 'id', 'schedules' => 'id',
            'Invoice' => 'id', 'InvoiceItem' => 'id', 'Payment' => 'id', 'session_deduction_ledger' => 'id'] as $t => $pk) {
            $out[$t] = DB::table($t)->orderBy($pk)->get()->map(fn ($r) => (array) $r)->all();
        }

        return $out;
    }

    private function cs(int $id, string $date, string $start, string $end, string $status): void
    {
        DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => 3499, 'SessionDate' => $date, 'StartTime' => $start,
            'EndTime' => $end, 'Status' => $status, 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-09-01 10:00:00']);
    }
}

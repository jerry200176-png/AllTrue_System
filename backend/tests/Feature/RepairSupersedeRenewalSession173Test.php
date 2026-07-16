<?php

namespace Tests\Feature;

use App\Models\SessionCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** in-app #173 B: supersede old overlap; no LR void; counters/Invoice unchanged. */
class RepairSupersedeRenewalSession173Test extends TestCase
{
    use RefreshDatabase;

    private const SID = 11292;
    private const KID = 16951;
    private const OLD = 114;
    private const NEW = 2076;
    private const SLOT = ['SessionDate' => '2026-06-10', 'StartTime' => '19:00:00', 'EndTime' => '21:00:00'];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('env');
        Artisan::output();
        $this->seedFixture();
    }

    public function test_dry_run_does_not_write(): void
    {
        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', ['--case' => '173']));
        $out = Artisan::output();
        $this->assertStringContainsString('=== DRY RUN repair:supersede-renewal-session ===', $out);
        $this->assertStringContainsString('WOULD supersede ClassSession id=11292', $out);
        $this->assertStringContainsString('WILL NOT CHANGE', $out);
        $this->assertSame('attended', DB::table('ClassSession')->where('id', self::SID)->value('Status'));
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    public function test_execute_writes_correction_without_mutating_lr_or_counters(): void
    {
        $snap = storage_path('app/repair-snapshots/test-173.json');
        @unlink($snap);
        $oldU = (int) DB::table('StudentClass')->where('ID', self::OLD)->value('UsedSessions');
        $oldR = (int) DB::table('StudentClass')->where('ID', self::OLD)->value('RemainingSessions');
        $newU = (int) DB::table('StudentClass')->where('ID', self::NEW)->value('UsedSessions');
        $newR = (int) DB::table('StudentClass')->where('ID', self::NEW)->value('RemainingSessions');
        $paid = (int) DB::table('Invoice')->where('id', 137)->value('PaidAmount');

        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173', '--execute' => true, '--snapshot' => $snap,
            '--actor' => 'phpunit:173', '--actor-user-id' => 42,
        ]));
        $this->assertFileExists($snap);
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', self::SID)->value('Status'));
        $this->assertSame('completed', DB::table('ClassSession')->where('id', self::KID)->value('Status'));

        $c = SessionCorrection::query()->where('session_id', self::SID)->first();
        $this->assertNotNull($c);
        $this->assertSame(self::KID, (int) $c->replaced_by_session_id);
        $this->assertSame('duplicate_after_renewal', $c->correction_reason);
        $this->assertSame('in-app #173', $c->decision_reference);
        $this->assertSame('attended', $c->previous_status);
        $this->assertSame(8883, (int) $c->preserved_learning_record_id);
        $this->assertSame(9959, (int) $c->keeper_learning_record_id);
        $this->assertSame(42, (int) $c->decided_by_user_id);
        $this->assertSame('phpunit:173', $c->decided_by_actor);
        $this->assertNull($c->rolled_back_at);

        $this->assertSame($oldU, (int) DB::table('StudentClass')->where('ID', self::OLD)->value('UsedSessions'));
        $this->assertSame($oldR, (int) DB::table('StudentClass')->where('ID', self::OLD)->value('RemainingSessions'));
        $this->assertSame($newU, (int) DB::table('StudentClass')->where('ID', self::NEW)->value('UsedSessions'));
        $this->assertSame($newR, (int) DB::table('StudentClass')->where('ID', self::NEW)->value('RemainingSessions'));
        $this->assertSame($paid, (int) DB::table('Invoice')->where('id', 137)->value('PaidAmount'));
        $this->assertNull(DB::table('LearningRecord')->where('id', 8883)->value('VoidedAt'));
        $this->assertSame(self::SID, (int) DB::table('LearningRecord')->where('id', 8883)->value('ClassSessionID'));

        // idempotent
        Artisan::call('repair:supersede-renewal-session', ['--case' => '173', '--execute' => true]);
        $this->assertStringContainsString('Already applied', Artisan::output());
        $this->assertSame(1, SessionCorrection::query()->count());

        // leaves cross-SC attended/completed pair
        $live = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', 900173)
            ->where('cs.SessionDate', self::SLOT['SessionDate'])
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) = ?', ['19:00'])
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->count();
        $this->assertSame(1, $live);

        // rollback
        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173', '--rollback' => true, '--execute' => true,
        ]));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', self::SID)->value('Status'));
        $this->assertNotNull(SessionCorrection::query()->where('session_id', self::SID)->value('rolled_back_at'));
    }

    private function seedFixture(): void
    {
        DB::table('Student')->insert([
            'id' => 900173, 'name' => 'Case173 Fixture', 'CampusID' => 9, 'ClassID' => 1, 'enable' => 1,
        ]);
        $this->sc(self::OLD, ['UsedSessions' => 8, 'RemainingSessions' => 0, 'SessionCount' => 8, 'Stop' => 1,
            'StartDate' => '2026-04-08 00:00:00', 'EndDate' => '2026-06-02 00:00:00']);
        $this->sc(self::NEW, ['UsedSessions' => 7, 'RemainingSessions' => 1, 'SessionCount' => 8, 'Stop' => 0,
            'StartDate' => '2026-06-03 00:00:00', 'EndDate' => '2026-08-12 00:00:00']);
        $this->cs(self::SID, self::OLD, 'attended');
        $this->cs(self::KID, self::NEW, 'completed');
        $this->lr(8883, self::OLD, self::SID);
        $this->lr(9959, self::NEW, self::KID);
        DB::table('Invoice')->insert([
            ['id' => 137, 'StudentID' => 900173, 'StudentClassID' => self::OLD, 'IssueDate' => '2026-04-08',
                'TotalAmount' => 12000, 'PaidAmount' => 12000, 'Status' => 'paid', 'Note' => '',
                'created_at' => now(), 'updated_at' => now()],
            ['id' => 936, 'StudentID' => 900173, 'StudentClassID' => self::NEW, 'IssueDate' => '2026-06-24',
                'TotalAmount' => 12000, 'PaidAmount' => 12000, 'Status' => 'paid', 'Note' => '',
                'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function sc(int $id, array $extra): void
    {
        $row = array_merge([
            'ID' => $id, 'StudentID' => 900173, 'GradeID' => 1, 'SubjectID' => 69, 'TeacherID' => 67,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 12000, 'Pay' => 12000, 'Paid' => 1,
            'Rate' => 1500, 'SessionDuration' => 120, 'ScheduleMode' => 'count',
        ], $extra);
        if (Schema::hasColumn('StudentClass', 'RoomID')) {
            $row['RoomID'] = '0';
        }
        if (Schema::hasColumn('StudentClass', 'ClassType')) {
            $row['ClassType'] = 'one_on_one';
        }
        DB::table('StudentClass')->insert($row);
    }

    private function cs(int $id, int $scId, string $status): void
    {
        DB::table('ClassSession')->insert([
            'id' => $id, 'StudentClassID' => $scId,
            'SessionDate' => self::SLOT['SessionDate'], 'StartTime' => self::SLOT['StartTime'],
            'EndTime' => self::SLOT['EndTime'], 'Status' => $status, 'Note' => '',
            'created_at' => '2026-06-01 10:00:00', 'updated_at' => '2026-06-10 21:00:00',
        ]);
    }

    private function lr(int $id, int $scId, int $csId): void
    {
        $row = [
            'id' => $id, 'StudentClassID' => $scId, 'ClassSessionID' => $csId, 'TeacherID' => 67,
            'Content' => 'fixture ' . $id, 'Status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ];
        foreach (['Subject' => 'Math', 'SessionDate' => self::SLOT['SessionDate'],
            'StartTime' => self::SLOT['StartTime'], 'EndTime' => self::SLOT['EndTime'], 'VoidedAt' => null] as $col => $val) {
            if (Schema::hasColumn('LearningRecord', $col)) {
                $row[$col] = $val;
            }
        }
        DB::table('LearningRecord')->insert($row);
    }
}

<?php

namespace Tests\Feature;

use App\Models\SessionCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * in-app #173 option B: supersede old-contract overlap after renewal.
 * Guards: no LR void/move, no Used/Remaining/Invoice mutation, auditable session_corrections.
 */
class RepairSupersedeRenewalSession173Test extends TestCase
{
    use RefreshDatabase;

    private const SUPERSEDE_CS = 11292;
    private const KEEP_CS = 16951;
    private const OLD_SC = 114;
    private const NEW_SC = 2076;
    private const SLOT = [
        'SessionDate' => '2026-06-10',
        'StartTime' => '19:00:00',
        'EndTime' => '21:00:00',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('env');
        Artisan::output();
        $this->seedCase173Fixture();
    }

    public function test_dry_run_lists_change_and_does_not_write(): void
    {
        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', ['--case' => '173']));
        $out = Artisan::output();
        $this->assertStringContainsString('=== DRY RUN repair:supersede-renewal-session ===', $out);
        $this->assertStringContainsString('WOULD supersede ClassSession id=11292', $out);
        $this->assertStringContainsString('WILL NOT CHANGE', $out);
        $this->assertSame('attended', DB::table('ClassSession')->where('id', self::SUPERSEDE_CS)->value('Status'));
        $this->assertSame(0, SessionCorrection::query()->count());
    }

    public function test_execute_cancels_superseded_session_and_writes_correction_metadata(): void
    {
        $snapshot = storage_path('app/repair-snapshots/test-173-supersede.json');
        @unlink($snapshot);

        $oldUsed = (int) DB::table('StudentClass')->where('ID', self::OLD_SC)->value('UsedSessions');
        $oldRem = (int) DB::table('StudentClass')->where('ID', self::OLD_SC)->value('RemainingSessions');
        $newUsed = (int) DB::table('StudentClass')->where('ID', self::NEW_SC)->value('UsedSessions');
        $newRem = (int) DB::table('StudentClass')->where('ID', self::NEW_SC)->value('RemainingSessions');
        $invPaid = (int) DB::table('Invoice')->where('id', 137)->value('PaidAmount');
        $lrVoidBefore = DB::table('LearningRecord')->where('id', 8883)->value('VoidedAt');

        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--execute' => true,
            '--snapshot' => $snapshot,
            '--actor' => 'phpunit:173',
            '--actor-user-id' => 42,
        ]));
        $this->assertFileExists($snapshot);

        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', self::SUPERSEDE_CS)->value('Status'));
        $this->assertSame('completed', DB::table('ClassSession')->where('id', self::KEEP_CS)->value('Status'));

        $correction = SessionCorrection::query()->where('session_id', self::SUPERSEDE_CS)->first();
        $this->assertNotNull($correction);
        $this->assertSame(self::KEEP_CS, (int) $correction->replaced_by_session_id);
        $this->assertSame('duplicate_after_renewal', $correction->correction_reason);
        $this->assertSame('in-app #173', $correction->decision_reference);
        $this->assertSame('attended', $correction->previous_status);
        $this->assertSame('cancelled', $correction->new_status);
        $this->assertSame(8883, (int) $correction->preserved_learning_record_id);
        $this->assertSame(9959, (int) $correction->keeper_learning_record_id);
        $this->assertSame(42, (int) $correction->decided_by_user_id);
        $this->assertSame('phpunit:173', $correction->decided_by_actor);
        $this->assertNotNull($correction->decided_at);
        $this->assertNull($correction->rolled_back_at);

        // Remaining / Used / Invoice / LR must not change
        $this->assertSame($oldUsed, (int) DB::table('StudentClass')->where('ID', self::OLD_SC)->value('UsedSessions'));
        $this->assertSame($oldRem, (int) DB::table('StudentClass')->where('ID', self::OLD_SC)->value('RemainingSessions'));
        $this->assertSame($newUsed, (int) DB::table('StudentClass')->where('ID', self::NEW_SC)->value('UsedSessions'));
        $this->assertSame($newRem, (int) DB::table('StudentClass')->where('ID', self::NEW_SC)->value('RemainingSessions'));
        $this->assertSame($invPaid, (int) DB::table('Invoice')->where('id', 137)->value('PaidAmount'));
        $this->assertSame($lrVoidBefore, DB::table('LearningRecord')->where('id', 8883)->value('VoidedAt'));
        $this->assertNull(DB::table('LearningRecord')->where('id', 8883)->value('VoidedAt'));
        $this->assertSame(self::SUPERSEDE_CS, (int) DB::table('LearningRecord')->where('id', 8883)->value('ClassSessionID'));
        $this->assertSame(self::KEEP_CS, (int) DB::table('LearningRecord')->where('id', 9959)->value('ClassSessionID'));
    }

    public function test_execute_is_idempotent_when_already_applied(): void
    {
        Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--execute' => true,
            '--actor' => 'phpunit:173-first',
        ]);
        $countAfterFirst = SessionCorrection::query()->count();

        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--execute' => true,
            '--actor' => 'phpunit:173-second',
        ]));
        $out = Artisan::output();
        $this->assertStringContainsString('Already applied', $out);
        $this->assertSame($countAfterFirst, SessionCorrection::query()->count());
    }

    public function test_rollback_restores_previous_status(): void
    {
        Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--execute' => true,
            '--actor' => 'phpunit:173',
        ]);

        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--rollback' => true,
        ]));
        $this->assertStringContainsString('DRY RUN ROLLBACK', Artisan::output());
        $this->assertSame('cancelled', DB::table('ClassSession')->where('id', self::SUPERSEDE_CS)->value('Status'));

        $this->assertSame(0, Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--rollback' => true,
            '--execute' => true,
        ]));
        $this->assertSame('attended', DB::table('ClassSession')->where('id', self::SUPERSEDE_CS)->value('Status'));
        $correction = SessionCorrection::query()->where('session_id', self::SUPERSEDE_CS)->first();
        $this->assertNotNull($correction->rolled_back_at);
    }

    public function test_cancelled_superseded_session_leaves_cross_sc_duplicate_group(): void
    {
        Artisan::call('repair:supersede-renewal-session', [
            '--case' => '173',
            '--execute' => true,
            '--actor' => 'phpunit:173',
        ]);

        $attendedPairs = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->where('sc.StudentID', 900173)
            ->where('cs.SessionDate', self::SLOT['SessionDate'])
            ->whereRaw('SUBSTRING(cs.StartTime,1,5) = ?', ['19:00'])
            ->whereRaw("LOWER(cs.Status) IN ('attended','completed')")
            ->count();

        $this->assertSame(1, $attendedPairs);
    }

    private function seedCase173Fixture(): void
    {
        DB::table('Student')->insert([
            'id' => 900173,
            'name' => 'Case173 Fixture',
            'CampusID' => 9,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        $this->insertStudentClass(self::OLD_SC, [
            'UsedSessions' => 8,
            'RemainingSessions' => 0,
            'SessionCount' => 8,
            'Stop' => 1,
            'StartDate' => '2026-04-08 00:00:00',
            'EndDate' => '2026-06-02 00:00:00',
        ]);
        $this->insertStudentClass(self::NEW_SC, [
            'UsedSessions' => 7,
            'RemainingSessions' => 1,
            'SessionCount' => 8,
            'Stop' => 0,
            'StartDate' => '2026-06-03 00:00:00',
            'EndDate' => '2026-08-12 00:00:00',
        ]);

        $this->insertSession(self::SUPERSEDE_CS, self::OLD_SC, 'attended');
        $this->insertSession(self::KEEP_CS, self::NEW_SC, 'completed');

        $this->insertLearningRecord(8883, self::OLD_SC, self::SUPERSEDE_CS);
        $this->insertLearningRecord(9959, self::NEW_SC, self::KEEP_CS);

        DB::table('Invoice')->insert([
            [
                'id' => 137,
                'StudentID' => 900173,
                'StudentClassID' => self::OLD_SC,
                'IssueDate' => '2026-04-08',
                'TotalAmount' => 12000,
                'PaidAmount' => 12000,
                'Status' => 'paid',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 936,
                'StudentID' => 900173,
                'StudentClassID' => self::NEW_SC,
                'IssueDate' => '2026-06-24',
                'TotalAmount' => 12000,
                'PaidAmount' => 12000,
                'Status' => 'paid',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        if (Schema::hasColumn('Invoice', 'reconciled_at')) {
            DB::table('Invoice')->whereIn('id', [137, 936])->update(['reconciled_at' => now()]);
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function insertStudentClass(int $id, array $extra): void
    {
        $row = array_merge([
            'ID' => $id,
            'StudentID' => 900173,
            'GradeID' => 1,
            'SubjectID' => 69,
            'TeacherID' => 67,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 12000,
            'Pay' => 12000,
            'Paid' => 1,
            'Rate' => 1500,
            'SessionDuration' => 120,
            'ScheduleMode' => 'count',
        ], $extra);

        if (Schema::hasColumn('StudentClass', 'RoomID')) {
            $row['RoomID'] = $row['RoomID'] ?? '0';
        }
        if (Schema::hasColumn('StudentClass', 'ClassType')) {
            $row['ClassType'] = $row['ClassType'] ?? 'one_on_one';
        }

        DB::table('StudentClass')->insert($row);
    }

    private function insertSession(int $id, int $scId, string $status): void
    {
        DB::table('ClassSession')->insert([
            'id' => $id,
            'StudentClassID' => $scId,
            'SessionDate' => self::SLOT['SessionDate'],
            'StartTime' => self::SLOT['StartTime'],
            'EndTime' => self::SLOT['EndTime'],
            'Status' => $status,
            'Note' => '',
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-10 21:00:00',
        ]);
    }

    private function insertLearningRecord(int $id, int $scId, int $csId): void
    {
        $row = [
            'id' => $id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csId,
            'TeacherID' => 67,
            'Content' => 'fixture lr ' . $id,
            'Status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if (Schema::hasColumn('LearningRecord', 'Subject')) {
            $row['Subject'] = 'Math';
        }
        if (Schema::hasColumn('LearningRecord', 'SessionDate')) {
            $row['SessionDate'] = self::SLOT['SessionDate'];
        }
        if (Schema::hasColumn('LearningRecord', 'StartTime')) {
            $row['StartTime'] = self::SLOT['StartTime'];
        }
        if (Schema::hasColumn('LearningRecord', 'EndTime')) {
            $row['EndTime'] = self::SLOT['EndTime'];
        }
        if (Schema::hasColumn('LearningRecord', 'VoidedAt')) {
            $row['VoidedAt'] = null;
        }
        DB::table('LearningRecord')->insert($row);
    }
}

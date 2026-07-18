<?php

namespace Tests\Feature;

use App\Models\SessionCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** in-app #173 FD1b C: merge eval fields into keeper LR; no counters/billing. */
class RepairMergeRenewalLearningRecord173Test extends TestCase
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
        $this->seedFixture();
        $this->applySupersedeB();
    }

    public function test_dry_run_fills_empty_keeper_from_source_treating_system_stub_empty(): void
    {
        $this->assertSame(0, Artisan::call('repair:merge-renewal-learning-record', ['--case' => '173']));
        $out = Artisan::output();
        $this->assertStringContainsString('=== DRY RUN', $out);
        $this->assertStringContainsString('fill_fields=', $out);
        $this->assertStringContainsString('Content', $out);
        $this->assertSame('（系統補登加課）', DB::table('LearningRecord')->where('id', 9959)->value('Content'));
        $this->assertSame(0, SessionCorrection::query()->where('decision_reference', 'in-app #173 LR-MERGE-C')->count());
    }

    public function test_conflict_when_both_have_distinct_user_content(): void
    {
        DB::table('LearningRecord')->where('id', 9959)->update([
            'Content' => '老師已填的另一套評量',
            'Comment' => 'keeper comment',
        ]);
        $this->assertSame(1, Artisan::call('repair:merge-renewal-learning-record', ['--case' => '173']));
        $this->assertStringContainsString('FIELD_CONFLICT', Artisan::output());
    }

    public function test_execute_merge_idempotent_and_rollback(): void
    {
        $snap = storage_path('app/repair-snapshots/test-173-lr-merge.json');
        @unlink($snap);
        $oldU = (int) DB::table('StudentClass')->where('ID', self::OLD)->value('UsedSessions');
        $newR = (int) DB::table('StudentClass')->where('ID', self::NEW)->value('RemainingSessions');
        $ded = (int) DB::table('LearningRecord')->where('id', 9959)->value('SessionDeducted');
        $paid = (int) DB::table('Invoice')->where('id', 936)->value('PaidAmount');

        $this->assertSame(0, Artisan::call('repair:merge-renewal-learning-record', [
            '--case' => '173', '--execute' => true, '--snapshot' => $snap,
            '--actor' => 'phpunit:173-lr', '--actor-user-id' => 42,
        ]));
        $kep = DB::table('LearningRecord')->where('id', 9959)->first();
        $this->assertSame('Ch6-3摩擦力', $kep->Content);
        $this->assertSame('good', $kep->Performance);
        $this->assertStringContainsString('光熙', (string) $kep->Comment);
        $this->assertSame($ded, (int) $kep->SessionDeducted);
        $this->assertSame(self::KID, (int) $kep->ClassSessionID);
        $this->assertNull(DB::table('LearningRecord')->where('id', 8883)->value('VoidedAt'));
        $this->assertSame(self::SID, (int) DB::table('LearningRecord')->where('id', 8883)->value('ClassSessionID'));

        $c = SessionCorrection::query()->where('decision_reference', 'in-app #173 LR-MERGE-C')->first();
        $this->assertNotNull($c);
        $this->assertSame(8883, (int) $c->preserved_learning_record_id);
        $this->assertSame(9959, (int) $c->keeper_learning_record_id);
        $this->assertContains('Content', $c->snapshot_before['restored_fields']);

        $this->assertSame($oldU, (int) DB::table('StudentClass')->where('ID', self::OLD)->value('UsedSessions'));
        $this->assertSame($newR, (int) DB::table('StudentClass')->where('ID', self::NEW)->value('RemainingSessions'));
        $this->assertSame($paid, (int) DB::table('Invoice')->where('id', 936)->value('PaidAmount'));

        Artisan::call('repair:merge-renewal-learning-record', ['--case' => '173', '--execute' => true]);
        $this->assertStringContainsString('ALREADY_APPLIED', Artisan::output());
        $this->assertSame(1, SessionCorrection::query()->where('decision_reference', 'in-app #173 LR-MERGE-C')->count());

        $this->assertSame(0, Artisan::call('repair:merge-renewal-learning-record', [
            '--case' => '173', '--rollback' => true, '--execute' => true,
        ]));
        $this->assertSame('（系統補登加課）', DB::table('LearningRecord')->where('id', 9959)->value('Content'));
        $this->assertNotNull(SessionCorrection::query()->where('decision_reference', 'in-app #173 LR-MERGE-C')->value('rolled_back_at'));
    }

    private function applySupersedeB(): void
    {
        DB::table('ClassSession')->where('id', self::SID)->update(['Status' => 'cancelled']);
        SessionCorrection::query()->create([
            'session_id' => self::SID,
            'replaced_by_session_id' => self::KID,
            'correction_reason' => 'duplicate_after_renewal',
            'decision_reference' => 'in-app #173',
            'decided_at' => now(),
            'decided_by_actor' => 'phpunit:seed-b',
            'previous_status' => 'attended',
            'new_status' => 'cancelled',
            'preserved_learning_record_id' => 8883,
            'keeper_learning_record_id' => 9959,
            'snapshot_before' => ['seed' => true],
        ]);
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
        $this->lr(8883, self::OLD, self::SID, [
            'Content' => 'Ch6-3摩擦力', 'HomeworkStatus' => 'incomplete', 'Progress' => 'Ch6-3摩擦力',
            'NextHomework' => '1.超級翰將p.219-p.213', 'Performance' => 'good',
            'Comment' => '光熙最近常常忘記寫作業', 'CreatedByUserID' => 67, 'SessionDeducted' => 0,
        ]);
        $this->lr(9959, self::NEW, self::KID, [
            'Content' => '（系統補登加課）', 'CreatedByUserID' => 56, 'SessionDeducted' => 1,
            'HomeworkStatus' => null, 'Progress' => null, 'NextHomework' => null,
            'Performance' => null, 'Comment' => null,
        ]);
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

    /** @param array<string,mixed> $extra */
    private function lr(int $id, int $scId, int $csId, array $extra = []): void
    {
        $row = array_merge([
            'id' => $id, 'StudentClassID' => $scId, 'ClassSessionID' => $csId, 'TeacherID' => 67,
            'Content' => 'fixture', 'Status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ], $extra);
        foreach (['Subject' => '理化', 'SessionDate' => self::SLOT['SessionDate'],
            'StartTime' => self::SLOT['StartTime'], 'EndTime' => self::SLOT['EndTime'], 'VoidedAt' => null] as $col => $val) {
            if (Schema::hasColumn('LearningRecord', $col) && !array_key_exists($col, $row)) {
                $row[$col] = $val;
            }
        }
        foreach (array_keys($row) as $col) {
            if ($col !== 'id' && !Schema::hasColumn('LearningRecord', $col)) {
                unset($row[$col]);
            }
        }
        DB::table('LearningRecord')->insert($row);
    }
}

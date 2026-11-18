<?php

namespace Tests\Feature\Ops;

use App\Operations\PopOperationCatalog;
use App\Operations\PopOperationService;
use App\Operations\Strategies\PastScheduledSessionsCancelStrategy as Cancel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Synthetic state only; exercise the supported executor, not just strategy methods. */
final class PastScheduledCancelNativeRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const OP = 'cancel-past-scheduled-course3380-20261118';
    private const OLD = 'cancel-past-scheduled-course2942-20261008';
    private const SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private string $manifest;
    private PopOperationService $pop;
    private array $lastApproval;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 18)->startOfDay());
        Cancel::useCasesForTesting([
            9201 => ['course_id' => 9200, 'date' => '2026-09-03', 'campus_id' => 1],
            9202 => ['course_id' => 9200, 'date' => '2026-09-10', 'campus_id' => 1],
        ]);
        DB::table('Student')->insert(['id' => 9200, 'name' => 'Synthetic Student', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        DB::table('StudentClass')->insert(['ID' => 9200, 'StudentID' => 9200, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'Pay' => 0, 'Rate' => 0, 'TotalHours' => 4, 'StartDate' => '2026-07-30',
            'EndDate' => '2026-09-21', 'ClassType' => 'one_on_one', 'ScheduleMode' => 'count', 'SessionCount' => 3,
            'Stop' => 1, 'Paid' => 0, 'Charge' => 14000, 'closed_reason' => 'waived']);
        foreach ([9201 => '2026-09-03', 9202 => '2026-09-10', 9203 => '2026-09-17'] as $id => $date) {
            DB::table('ClassSession')->insert(['id' => $id, 'StudentClassID' => 9200, 'SessionDate' => $date,
                'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->manifest = tempnam(sys_get_temp_dir(), 'synthetic-cancel-deployment-');
        file_put_contents($this->manifest, json_encode(['backend_sha' => self::SHA], JSON_THROW_ON_ERROR));
        $this->pop = new PopOperationService(new PopOperationCatalog(dirname(base_path()).'/operations/catalog.yaml'), null, $this->manifest);
    }

    protected function tearDown(): void
    {
        Cancel::useCasesForTesting(null);
        @unlink($this->manifest);
        $this->travelBack();
        parent::tearDown();
    }

    private function approved(string $op = self::OP): string
    {
        $p = ['decision_reference' => $op];
        $draft = $this->pop->createDraft($op, $p, 'synthetic:'.$op, 'user:9901', 'super_admin', [1], 9901);
        self::assertTrue($this->pop->runDryRun($draft['id'], 'user:9901', 9901, 'super_admin', [1])['ok']);
        self::assertTrue(($this->lastApproval = $this->renew($draft['id'], $op))['ready']);
        return $draft['id'];
    }

    private function renew(string $id, string $op = self::OP): array
    {
        $suffix = $op === self::OP ? '20261118' : '20261008';
        $rollback = $op === self::OP && DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->where('result', 'succeeded')->exists();
        return $this->pop->approve($id, 'founder-go-past-scheduled-cancel-'.($rollback ? 'rollback-' : '').$suffix, 'user:9901', 'super_admin', self::SHA, 9901, [1], 1);
    }

    private function statuses(): array
    {
        return DB::table('ClassSession')->where('StudentClassID', 9200)->orderBy('id')->pluck('Status')->all();
    }

    private function course(): array
    {
        return (array) DB::table('StudentClass')->where('ID', 9200)->first();
    }

    public function test_native_execute_verify_and_fresh_approved_rollback_preserve_the_original_contract(): void
    {
        $before = $this->course(); $id = $this->approved();
        $executeToken = $this->lastApproval['token'];
        self::assertSame('succeeded', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], $this->statuses());
        self::assertSame('idle', $this->pop->runApprovedLocally()['status']);
        $error = null;
        try { $this->pop->run($id, 'rollback', $executeToken, self::SHA, 'pop-pi-local'); }
        catch (\RuntimeException $e) { $error = $e; }
        self::assertInstanceOf(\RuntimeException::class, $error, 'execute-purpose approval must not authorize rollback');
        $renewed = $this->renew($id);
        self::assertTrue($renewed['ready']);
        self::assertSame('rollback', DB::table('pop_approval_events')->where('operation_id', $id)->latest('id')->value('phase'));
        self::assertSame('rolled_back', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['scheduled', 'scheduled', 'scheduled'], $this->statuses());
        self::assertSame($before, $this->course());
        self::assertSame('skipped', $this->pop->runApprovedLocally($id, 'rollback')['status']);
    }

    public function test_receipt_failure_cannot_retain_cancelled_rows_and_never_retries_automatically(): void
    {
        $id = $this->approved(); $before = $this->course();
        $fault = (object) ['armed' => true];
        DB::listen(function ($q) use ($fault): void {
            if ($fault->armed && str_starts_with($q->sql, 'insert ignore into `pop_execution_records`') && in_array('execute', $q->bindings, true)) {
                $fault->armed = false; throw new \RuntimeException('synthetic_cancel_receipt_failure');
            }
        });
        self::assertFalse($this->pop->runApprovedLocally($id)['ok']);
        self::assertFalse($fault->armed);
        self::assertSame(['scheduled', 'scheduled', 'scheduled'], $this->statuses());
        self::assertSame($before, $this->course());
        self::assertSame(0, DB::table('security_audit_events')->where('event_type', 'pop.past_scheduled_sessions_cancel')->count());
        self::assertSame('failed', DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->value('result'));
        self::assertSame('idle', $this->pop->runApprovedLocally()['status']);
        $this->travel(2)->minutes();
        self::assertTrue($this->renew($id)['ready']);
        self::assertSame('succeeded', $this->pop->runApprovedLocally()['status']);
        self::assertSame([1, 2], DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'execute')->orderBy('attempt_no')->pluck('attempt_no')->all());
    }

    public function test_partial_rollback_can_be_retried_without_overwriting_activity_or_replaying_the_failed_result(): void
    {
        $id = $this->approved(); self::assertSame('succeeded', $this->pop->runApprovedLocally($id)['status']);
        self::assertTrue($this->renew($id)['ready']);
        DB::table('session_deduction_ledger')->insert(['student_class_id' => 9200, 'class_session_id' => 9202,
            'event_type' => 'deduct', 'source' => 'attendance', 'created_at' => now(), 'updated_at' => now()]);
        $partial = $this->pop->runApprovedLocally();
        self::assertFalse($partial['ok']);
        self::assertSame(['scheduled', 'cancelled', 'scheduled'], $this->statuses());
        self::assertSame('idle', $this->pop->runApprovedLocally()['status']);
        self::assertTrue($this->renew($id)['ready']);
        self::assertSame('rollback-2', DB::table('pop_approval_events')->where('operation_id', $id)->latest('id')->value('phase'));
        // Synthetic downstream resolution only. The production rollback never removes activity.
        DB::table('session_deduction_ledger')->where('class_session_id', 9202)->delete();
        self::assertSame('rolled_back', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['scheduled', 'scheduled', 'scheduled'], $this->statuses());
        self::assertSame([1, 2], DB::table('pop_execution_records')->where('operation_id', $id)->where('phase', 'rollback')->orderBy('attempt_no')->pluck('attempt_no')->all());
    }

    public function test_new_recovery_does_not_enable_the_older_case_or_an_unapproved_request(): void
    {
        $old = $this->approved(self::OLD);
        self::assertSame('succeeded', $this->pop->runApprovedLocally($old)['status']);
        self::assertSame('skipped', $this->pop->runApprovedLocally($old, 'rollback')['status']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], $this->statuses());
        $draft = $this->pop->createDraft(self::OP, ['decision_reference' => self::OP], 'synthetic-unapproved', 'user:9901', 'super_admin', [1], 9901);
        self::assertSame('skipped', $this->pop->runApprovedLocally($draft['id'], 'rollback')['status']);
    }

    public function test_expired_rollback_intent_waits_for_fresh_approval_and_never_falls_back_to_execute(): void
    {
        $id = $this->approved(); $this->pop->runApprovedLocally(); $this->renew($id);
        $this->travel(2)->minutes();
        self::assertSame('idle', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], $this->statuses());
        self::assertTrue($this->renew($id)['ready']);
        self::assertSame('rolled_back', $this->pop->runApprovedLocally()['status']);
        $this->travel(2)->minutes();
        $this->expectExceptionMessage('already rolled back'); $this->renew($id);
    }

    public function test_missing_snapshot_or_preexisting_cancellation_cannot_create_rollback_authority(): void
    {
        DB::table('ClassSession')->whereIn('id', [9201, 9202])->update(['Status' => 'cancelled']);
        $id = $this->approved(); self::assertSame('succeeded', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], $this->statuses());
        $this->expectExceptionMessage('original successful execute snapshot'); $this->renew($id);
    }

    public function test_post_success_execute_reference_cannot_approve_rollback_or_change_any_row(): void
    {
        $id = $this->approved(); $this->pop->runApprovedLocally(); $error = null;
        try { $this->pop->approve($id, 'founder-go-past-scheduled-cancel-20261118', 'user:9901', 'super_admin', self::SHA, 9901, [1], 1); }
        catch (\RuntimeException $e) { $error = $e; }
        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame(1, DB::table('pop_approval_events')->where('operation_id', $id)->where('event_type', 'approved')->count());
        self::assertSame('idle', $this->pop->runApprovedLocally()['status']);
        self::assertSame(['cancelled', 'cancelled', 'scheduled'], $this->statuses());
    }
}

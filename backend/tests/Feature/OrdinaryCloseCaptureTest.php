<?php

namespace Tests\Feature;

use App\Services\Billing\OrdinaryCloseCapture;
use App\Services\Billing\OrdinaryCloseEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrdinaryCloseCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_and_close_delta_return_safe_partial_provenance(): void
    {
        $service = new OrdinaryCloseCapture();
        $course = $this->course(88032);
        $session = $this->makeSession($course, '2026-10-24', 'scheduled', '');
        $unicode = $this->makeSession($course, '2026-10-26', 'scheduled', ' 你好 [暫停取消]');
        $past = $this->makeSession($course, '2026-10-20', 'attended', 'unchanged');
        $movedOwner = $this->course(88039);
        $movedSession = $this->makeSession($movedOwner, '2026-10-21', 'attended', 'moved');
        $unrelatedOwner = $this->course(88040);
        $unrelatedSession = $this->makeSession($unrelatedOwner, '2026-10-22', 'attended', 'unrelated');
        $priorPointer = ['kind' => 'ordinary_close_corrected_v1', 'close_uuid' => (string) Str::uuid(), 'correction_event_id' => 93];
        DB::table('StudentClass')->where('ID', $course)->update(['settlement_snapshot' => json_encode($priorPointer)]);
        DB::table('session_entitlement_transfers')->insert($this->transfer($session, $course, $course));
        DB::table('class_session_reassignments')->insert($this->reassignment($session, $course, $course));
        $movedTransfer = $this->transfer($movedSession, $course, $movedOwner);
        unset($movedTransfer['id']);
        $movedTransfer['rolled_back_at'] = now();
        DB::table('session_entitlement_transfers')->insert($movedTransfer);
        $movedReassignment = $this->reassignment($movedSession, $course, $movedOwner);
        unset($movedReassignment['id']);
        DB::table('class_session_reassignments')->insert($movedReassignment);
        $unrelatedTransfer = $this->transfer($unrelatedSession, $unrelatedOwner, $unrelatedOwner);
        unset($unrelatedTransfer['id']);
        DB::table('session_entitlement_transfers')->insert($unrelatedTransfer);
        $unrelatedReassignment = $this->reassignment($unrelatedSession, $unrelatedOwner, $unrelatedOwner);
        unset($unrelatedReassignment['id']);
        DB::table('class_session_reassignments')->insert($unrelatedReassignment);
        $capture = DB::transaction(fn () => $service->captureLocked($course));
        $this->assertSame([$session, $unicode, $past], array_keys($capture['sessions']));
        $this->assertSame([$session, (int) DB::table('session_entitlement_transfers')->where('class_session_id', $movedSession)->value('id')], $capture['history']['session_entitlement_transfers']['ids']);
        $this->assertSame([$session, (int) DB::table('class_session_reassignments')->where('class_session_id', $movedSession)->value('id')], $capture['history']['class_session_reassignments']['ids']);
        $this->assertSame($session, $capture['history']['session_entitlement_transfers']['ids'][0]);
        $this->assertSame($session, $capture['history']['class_session_reassignments']['ids'][0]);
        $this->assertNotSame($capture['history']['session_entitlement_transfers']['digest'], $capture['history']['class_session_reassignments']['digest']);
        $this->assertArrayNotHasKey('Note', $capture['sessions'][$session]);
        $uuid = (string) Str::uuid();

        DB::beginTransaction();
        try {
            DB::table('StudentClass')->where('ID', $course)->update([
                'Stop' => 1, 'closed_reason' => 'settled', 'EndDate' => '2026-10-24 00:00:00',
                'settlement_snapshot' => json_encode(['kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false]),
                'MDate' => now(),
            ]);
            DB::table('ClassSession')->where('id', $session)->update([
                'Status' => 'cancelled', 'Note' => ' [結案取消]', 'updated_at' => now(),
            ]);
            DB::table('ClassSession')->where('id', $unicode)->update([
                'Status' => 'cancelled', 'Note' => ' 你好 [暫停取消] [結案取消]', 'updated_at' => now(),
            ]);
            $partial = $service->describeCloseLocked($capture, $uuid, 'settled', true, false, '2026-10-24');
            $this->assertSame([$session, $unicode], array_column($partial['cancelled_sessions'], 'id'));
            $this->assertSame($priorPointer, $partial['previous_pointer']);
            $this->assertFalse($partial['cancelled_sessions'][0]['note']['was_null']);
            $this->assertSame(0, $partial['cancelled_sessions'][0]['note']['byte_length']);
            $this->assertSame(strlen(' 你好 [暫停取消]'), $partial['cancelled_sessions'][1]['note']['byte_length']);
            $this->assertSame(hash('sha256', ' 你好 [暫停取消]'), $partial['cancelled_sessions'][1]['note']['sha256']);
            $this->assertArrayNotHasKey('guard_before_digest', $partial);
            $this->assertArrayNotHasKey('guard_digest', $partial);
            $this->assertSame(['sessions', 'entitlement_changes', 'session_reassignments'], array_keys($partial['guard_fact_ids']));
            DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function test_delta_refuses_a_changed_financial_field_and_wrong_note_suffix(): void
    {
        $service = new OrdinaryCloseCapture();
        $course = $this->course(88033);
        $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'same');
        $capture = DB::transaction(fn () => $service->captureLocked($course));
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        DB::table('StudentClass')->where('ID', $course)->update([
            'Stop' => 1, 'closed_reason' => 'completed', 'settlement_snapshot' => json_encode([
                'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
            ]), 'MDate' => now(),
        ]);
        DB::table('ClassSession')->where('id', $session)->update(['Status' => 'cancelled', 'Note' => 'same [結案取消]']);
        try {
            $service->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24');
            $this->fail('Wrong close suffix must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('note', strtolower($e->getMessage()));
        } finally { DB::rollBack(); }

        $course = $this->course(88034);
        $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'same');
        $capture = DB::transaction(fn () => $service->captureLocked($course));
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        DB::table('StudentClass')->where('ID', $course)->update([
            'Stop' => 1, 'closed_reason' => 'completed', 'settlement_snapshot' => json_encode([
                'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
            ]), 'Charge' => 1, 'MDate' => now(),
        ]);
        try {
            $service->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
            $this->fail('Financial drift must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contract field', $e->getMessage());
        } finally { DB::rollBack(); }
    }

    public function test_cancel_remaining_false_requires_no_cancelled_rows_and_partial_part_is_not_appendable(): void
    {
        $service = new OrdinaryCloseCapture();
        $course = $this->course(88035);
        $this->makeSession($course, '2026-10-29', 'scheduled', 'x');
        $capture = DB::transaction(fn () => $service->captureLocked($course));
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        DB::table('StudentClass')->where('ID', $course)->update([
            'Stop' => 1, 'closed_reason' => 'completed', 'settlement_snapshot' => json_encode([
                'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
            ]), 'MDate' => now(),
        ]);
        $partial = $service->describeCloseLocked($capture, $uuid, 'completed', false, true, '2026-10-24');
        $this->assertSame([], $partial['cancelled_sessions']);
        $this->assertSame(['sessions', 'entitlement_changes', 'session_reassignments'], array_keys($partial['guard_fact_ids']));
        $this->assertArrayHasKey('partial_guard_before_digest', $partial);
        $this->assertArrayHasKey('partial_guard_digest', $partial);
        $this->assertArrayNotHasKey('guard_before_digest', $partial);
        $this->assertArrayNotHasKey('guard_digest', $partial);
        try {
            (new OrdinaryCloseEvidence())->append($partial, 77, 1, $course);
            $this->fail('The partial capture must not be appendable as a complete close event.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('schema', $e->getMessage());
        }
        $this->assertSame(0, DB::table('security_audit_events')->count());
        DB::rollBack();
    }

    public function test_capture_rejects_more_than_one_thousand_combined_source_rows(): void
    {
        $course = $this->course(88037);
        $rows = [];
        for ($i = 0; $i < 1001; $i++) {
            $rows[] = ['StudentClassID' => $course, 'SessionDate' => '2026-10-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'StartTime' => '10:00:00', 'EndTime' => '11:00:00', 'Status' => 'attended', 'Note' => '', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('ClassSession')->insert($rows);
        try {
            DB::transaction(fn () => (new OrdinaryCloseCapture())->captureLocked($course));
            $this->fail('An over-budget census must fail closed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('row budget', $e->getMessage());
        }
    }

    public function test_capture_combines_rows_from_separate_tables_for_the_single_budget(): void
    {
        $course = $this->course(88038);
        $rows = [];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = ['StudentClassID' => $course, 'SessionDate' => '2026-10-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'StartTime' => sprintf('%02d:%02d:00', intdiv($i, 60), $i % 60), 'EndTime' => '23:59:59', 'Status' => 'attended',
                'Note' => '', 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('ClassSession')->insert($rows);
        $ids = DB::table('ClassSession')->where('StudentClassID', $course)->orderBy('id')->limit(401)->pluck('id');
        $histories = [];
        foreach ($ids as $id) $histories[] = $this->transfer((int) $id, $course, $course);
        DB::table('session_entitlement_transfers')->insert($histories);
        try {
            DB::transaction(fn () => (new OrdinaryCloseCapture())->captureLocked($course));
            $this->fail('The combined census must enforce its one-thousand row limit.');
        } catch (RuntimeException $e) { $this->assertStringContainsString('row budget', $e->getMessage()); }
    }

    public function test_delta_rejects_deleted_moved_or_replaced_sessions(): void
    {
        foreach (['delete', 'move', 'replace'] as $index => $change) {
            $course = $this->course(88050 + $index);
            $otherCourse = $this->course(88070 + $index);
            $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'x');
            $capture = DB::transaction(fn () => (new OrdinaryCloseCapture())->captureLocked($course));
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            $this->closeContract($course, $uuid);
            if ($change === 'delete' || $change === 'replace') DB::table('ClassSession')->where('id', $session)->delete();
            if ($change === 'move') DB::table('ClassSession')->where('id', $session)->update(['StudentClassID' => $otherCourse]);
            if ($change === 'replace') $this->makeSession($course, '2026-10-29', 'scheduled', 'x');
            try {
                (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
                $this->fail("A {$change}d session census must be refused.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('session census', $e->getMessage());
            } finally { DB::rollBack(); }
        }
    }

    public function test_cancel_and_forfeit_flags_remain_independent_in_capture(): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as $index => [$cancel, $forfeit]) {
            $course = $this->course(88060 + $index);
            $capture = DB::transaction(fn () => (new OrdinaryCloseCapture())->captureLocked($course));
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            $this->closeContract($course, $uuid);
            try {
                $part = (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', $cancel, $forfeit, '2026-10-24');
                $this->assertSame($cancel, $part['cancel_remaining']);
                $this->assertSame($forfeit, $part['forfeit_remaining']);
                $this->assertSame([], $part['cancelled_sessions']);
            } finally { DB::rollBack(); }
        }
    }

    public function test_delta_refuses_entitlement_history_drift(): void
    {
        $course = $this->course(88075);
        $session = $this->makeSession($course, '2026-10-20', 'attended', '');
        DB::table('session_entitlement_transfers')->insert($this->transfer($session, $course, $course));
        $capture = DB::transaction(fn () => (new OrdinaryCloseCapture())->captureLocked($course));
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
        DB::table('session_entitlement_transfers')->where('id', $session)->update(['reason' => 'changed']);
        try {
            (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
            $this->fail('An entitlement history edit must invalidate the census.');
        } catch (RuntimeException $e) { $this->assertStringContainsString('entitlement history', $e->getMessage()); }
        finally { DB::rollBack(); }
    }

    private function course(int $student): int
    {
        DB::table('Student')->insert(['id' => $student, 'name' => 'Capture test', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        return (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 1,
            'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Rate' => 0, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subMonth(), 'SessionCount' => 2, 'SessionDuration' => 60, 'RemainingSessions' => 1,
            'UsedSessions' => 1, 'Stop' => 0, 'ScheduleMode' => 'count', 'MDate' => now(),
        ]);
    }
    private function makeSession(int $course, string $date, string $status, ?string $note): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '10:00:00', 'EndTime' => '11:00:00',
            'Status' => $status, 'Note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    private function closeContract(int $course, string $uuid): void
    {
        DB::table('StudentClass')->where('ID', $course)->update([
            'Stop' => 1, 'closed_reason' => 'completed', 'settlement_snapshot' => json_encode([
                'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
            ]), 'MDate' => now(),
        ]);
    }
    private function transfer(int $session, int $source, int $target): array
    {
        return ['id' => $session, 'class_session_id' => $session, 'source_student_class_id' => $source, 'target_student_class_id' => $target,
            'reason' => 'test', 'decision_reference' => 'capture-test', 'transferred_at' => now(), 'snapshot_before' => '{}', 'created_at' => now(), 'updated_at' => now()];
    }
    private function reassignment(int $session, int $old, int $new): array
    {
        return ['id' => $session, 'class_session_id' => $session, 'old_student_class_id' => $old, 'new_student_class_id' => $new, 'reason' => 'test', 'created_at' => now()];
    }
}

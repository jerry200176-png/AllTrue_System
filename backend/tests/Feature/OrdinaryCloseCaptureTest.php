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
        $whitespace = $this->makeSession($course, '2026-10-25', 'scheduled', '   ');
        $past = $this->makeSession($course, '2026-10-20', 'attended', 'unchanged');
        $movedOwner = $this->course(88039);
        $movedSession = $this->makeSession($movedOwner, '2026-10-21', 'attended', 'moved');
        $unrelatedOwner = $this->course(88040);
        $unrelatedSession = $this->makeSession($unrelatedOwner, '2026-10-22', 'attended', 'unrelated');
        $priorPointer = ['kind' => 'ordinary_close_corrected_v1', 'close_uuid' => (string) Str::uuid(), 'correction_event_id' => 93];
        DB::table('StudentClass')->where('ID', $course)->update(['settlement_snapshot' => json_encode($priorPointer)]);
        DB::table('session_entitlement_transfers')->insert($this->transfer($session, $course, $course));
        DB::table('class_session_reassignments')->insert($this->reassignment($session, $course, $course));
        $movedTransfer = array_diff_key($this->transfer($movedSession, $course, $movedOwner), ['id' => true]);
        $movedTransfer['rolled_back_at'] = now();
        DB::table('session_entitlement_transfers')->insert($movedTransfer);
        DB::table('class_session_reassignments')->insert(array_diff_key($this->reassignment($movedSession, $course, $movedOwner), ['id' => true]));
        DB::table('session_entitlement_transfers')->insert(array_diff_key($this->transfer($unrelatedSession, $unrelatedOwner, $unrelatedOwner), ['id' => true]));
        DB::table('class_session_reassignments')->insert(array_diff_key($this->reassignment($unrelatedSession, $unrelatedOwner, $unrelatedOwner), ['id' => true]));
        $capture = $this->capture($course);
        $this->assertSame([$session, $unicode, $whitespace, $past], array_keys($capture['sessions']));
        $this->assertSame([$session, (int) DB::table('session_entitlement_transfers')->where('class_session_id', $movedSession)->value('id')], $capture['history']['session_entitlement_transfers']['ids']);
        $this->assertSame([$session, (int) DB::table('class_session_reassignments')->where('class_session_id', $movedSession)->value('id')], $capture['history']['class_session_reassignments']['ids']);
        $this->assertNotSame($capture['history']['session_entitlement_transfers']['digest'], $capture['history']['class_session_reassignments']['digest']);
        $this->assertArrayNotHasKey('Note', $capture['sessions'][$session]);
        $uuid = (string) Str::uuid();

        DB::beginTransaction();
        try {
            $this->closeContract($course, $uuid, 'settled', '2026-10-24 00:00:00');
            DB::table('ClassSession')->where('id', $session)->update([
                'Status' => 'cancelled', 'Note' => ' [結案取消]', 'updated_at' => now(),
            ]);
            DB::table('ClassSession')->where('id', $unicode)->update([
                'Status' => 'cancelled', 'Note' => ' 你好 [暫停取消] [結案取消]', 'updated_at' => now(),
            ]);
            DB::table('ClassSession')->where('id', $whitespace)->update([
                'Status' => 'cancelled', 'Note' => '    [結案取消]', 'updated_at' => now(),
            ]);
            $partial = $service->describeCloseLocked($capture, $uuid, 'settled', true, false, '2026-10-24');
            $this->assertSame([$session, $unicode, $whitespace], array_column($partial['cancelled_sessions'], 'id'));
            $this->assertSame($priorPointer, $partial['previous_pointer']);
            $this->assertFalse($partial['cancelled_sessions'][0]['note']['was_null']);
            $this->assertSame(0, $partial['cancelled_sessions'][0]['note']['byte_length']);
            $this->assertSame(strlen(' 你好 [暫停取消]'), $partial['cancelled_sessions'][1]['note']['byte_length']);
            $this->assertSame($this->noteDigest(' 你好 [暫停取消]'), $partial['cancelled_sessions'][1]['note']['sha256']);
            $this->assertSame($this->noteDigest(' 你好 [暫停取消] [結案取消]'), $partial['cancelled_sessions'][1]['note']['after_sha256']);
            $this->assertSame(3, $partial['cancelled_sessions'][2]['note']['byte_length']);
            $this->assertSame($this->noteDigest('   '), $partial['cancelled_sessions'][2]['note']['sha256']);
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
        $capture = $this->capture($course);
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
        DB::table('ClassSession')->where('id', $session)->update(['Status' => 'cancelled', 'Note' => 'same [暫停取消]']);
        try {
            $this->assertCount(1, $service->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24')['cancelled_sessions']);
            DB::table('ClassSession')->where('id', $session)->update(['Note' => 'same [結案取消]']);
            $service->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24');
            $this->fail('Wrong close suffix must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('note', strtolower($e->getMessage()));
        } finally { DB::rollBack(); }

        $course = $this->course(88034);
        $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'same');
        $capture = $this->capture($course);
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
        try {
            DB::table('StudentClass')->where('ID', $course)->update(['Charge' => 0]);
            $service->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
            DB::table('StudentClass')->where('ID', $course)->update(['Charge' => 1]);
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
        $capture = $this->capture($course);
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
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
        $this->capture($course);
        $ids = DB::table('ClassSession')->where('StudentClassID', $course)->orderBy('id')->limit(401)->pluck('id');
        $histories = [];
        foreach ($ids as $id) $histories[] = $this->transfer((int) $id, $course, $course);
        DB::table('session_entitlement_transfers')->insert($histories);
        try {
            $this->capture($course);
            $this->fail('The combined census must enforce its one-thousand row limit.');
        } catch (RuntimeException $e) { $this->assertStringContainsString('row budget', $e->getMessage()); }
    }

    public function test_delta_rejects_deleted_moved_or_replaced_sessions(): void
    {
        foreach (['delete', 'move', 'replace'] as $index => $change) {
            $course = $this->course(88050 + $index);
            $otherCourse = $this->course(88070 + $index);
            $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'x');
            $capture = $this->capture($course);
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            $this->closeContract($course, $uuid);
            (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
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
            $session = $this->makeSession($course, '2026-10-29', 'scheduled', 'flag');
            $capture = $this->capture($course);
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            $this->closeContract($course, $uuid);
            if ($cancel) DB::table('ClassSession')->where('id', $session)->update(['Status' => 'cancelled', 'Note' => 'flag [暫停取消]', 'updated_at' => now()]);
            try {
                $part = (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', $cancel, $forfeit, '2026-10-24');
                $this->assertSame($cancel, $part['cancel_remaining']);
                $this->assertSame($forfeit, $part['forfeit_remaining']);
                $this->assertCount($cancel ? 1 : 0, $part['cancelled_sessions']);
                $this->assertSame($cancel ? 'cancelled' : 'scheduled', DB::table('ClassSession')->where('id', $session)->value('Status'));
            } finally { DB::rollBack(); }
        }
    }

    public function test_note_schema_is_nonnull_and_truncated_post_close_note_is_refused(): void
    {
        $noteColumn = collect(DB::select("SHOW COLUMNS FROM ClassSession LIKE 'Note'"))->first();
        $this->assertNotNull($noteColumn);
        $this->assertSame('NO', $noteColumn->Null, 'The current schema does not permit a NULL Note fixture.');

        $course = $this->course(88076);
        $original = str_repeat('x', 240);
        $session = $this->makeSession($course, '2026-10-29', 'scheduled', $original);
        $capture = $this->capture($course);
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
        DB::table('ClassSession')->where('id', $session)->update(['Status' => 'cancelled', 'Note' => $original.' [暫停取消]', 'updated_at' => now()]);
        $this->assertCount(1, (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24')['cancelled_sessions']);
        DB::table('ClassSession')->where('id', $session)->update([
            'Note' => $original.' [暫停',
        ]);
        try {
            (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', true, false, '2026-10-24');
            $this->fail('A truncated suffix cannot be represented as reversible evidence.');
        } catch (RuntimeException $e) { $this->assertStringContainsString('note inverse', $e->getMessage()); }
        finally { DB::rollBack(); }
    }

    public function test_note_hmac_keeps_null_distinct_from_empty_without_persisting_plain_hashes(): void
    {
        $method = new \ReflectionMethod(OrdinaryCloseCapture::class, 'digestValue');
        $method->setAccessible(true);
        $service = new OrdinaryCloseCapture();
        $this->assertNotSame($method->invoke($service, 'note', null), $method->invoke($service, 'note', ''));
        $this->assertNotSame(hash('sha256', 'private note'), $this->noteDigest('private note'));
    }

    public function test_delta_refuses_each_unchanged_session_field_drift(): void
    {
        $mutations = [
            ['SubjectID' => 2], ['SessionDate' => '2026-10-21'], ['StartTime' => '12:00:00'], ['EndTime' => '12:00:00'],
            ['updated_at' => '2026-10-01 00:00:00'], ['IsContractException' => 1],
            ['session_charge' => 1], ['OriginalSessionDate' => '2026-10-01'],
            ['OriginalStartTimeHM' => '08:30'], ['created_at' => '2026-10-01 00:00:00'],
        ];
        foreach ($mutations as $index => $mutation) {
            $course = $this->course(88080 + $index);
            $session = $this->makeSession($course, '2026-10-20', 'attended', 'stable');
            $capture = $this->capture($course);
            $uuid = (string) Str::uuid();
            DB::beginTransaction();
            $this->closeContract($course, $uuid);
            (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
            DB::table('ClassSession')->where('id', $session)->update($mutation);
            try {
                (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
                $this->fail('Session field drift must be refused.');
            } catch (RuntimeException $e) {
                $expected = array_key_exists('updated_at', $mutation) ? 'ineligible session' : 'unsupported session field';
                $this->assertStringContainsString($expected, $e->getMessage());
            }
            finally { DB::rollBack(); }
        }
    }

    public function test_capture_refuses_an_unsupported_previous_pointer(): void
    {
        $course = $this->course(88090);
        $this->capture($course);
        DB::table('StudentClass')->where('ID', $course)->update(['settlement_snapshot' => '{"kind":"contract_amended","unexpected":true}']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported previous ordinary-close pointer.');
        $this->capture($course);
    }

    public function test_delta_refuses_entitlement_history_drift(): void
    {
        $course = $this->course(88075);
        $session = $this->makeSession($course, '2026-10-20', 'attended', '');
        DB::table('session_entitlement_transfers')->insert($this->transfer($session, $course, $course));
        $capture = $this->capture($course);
        $uuid = (string) Str::uuid();
        DB::beginTransaction();
        $this->closeContract($course, $uuid);
        (new OrdinaryCloseCapture())->describeCloseLocked($capture, $uuid, 'completed', false, false, '2026-10-24');
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
    private function capture(int $course): array
    {
        $student = (int) DB::table('StudentClass')->where('ID', $course)->value('StudentID');
        return DB::transaction(function () use ($course, $student) {
            DB::table('Student')->where('id', $student)->lockForUpdate()->first();
            return (new OrdinaryCloseCapture())->captureLocked($course);
        });
    }
    private function makeSession(int $course, string $date, string $status, ?string $note): int
    {
        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course, 'SessionDate' => $date, 'StartTime' => '10:00:00', 'EndTime' => '11:00:00',
            'Status' => $status, 'Note' => $note, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    private function closeContract(int $course, string $uuid, string $reason = 'completed', ?string $endDate = null): void
    {
        DB::table('StudentClass')->where('ID', $course)->update([
            'Stop' => 1, 'closed_reason' => $reason, 'EndDate' => $endDate, 'settlement_snapshot' => json_encode([
                'kind' => 'ordinary_close_v1', 'close_uuid' => $uuid, 'version' => 1, 'invalidated' => false,
            ]), 'MDate' => now(),
        ]);
    }
    private function transfer(int $session, int $source, int $target): array
    {
        return ['id' => $session, 'class_session_id' => $session, 'source_student_class_id' => $source, 'target_student_class_id' => $target,
            'reason' => 'test', 'decision_reference' => 'capture-test', 'transferred_at' => now(), 'snapshot_before' => '{}', 'created_at' => now(), 'updated_at' => now()];
    }
    private function noteDigest(?string $note): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = (string) base64_decode(substr($key, 7), true);
        $json = json_encode(['value' => $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', 'alltrue-ordinary-close-capture-v1|note|'.$json, $key);
    }
    private function reassignment(int $session, int $old, int $new): array
    {
        return ['id' => $session, 'class_session_id' => $session, 'old_student_class_id' => $old, 'new_student_class_id' => $new, 'reason' => 'test', 'created_at' => now()];
    }
}

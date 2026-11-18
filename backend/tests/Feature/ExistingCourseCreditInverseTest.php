<?php

namespace Tests\Feature;

use App\Models\{AuthToken, ClassSession, ExistingCourseCreditAllocationGroup, Invoice, Payment, PaymentReport, Student, StudentClass, SubjectCreditTransfer, UserCampus};
use App\Services\Billing\ExistingCourseCreditMovement;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExistingCourseCreditFixtures;
use Tests\TestCase;

class ExistingCourseCreditInverseTest extends TestCase
{
    use RefreshDatabase, ExistingCourseCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_group_inverse_preserves_taught_target_quantities_and_cash_and_restores_only_selected_future_rows(): void
    {
        [$source, $targets, $input, $actor, $campus] = $this->allocationWithTeacher();
        $beforeSource = $source->fresh()->only(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Pay', 'Paid', 'Stop', 'closed_reason']);
        $beforeTargets = [];
        foreach ($targets as $id => $_) $beforeTargets[$id] = StudentClass::findOrFail($id)->only(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Pay', 'Paid', 'PayDate', 'Stop', 'closed_reason']);
        $cash = Payment::where('Method', 'cash')->firstOrFail()->getAttributes();
        $lessons = ClassSession::orderBy('id')->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->toArray();
        $group = $this->fund($source, $input, $actor, $campus);
        $audit = $group->only(['source_before', 'source_after', 'targets_before', 'targets_after', 'released_sessions']);
        $result = $this->reverse($source, $group, $actor, $campus);
        $this->assertFalse($result['replayed']);
        $this->assertSame($beforeSource, $source->fresh()->only(array_keys($beforeSource)));
        $this->assertSame([960, 720], array_values(array_map('intval', $source->fresh()->only(['PurchasedMinutes', 'RemainingMinutes']))));
        foreach ($beforeTargets as $id => $values) $this->assertSame($values, StudentClass::findOrFail($id)->only(array_keys($values)));
        $this->assertSame($cash, Payment::findOrFail($cash['id'])->getAttributes());
        $this->assertSame($lessons, ClassSession::orderBy('id')->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->toArray());
        $this->assertSame($audit, $group->fresh()->only(array_keys($audit)), 'Inverse retains the original audited before/after images.');
        $this->assertNotNull($group->fresh()->reversed_at);
        $this->assertSame(2, SubjectCreditTransfer::where('allocation_group_id', $group->id)->whereNotNull('reversed_at')->count());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
        $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_reversal_in', 'transfer_reversal_out'])->sum('Amount'));
        foreach ($targets as $id => $_) {
            $bill = Invoice::where('StudentClassID', $id)->firstOrFail();
            $this->assertSame('void', $bill->Status);
            $this->assertSame(0, (int) $bill->TotalAmount);
        }
    }

    public function test_same_actor_reason_replay_after_inverse_is_a_no_op_even_after_the_old_slots_pass(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        $this->reverse($source, $group, $actor, $campus);
        Carbon::setTestNow('2026-11-30 10:00:00');
        $before = $this->businessSnapshot();
        $again = $this->reverse($source, $group, $actor, $campus);
        $this->assertTrue($again['replayed']);
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_original_confirmed_header_receipt_keeps_its_quantity_dates_and_cash_through_group_and_inverse(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $invoice = Invoice::where('StudentClassID', $source->ID)->firstOrFail();
        $cash = Payment::where('InvoiceID', $invoice->id)->firstOrFail();
        $report = PaymentReport::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'isolated payer', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => 12000, 'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $cash->id,
            'report_token_hash' => hash('sha256', 'isolated allocation receipt'), 'token_expires_at' => now()->addDay()]);
        $cash->forceFill(['payment_report_id' => $report->id])->save();
        $token = (string) AuthToken::where('user_id', $actor)->value('token');
        $fields = array_flip(['receipt_no', 'amount', 'payment_date', 'session_count', 'subject', 'class_type', 'schedule_mode', 'session_dates', 'period_start', 'period_end']);
        $printed = array_intersect_key($this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->json(), $fields);
        $cashBefore = $cash->fresh()->getAttributes(); $reportBefore = $report->fresh()->getAttributes();
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $group = $this->fund($source, $input, $actor, $campus);
        $this->assertSame($printed, array_intersect_key($this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->json(), $fields));
        $this->reverse($source, $group, $actor, $campus);
        $this->assertSame($printed, array_intersect_key($this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->json(), $fields));
        $this->assertSame($cashBefore, $cash->fresh()->getAttributes());
        $this->assertSame($reportBefore, $report->fresh()->getAttributes());
        $this->assertDatabaseCount('payment_reports', 1);
    }

    public function test_later_financial_or_target_quantity_drift_blocks_the_entire_inverse(): void
    {
        [$source, $targets, $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        $target = StudentClass::findOrFail(array_key_last($targets));
        $target->forceFill(['Charge' => (int) $target->Charge + 1])->save();
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_changed');
    }

    public function test_a_released_lesson_that_has_started_cannot_be_silently_restored(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        Carbon::setTestNow('2026-10-02 09:00:00');
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_expired');
    }

    public function test_current_teacher_occupancy_blocks_restore_without_reversing_any_money(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        $student = Student::create(['name' => 'isolated-new-occupant', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $other = $source->replicate(); $other->forceFill(['StudentID' => $student->id, 'ClassType' => 'one_on_one'])->save();
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-10-02', 'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_capacity');
    }

    public function test_current_room_limit_blocks_restore_even_when_teacher_still_has_a_seat(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $room = DB::table('rooms')->insertGetId(['campus_id' => $campus, 'name' => 'isolated-small-room', 'capacity' => 2]);
        $source->forceFill(['ClassType' => 'one_on_three', 'room_id' => $room])->save();
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $group = $this->fund($source, $input, $actor, $campus);
        $student = Student::create(['name' => 'isolated-room-occupant', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $other = $source->replicate(); $other->StudentID = $student->id; $other->save();
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-10-02', 'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_capacity');
    }

    public function test_legacy_unassigned_teacher_group_cannot_skip_room_safety_when_restoring_lessons(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $room = DB::table('rooms')->insertGetId(['campus_id' => $campus, 'name' => 'isolated-unassigned-room', 'capacity' => 2]);
        $source->forceFill(['ClassType' => 'one_on_three', 'room_id' => $room])->save();
        $beforeAttributes = $source->refresh()->getAttributes();
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $group = $this->fund($source, $input, $actor, $campus);
        // Seed the valid persisted shape accepted by the earlier writer with TeacherID=0.
        // The new preview must refuse creating it; inverse still has to protect historical groups.
        $source->refresh()->forceFill(['TeacherID' => 0])->save();
        $before = $group->source_before; $after = $group->source_after;
        $before['course']['TeacherID'] = $after['course']['TeacherID'] = $beforeAttributes['TeacherID'] = 0;
        $before['course_context_digest'] = \App\Services\Billing\SubjectCreditState::digest($beforeAttributes);
        $after['course_context_digest'] = \App\Services\Billing\SubjectCreditState::digest($source->fresh()->getAttributes());
        $group->forceFill(['source_before' => $before, 'source_after' => $after])->save();
        foreach (SubjectCreditTransfer::where('allocation_group_id', $group->id)->get() as $child) {
            $childBefore = $child->source_before; $childAfter = $child->source_after;
            $childBefore['course']['TeacherID'] = $childAfter['course']['TeacherID'] = 0;
            $child->forceFill(['source_before' => $childBefore, 'source_after' => $childAfter])->save();
        }
        $student = Student::create(['name' => 'isolated-unassigned-occupant', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $other = $source->fresh()->replicate(); $other->StudentID = $student->id; $other->save();
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-10-02', 'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        $audit = $group->fresh()->getAttributes();
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_calendar_unverified');
        $this->assertSame($audit, $group->fresh()->getAttributes());
    }

    public function test_unassigned_teacher_does_not_block_funding_and_inverse_without_calendar_releases(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->delete();
        $source->forceFill(['TeacherID' => 0])->save();
        $input['released_session_ids'] = [];
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $before = $source->fresh()->only(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Pay', 'Paid']);
        $group = $this->fund($source, $input, $actor, $campus);
        $this->assertSame([], $group->released_sessions);
        $this->assertFalse($this->reverse($source, $group, $actor, $campus)['replayed']);
        $this->assertSame($before, $source->fresh()->only(array_keys($before)));
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    public function test_exhausted_source_restore_checks_the_students_new_other_course_before_reopening_quota(): void
    {
        [$source, $targets, $input, $actor, $campus] = $this->allocationWithTeacher();
        StudentClass::findOrFail(array_key_last($targets))->forceFill(['SessionCount' => 5, 'Charge' => 7500, 'RemainingSessions' => 2])->save();
        $input['released_session_ids'] = ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->orderBy('id')->pluck('id')->all();
        $input['state_hash'] = app(\App\Services\Billing\ExistingCourseCreditPreview::class)->run($source, $input, [$campus])['state_hash'];
        $group = $this->fund($source, $input, $actor, $campus);
        $this->assertSame(0, (int) $source->fresh()->RemainingSessions);
        $this->assertSame(1, (int) $source->fresh()->Stop);
        $other = $source->fresh()->replicate();
        $other->forceFill(['SubjectID' => 970010, 'TeacherID' => 0, 'SessionCount' => 4, 'UsedSessions' => 0,
            'RemainingSessions' => 4, 'Stop' => 0, 'closed_reason' => null])->save();
        ClassSession::create(['StudentClassID' => $other->ID, 'SessionDate' => '2026-10-02', 'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_student_overlap');
    }

    public function test_second_reversal_failure_rolls_back_money_flags_audit_and_lesson_restore(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        $before = $this->businessSnapshot(); $audit = $group->fresh()->getAttributes();
        $fail = true; $out = 0;
        Payment::creating(function (Payment $payment) use (&$fail, &$out): void {
            if ($fail && $payment->Method === 'transfer_reversal_out' && ++$out === 2) throw new \RuntimeException('isolated second-reversal failure');
        });
        try {
            try { $this->reverse($source, $group, $actor, $campus); $this->fail('A partial inverse must not commit.'); }
            catch (\RuntimeException $e) { $this->assertSame('isolated second-reversal failure', $e->getMessage()); }
        } finally { $fail = false; }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($audit, $group->fresh()->getAttributes());
        $this->assertSame(0, SubjectCreditTransfer::where('allocation_group_id', $group->id)->whereNotNull('reversed_at')->count());
    }

    public function test_inconsistent_child_amount_cannot_generate_more_reversal_credit_than_the_actual_funding(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocationWithTeacher();
        $group = $this->fund($source, $input, $actor, $campus);
        $child = SubjectCreditTransfer::where('allocation_group_id', $group->id)->firstOrFail();
        $child->forceFill(['amount' => (int) $child->getAttribute('amount') + 1])->save();
        $this->assertBlocked($source, $group, $actor, $campus, 'existing_credit_reverse_changed');
    }

    private function allocationWithTeacher(): array
    {
        $fixture = $this->allocation(); [$source, , , , $campus] = $fixture;
        UserCampus::firstOrCreate(['UserID' => $source->TeacherID, 'CampusID' => $campus], ['Admin' => 0, 'Approved' => 1]);
        return $fixture;
    }

    private function fund(StudentClass $source, array $input, int $actor, int $campus): ExistingCourseCreditAllocationGroup
    {
        $result = app(ExistingCourseCreditMovement::class)->apply($source, $input, $actor, [$campus]);
        return ExistingCourseCreditAllocationGroup::findOrFail($result['body']['allocation_group']['id']);
    }

    private function reverse(StudentClass $source, ExistingCourseCreditAllocationGroup $group, int $actor, int $campus): array
    {
        return app(ExistingCourseCreditMovement::class)->reverse($source, (int) $group->id, 'restore this allocation', $actor, [$campus]);
    }

    private function assertBlocked(StudentClass $source, ExistingCourseCreditAllocationGroup $group, int $actor, int $campus, string $code): void
    {
        $before = $this->businessSnapshot();
        try { $this->reverse($source, $group, $actor, $campus); $this->fail('An unsafe inverse must not commit.'); }
        catch (HttpResponseException $e) { $this->assertSame($code, $e->getResponse()->getData(true)['code']); }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertNull($group->fresh()->reversed_at);
    }
}

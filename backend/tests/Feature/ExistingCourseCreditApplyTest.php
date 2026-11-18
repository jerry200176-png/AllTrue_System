<?php

namespace Tests\Feature;

use App\Models\{AuthToken, ClassSession, ExistingCourseCreditAllocationGroup, Invoice, InvoiceItem, Payment, Student, StudentClass, SubjectCreditTransfer};
use App\Services\Billing\{ExistingCourseCreditMovement, ExistingCourseCreditPreview, PaidContractCreditMovement};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ExistingCourseCreditFixtures;
use Tests\TestCase;

class ExistingCourseCreditApplyTest extends TestCase
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

    public function test_atomic_group_funds_existing_used_targets_preserving_cash_history_and_explicit_reservations(): void
    {
        [$source, $targets, $input, $actor, $campus] = $this->allocation();
        $cash = Payment::where('Method', 'cash')->firstOrFail()->getAttributes();
        $taught = ClassSession::where('Status', 'attended')->orderBy('id')->get()->toArray();
        $result = $this->apply($source, $input, $actor, $campus);
        $this->assertSame(201, $result['status']);
        $this->assertSame([3, 2, 1, 4500], array_values(array_map('intval', $source->fresh()->only(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge']))));
        $this->assertSame([360, 120], array_values(array_map('intval', $source->fresh()->only(['PurchasedMinutes', 'RemainingMinutes']))));
        foreach ($targets as $id => [$count, $used, $amount]) {
            $target = StudentClass::findOrFail($id);
            $this->assertSame([$count, $used, $count - $used, $amount, 1, $amount], array_values(array_map('intval', $target->only(['SessionCount', 'UsedSessions', 'RemainingSessions', 'Charge', 'Paid', 'Pay']))));
            $this->assertSame('2026-09-01', substr((string) $target->StartDate, 0, 10));
            $bill = Invoice::where('StudentClassID', $id)->firstOrFail();
            $this->assertSame('2026-09-30', substr((string) $bill->IssueDate, 0, 10), 'New funding documents are issued today, not backdated to an old contract.');
        }
        $this->assertSame($cash, Payment::findOrFail($cash['id'])->getAttributes());
        $this->assertSame($taught, ClassSession::where('Status', 'attended')->orderBy('id')->get()->toArray());
        $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_in', 'transfer_out'])->sum('Amount'));
        $this->assertSame(12000, (int) Payment::sum('Amount'));
        $this->assertSame(5, ClassSession::whereIn('id', $input['released_session_ids'])->where('Status', 'cancelled')->count());
        $this->assertSame(1, ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->count());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 1);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 2);
        foreach (SubjectCreditTransfer::all() as $child) {
            $this->assertNotNull($child->allocation_group_id);
            $this->assertSame(ExistingCourseCreditAllocationGroup::SNAPSHOT_PROTOCOL, $child->source_after['snapshot_protocol']);
        }
    }

    public function test_exact_replay_returns_the_group_before_target_eligibility_and_changed_content_conflicts(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $first = $this->apply($source, $input, $actor, $campus);
        $before = $this->businessSnapshot();
        $again = $this->apply($source, $input, $actor, $campus);
        $this->assertSame(200, $again['status']);
        $this->assertTrue($again['body']['replayed']);
        $this->assertSame($first['body']['allocation_group']['id'], $again['body']['allocation_group']['id']);
        $this->assertSame($before, $this->businessSnapshot());
        try { $this->apply($source, array_replace($input, ['reason' => 'different command']), $actor, $campus); $this->fail('A key cannot pay twice.'); }
        catch (HttpResponseException $e) { $this->assertSame('existing_credit_key_reused', $e->getResponse()->getData(true)['code']); }
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 1);
    }

    public function test_failure_on_second_incoming_payment_rolls_back_both_targets_source_cancellations_and_audit(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $before = $this->businessSnapshot(); $auditCount = DB::table('schedule_audit_logs')->count();
        $fail = true; $incoming = 0;
        Payment::creating(function (Payment $payment) use (&$fail, &$incoming): void {
            if ($fail && $payment->Method === 'transfer_in' && ++$incoming === 2) throw new \RuntimeException('isolated second-target failure');
        });
        try {
            try { $this->apply($source, $input, $actor, $campus); $this->fail('Partial funding must never commit.'); }
            catch (\RuntimeException $e) { $this->assertSame('isolated second-target failure', $e->getMessage()); }
        } finally { $fail = false; }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 0);
        $this->assertSame($auditCount, DB::table('schedule_audit_logs')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('replayScopes')]
    public function test_replay_is_bound_to_actor_and_the_locked_student_campus_source(string $mismatch): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $first = $this->apply($source, $input, $actor, $campus);
        $group = ExistingCourseCreditAllocationGroup::findOrFail($first['body']['allocation_group']['id']);
        if ($mismatch === 'actor') {
            $otherToken = $this->token($campus);
            $actor = (int) AuthToken::where('token', $otherToken)->value('user_id');
        } else {
            $group->forceFill([$mismatch => (int) $group->getAttribute($mismatch) + 1000])->save();
        }
        $before = $this->businessSnapshot(); $audit = $group->fresh()->getAttributes();
        try { $this->apply($source, $input, $actor, $campus); $this->fail('A replay must not inherit another actor or persisted scope.'); }
        catch (HttpResponseException $e) { $this->assertSame('existing_credit_replay_scope', $e->getResponse()->getData(true)['code']); }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($audit, $group->fresh()->getAttributes());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 1);
    }

    public static function replayScopes(): array
    {
        return [['actor'], ['student_id'], ['campus_id'], ['source_student_class_id']];
    }

    public function test_free_source_capacity_accepts_preview_apply_and_replay_without_a_release_key(): void
    {
        [$source, $targets, , $actor, $campus] = $this->allocation();
        // A genuinely unreserved source needs no cancellation or release confirmation.
        ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->delete();
        $input = ['target_course_ids' => array_keys($targets)];
        $preview = app(ExistingCourseCreditPreview::class)->run($source, $input, [$campus]);
        $input += ['state_hash' => $preview['state_hash'], 'idempotency_key' => 'isolated-free-source', 'reason' => 'unreserved paid credit'];
        $first = $this->apply($source, $input, $actor, $campus);
        $this->assertSame(201, $first['status']);
        $group = ExistingCourseCreditAllocationGroup::findOrFail($first['body']['allocation_group']['id']);
        $this->assertSame([], $group->released_sessions);
        $before = $this->businessSnapshot();
        $again = $this->apply($source, $input, $actor, $campus);
        $this->assertSame(200, $again['status']);
        $this->assertTrue($again['body']['replayed']);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame(1, (int) $source->fresh()->RemainingSessions);
    }

    public function test_stale_preview_never_applies_or_cancels_selected_lessons(): void
    {
        [$source, , $input, $actor, $campus] = $this->allocation();
        $source->forceFill(['Memo' => 'synthetic intervening edit'])->save();
        $before = $this->businessSnapshot();
        try { $this->apply($source, $input, $actor, $campus); $this->fail('Stale preview must be refused.'); }
        catch (HttpResponseException $e) { $this->assertSame('existing_credit_preview_changed', $e->getResponse()->getData(true)['code']); }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('existing_course_credit_allocation_groups', 0);
    }

    public function test_explicit_new_invoice_date_does_not_change_original_cash_date_or_legacy_default(): void
    {
        [, $source, $target, $invoice] = $this->fixture();
        DB::transaction(function () use ($source, $target, $invoice): void {
            $moved = app(PaidContractCreditMovement::class)->move($invoice, $source, $target, 1, 1500, '2026-09-01', 'isolated allocation', '2026-09-30');
            $this->assertSame('2026-09-30', substr((string) $moved['invoice']->IssueDate, 0, 10));
            $this->assertSame('2026-09-01', substr((string) $moved['in']->PaidAt, 0, 10));
            $legacy = app(PaidContractCreditMovement::class)->move($invoice, $source, $target, 1, 1500, '2026-09-01', 'isolated legacy');
            $this->assertSame('2026-09-01', substr((string) $legacy['invoice']->IssueDate, 0, 10));
        });
    }


    private function apply(StudentClass $source, array $input, int $actor, int $campus): array
    {
        return app(ExistingCourseCreditMovement::class)->apply($source, $input, $actor, [$campus]);
    }
}

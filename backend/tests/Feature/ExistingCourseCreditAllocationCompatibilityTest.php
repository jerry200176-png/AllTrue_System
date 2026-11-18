<?php

namespace Tests\Feature;

use App\Models\{ExistingCourseCreditAllocationGroup, SubjectCreditTransfer};
use App\Services\Billing\SubjectCreditState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** New allocation groups must never reach the legacy inverse which deletes a new batch's quota. */
class ExistingCourseCreditAllocationCompatibilityTest extends TestCase
{
    use RefreshDatabase;
    use SubjectCreditFixtures;

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

    public function test_group_member_cannot_use_legacy_single_transfer_inverse_even_without_protocol_marker(): void
    {
        [$token, $source, $template] = $this->fixture();
        $base = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $preview = $this->withToken($token)->postJson("{$base}/preview", ['target_course_id' => $template->ID, 'sessions' => 2])->assertOk();
        $this->withToken($token)->postJson($base, ['target_course_id' => $template->ID, 'sessions' => 2,
            'reason' => 'isolated allocation compatibility', 'idempotency_key' => 'allocation-compatibility',
            'state_hash' => $preview->json('state_hash')])->assertCreated();
        $record = SubjectCreditTransfer::firstOrFail();
        $group = DB::table('existing_course_credit_allocation_groups')->insertGetId([
            'operation_key' => str_repeat('a', 64), 'request_fingerprint' => str_repeat('b', 64),
            'campus_id' => $record->campus_id, 'student_id' => $record->student_id,
            'source_student_class_id' => $source->ID, 'source_invoice_id' => $record->source_invoice_id,
            'created_by' => $record->created_by, 'sessions' => 2, 'amount' => $record->amount, 'reason' => 'isolated group',
            'source_before' => json_encode($record->source_before), 'source_after' => json_encode($record->source_after),
            'targets_before' => '[]', 'targets_after' => json_encode([$record->target_after]), 'released_sessions' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contract_subject_credit_transfers')->where('id', $record->id)->update(['allocation_group_id' => $group]);
        $before = $this->businessSnapshot();
        $this->withToken($token)->postJson("{$base}/{$record->id}/reverse", ['reason' => 'must use atomic group inverse'])
            ->assertStatus(409)->assertJsonPath('code', 'subject_credit_allocation_group');
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertNull($record->fresh()->reversed_at);
        $this->assertNull(DB::table('existing_course_credit_allocation_groups')->where('id', $group)->value('reversed_at'));
        $this->assertTrue(ExistingCourseCreditAllocationGroup::storageReady());
    }

    public function test_versioned_snapshot_also_refuses_the_legacy_inverse_without_new_group_metadata(): void
    {
        [$token, $source, $template] = $this->fixture();
        $base = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer";
        $preview = $this->withToken($token)->postJson("{$base}/preview", ['target_course_id' => $template->ID, 'sessions' => 2])->assertOk();
        $this->withToken($token)->postJson($base, ['target_course_id' => $template->ID, 'sessions' => 2,
            'reason' => 'isolated rollback compatibility', 'idempotency_key' => 'allocation-rollback-compatibility',
            'state_hash' => $preview->json('state_hash')])->assertCreated();
        $record = SubjectCreditTransfer::firstOrFail();
        $state = $record->source_after;
        $versioned = ExistingCourseCreditAllocationGroup::childSnapshot($state);
        $this->assertNotSame(SubjectCreditState::digest($state), SubjectCreditState::digest($versioned));
        $record->forceFill(['source_after' => $versioned])->save();
        // Exercise the preexisting full-snapshot guard, without relying on the newly added group guard.
        $this->assertNull($record->allocation_group_id);
        $before = $this->businessSnapshot();
        $this->withToken($token)->postJson("{$base}/{$record->id}/reverse", ['reason' => 'old inverse must refuse unknown protocol'])
            ->assertStatus(409)->assertJsonPath('code', 'subject_credit_reverse_changed');
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertNull($record->fresh()->reversed_at);
    }
}

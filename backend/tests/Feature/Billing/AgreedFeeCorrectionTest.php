<?php

namespace Tests\Feature\Billing;

use App\Models\{ClassSession, Invoice, Payment, PaymentReport, StudentClass};
use App\Services\Billing\AgreedFeeCorrection;
use Illuminate\Support\Facades\{DB, Http, Notification, Queue, Schema};
use App\Services\Billing\ContractMoneyState;
use RuntimeException;
use Illuminate\Support\Str;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\Support\AgreedFeeTestSchema;
use Tests\TestCase;

/** Local synthetic agreed-fee correction: fee and obligation change, teaching entitlement does not. */
class AgreedFeeCorrectionTest extends TestCase
{
    use AgreedFeeRefreshDatabase, AgreedFeeFixture;

    private function path(array $f): string { return '/api/v1/student-classes/'.$f['course']->ID.'/agreed-fee-correction'; }

    public function test_disabled_by_default_and_campus_empty_scope_fail_closed(): void
    {
        $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertNotFound();
        $this->assertFalse(config('billing.agreed_fee_correction_enabled'));
        $this->assertFalse(AgreedFeeCorrection::available(), 'flag false ⇒ unavailable even if test schema has columns');
        config(['billing.agreed_fee_correction_enabled' => true]);
        $f['student']->update(['CampusID' => 2]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertForbidden();
        $f['student']->update(['CampusID' => 1]);
        DB::table('UserCampus')->where('UserID', $f['user']->id)->delete();
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertForbidden();
    }

    public function test_missing_extension_columns_fail_closed_even_when_flag_on(): void
    {
        // End outer Testing txn *before* DROP — MySQL DDL implicit-commits and must not
        // leave Laravel's transaction counter desynced (next begin would be SAVEPOINT-only).
        $this->endOuterTestingTransactions();
        AgreedFeeTestSchema::dropExtensionColumns();
        try {
            config(['billing.agreed_fee_correction_enabled' => true]);
            $this->assertFalse(AgreedFeeCorrection::available());
            $f = $this->fixture();
            $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->feePayload($f['invoice']))
                ->assertStatus(503)
                ->assertJsonPath('code', 'unavailable');
        } finally {
            $this->rebuildIsolatedSchema();
        }
    }

    public function test_trial_8000_to_500_preserves_originals_rate_count_usage_and_dates_with_no_money_or_messages(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        Http::fake(); Notification::fake(); Queue::fake();
        $notificationCount = DB::table('Notifications')->count();
        $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        ClassSession::create(['StudentClassID' => $f['course']->ID, 'SessionDate' => '2026-10-02', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $before = $f['course']->fresh()->getRawOriginal(); $items = DB::table('InvoiceItem')->get()->toArray(); $sessions = DB::table('ClassSession')->get()->toArray();
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()
            ->assertJsonPath('old_charge', 8000)->assertJsonPath('original_invoice_total', 7600)->assertJsonPath('new_charge', 500)
            ->assertJsonPath('charge_delta', -7500)->assertJsonPath('obligation_delta', -7100)->assertJsonPath('new_outstanding', 500)->assertJsonPath('effects.payment', 0)->assertJsonPath('effects.notification', 0)->json();
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview['preview_token']])->assertOk()->json();
        $after = $f['course']->fresh()->getRawOriginal(); unset($before['Charge'], $after['Charge']);
        $this->assertEquals($before, $after); $this->assertSame(500, (int) $f['course']->fresh()->Charge);
        $this->assertSame(7600, (int) $f['invoice']->fresh()->TotalAmount); $this->assertSame('void', $f['invoice']->fresh()->Status);
        $this->assertStringStartsWith('Original retained note', $f['invoice']->fresh()->Note);
        $this->assertEquals($items, DB::table('InvoiceItem')->where('InvoiceID', $f['invoice']->id)->get()->toArray());
        $this->assertEquals($sessions, DB::table('ClassSession')->get()->toArray());
        $new = Invoice::findOrFail($result['replacement_invoice_id']);
        $this->assertSame(500, (int) $new->TotalAmount); $this->assertSame('unpaid', $new->Status); $this->assertSame(0, (int) $new->PaidAmount);
        $this->assertSame(0, Payment::count()); $this->assertSame(0, PaymentReport::count());
        Http::assertNothingSent(); Notification::assertNothingSent(); Queue::assertNothingPushed();
        $this->assertSame($notificationCount, DB::table('Notifications')->count());
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->count());
        config(['app.key' => 'synthetic-rotated-key']);
        DB::table('security_audit_events')->delete();
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => 'expired'])->assertOk()->assertExactJson($result);
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', array_replace($body, ['new_charge' => 501, 'preview_token' => 'expired']))->assertStatus(409);
        $this->assertSame(2, Invoice::count());
    }

    public function test_state_bound_preview_and_signature_and_payload_fail_closed(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $f['course']->update(['RemainingSessions' => 4]);
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertStatus(409);
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => 'forged'])->assertStatus(409);
        config(['app.key' => '']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(503);
        $this->assertSame(1, Invoice::count());
    }

    /** @dataProvider exclusions */
    public function test_exclusions_are_actionable(array $change): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $f['course']->update($change);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->feePayload($f['invoice']))->assertStatus(422)->assertJsonStructure(['message', 'code']);
    }
    public static function exclusions(): array { return [[['ClassType' => 'tutoring']], [['Charge' => 0]], [['ScheduleMode' => 'date']], [['closed_reason' => 'waived']], [['closed_reason' => 'WAIVED']], [['closed_reason' => 'waived ']], [['Paid' => 1]], [['Pay' => 500]], [['PayDate' => '2026-10-01']], [['PackageID' => 999]]]; }

    public function test_unbilled_void_only_multiple_invoices_and_active_amendments_do_not_invent_evidence(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $f['invoice']->update(['Status' => 'void']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'live_invoice_required');
        $f['invoice']->update(['Status' => 'unpaid']);
        $copy = $f['invoice']->replicate(); $copy->save();
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'ambiguous_invoice');
        $copy->delete();
        DB::table('student_class_pricing_amendments')->insert(['student_class_id' => $f['course']->ID, 'effective_from' => '2026-11-01', 'rate' => 1000, 'source_reference' => 'fixture', 'reason' => 'fixture']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'active_pricing_amendment');
    }

    public function test_pending_confirmed_and_partial_money_block_but_matched_void_history_is_allowed(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $report = $this->receiptFor($f);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422);
        $this->withToken($f['token'])->putJson('/api/v1/payment-reports/'.$report->id.'/void', ['void_reason' => 'Synthetic reversal'])->assertOk();
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk();
        Payment::where('Method', 'void')->update(['payment_report_id' => null]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
    }

    public function test_failure_after_new_invoice_rolls_back_every_write_including_audit(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $fail = true;
        Invoice::created(function () use (&$fail) { if ($fail) { $fail = false; throw new \RuntimeException('synthetic forced failure'); } });
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertStatus(500);
        $this->assertSame(1, Invoice::count()); $this->assertSame('unpaid', $f['invoice']->fresh()->Status); $this->assertSame(8000, (int) $f['course']->fresh()->Charge);
        $this->assertSame(0, DB::table('invoice_corrections')->count());
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->count());
    }

    public function test_4217_pending_8000_requires_rejection_before_agreeing_8800_without_receipt(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        $f = $this->fixture(); $f['invoice']->update(['TotalAmount' => 8000]);
        $report = $this->reportFor($f); $report->update(['reported_amount' => 8000]);
        $body = array_replace($this->feePayload($f['invoice']), ['new_charge' => 8800]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $this->withToken($f['token'])->putJson('/api/v1/payment-reports/'.$report->id.'/reject', ['rejection_note' => 'Synthetic amount requires a fresh report'])->assertOk();
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk()->json();
        $this->assertSame(8800, (int) Invoice::findOrFail($result['replacement_invoice_id'])->TotalAmount);
        $this->assertSame(8800, (int) $f['course']->fresh()->Charge);
        $this->assertSame('rejected', $report->fresh()->status);
        $this->assertSame(8000, (int) $report->fresh()->reported_amount);
        $this->assertSame(1, PaymentReport::count()); $this->assertSame(0, Payment::count());
    }

    public function test_unbilled_trial_changes_only_charge_keeps_history_and_replays_after_later_invoice(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture();
        DB::table('InvoiceItem')->delete(); $f['invoice']->delete();
        $body = ['scope' => 'unbilled', 'new_charge' => 500, 'reason' => 'Synthetic unbilled trial', 'idempotency_key' => (string) Str::uuid()];
        $before = $f['course']->fresh()->getRawOriginal();
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()
            ->assertJsonPath('original_invoice_total', null)->assertJsonPath('obligation_delta', null)->assertJsonPath('new_outstanding', null)
            ->assertJsonPath('message', '只更正約定費用，尚未開帳單，不收款。')->json('preview_token');
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk()->assertJsonPath('replacement_invoice_id', null)->json();
        $after = $f['course']->fresh()->getRawOriginal(); unset($before['Charge'], $after['Charge']); $this->assertEquals($before, $after);
        $row = DB::table('invoice_corrections')->sole(); $this->assertSame('agreed_fee_unbilled', $row->kind);
        $this->assertNull($row->original_invoice_id); $this->assertNull($row->replacement_invoice_id); $this->assertNull($row->amount);
        $this->assertSame(0, Invoice::count()); $this->assertSame(0, Payment::count()); $this->assertSame(0, PaymentReport::count());
        $this->assertTrue(StudentClass::hasOperationalHistory([$f['course']->ID]));
        $this->withToken($f['token'])->deleteJson('/api/v1/student-classes/'.$f['course']->ID)->assertStatus(422);
        Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID, 'IssueDate' => '2026-10-17', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        config(['app.key' => '']); DB::table('security_audit_events')->delete();
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => 'old'])->assertOk()->assertExactJson($result);
        $f['student']->update(['CampusID' => 2]);
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => 'old'])->assertForbidden();
    }

    public function test_invoice_only_discrepancy_uses_selected_invoice_instead_of_recomputing_rate(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $f['course']->update(['Charge' => 7200]); $f['invoice']->update(['TotalAmount' => 8800]);
        $body = array_replace($this->feePayload($f['invoice']), ['new_charge' => 7200]);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->assertJsonPath('charge_delta', 0)->assertJsonPath('obligation_delta', -1600)->json('preview_token');
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        $this->assertSame(7200, (int) Invoice::findOrFail($result->json('replacement_invoice_id'))->TotalAmount);
        $this->assertSame(1000.0, $f['course']->fresh()->Rate);
    }

    public function test_unbilled_never_accepts_voided_bills_rejected_reports_or_external_items(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture();
        $body = ['scope' => 'unbilled', 'new_charge' => 500, 'reason' => 'Synthetic', 'idempotency_key' => (string) Str::uuid()];
        $f['invoice']->update(['Status' => 'void']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'never_billed_required');
        $f['invoice']->delete(); // retained orphan/external item still points at this course
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422);
        DB::table('InvoiceItem')->delete(); $this->reportFor($f, 'rejected');
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422);
    }

    public function test_pending_reports_shared_items_and_ambiguous_reversal_provenance_block(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $report = $this->reportFor($f);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
        $report->update(['status' => 'rejected']);
        DB::table('InvoiceItem')->update(['StudentClassID' => 999]);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertStatus(422)->assertJsonPath('code', 'invoice_mismatch');
    }

    public function test_schema_unavailable_audit_failure_and_long_note_fail_safely(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $f['invoice']->update(['Note' => str_repeat('原', 255)]);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_starts_with($query->sql, 'insert into `security_audit_events`')) {
                $fail = false; throw new \RuntimeException('synthetic audit failure after insert');
            }
        });
        try {
            $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertStatus(500);
            $this->assertSame(1, Invoice::count()); $this->assertSame(0, DB::table('invoice_corrections')->count()); $this->assertSame(8000, (int) $f['course']->fresh()->Charge);
        } finally { $fail = false; }
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        $this->assertSame(str_repeat('原', 255), $f['invoice']->fresh()->Note);
    }


    /** @dataProvider singleTrialScopes */
    public function test_single_session_trial_8000_to_500_preserves_rate_and_one_session(string $scope): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture();
        $f['course']->update(['Rate' => 8000, 'SessionCount' => 1, 'RemainingSessions' => 1, 'UsedSessions' => 0]);
        $body = $this->feePayload($f['invoice']);
        if ($scope === 'unbilled') {
            DB::table('InvoiceItem')->delete(); $f['invoice']->delete();
            $body = array_replace($body, ['scope' => 'unbilled', 'original_invoice_id' => null]);
        }
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        $course = $f['course']->fresh(); $this->assertSame(500, (int) $course->Charge); $this->assertSame(8000.0, $course->Rate);
        $this->assertSame(1, (int) $course->SessionCount); $this->assertSame(1, (int) $course->RemainingSessions); $this->assertSame(0, (int) $course->UsedSessions);
        $this->assertSame(0, Payment::count()); $this->assertSame(0, PaymentReport::count());
    }
    public static function singleTrialScopes(): array { return [['billed'], ['unbilled']]; }

    public function test_teacher_and_unauthenticated_cannot_preview(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $this->postJson($this->path($f).'/preview', $body)->assertUnauthorized();
        $f['user']->update(['type' => 'T']);
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertForbidden();
    }

    public function test_4217_preview_replay_and_audit_bind_the_authenticated_director(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]);
        $f = $this->fixture(); $other = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $apply = $body + ['preview_token' => $preview];
        $this->withToken($other['token'])->postJson($this->path($f).'/apply', $apply)->assertStatus(409);
        $result = $this->withToken($f['token'])->postJson($this->path($f).'/apply', $apply)->assertOk()->json();
        $this->assertSame((int) $f['user']->id, (int) DB::table('invoice_corrections')->where('id', $result['correction_id'])->value('operator_user_id'));
        $this->assertSame(\App\Models\SecurityAuditEvent::ref('user', $f['user']->id), DB::table('security_audit_events')->where('event_type', 'billing.agreed_fee_corrected')->value('actor_ref'));
        $this->withToken($other['token'])->postJson($this->path($f).'/apply', $apply)->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $apply)->assertOk()->assertExactJson($result);
    }

    public function test_domain_history_blocks_destructive_rollback(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture(); $body = $this->feePayload($f['invoice']);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        // No committed ALTER migration in this PR — assert the same refuse rule via test helper.
        try {
            AgreedFeeTestSchema::refuseDestructiveRollback();
            $this->fail('History must prevent destructive rollback');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Retain agreed-fee correction history', $e->getMessage());
        }
        $this->assertSame(1, DB::table('invoice_corrections')->count());
        $this->assertTrue(Schema::hasColumn('invoice_corrections', 'old_charge'));
    }


    public function test_regular_eight_session_fee_7200_preserves_five_used_three_future_and_deductions(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture();
        $f['course']->update(['ClassType' => 'one_on_one', 'Charge' => 8800, 'Rate' => 1100, 'UsedSessions' => 5, 'RemainingSessions' => 3]);
        $f['invoice']->update(['TotalAmount' => 8800]);
        foreach (range(1, 8) as $n) {
            $session = ClassSession::create(['StudentClassID' => $f['course']->ID, 'SessionDate' => '2026-'.($n <= 5 ? '09' : '11').'-0'.$n,
                'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $n <= 5 ? 'attended' : 'scheduled']);
            if ($n <= 5) DB::table('session_deduction_ledger')->insert(['student_class_id' => $f['course']->ID, 'class_session_id' => $session->ID,
                'event_type' => 'deduct', 'source' => 'attendance', 'minutes' => 120]);
        }
        $beforeCourse = $f['course']->fresh()->getRawOriginal();
        $sessions = DB::table('ClassSession')->get()->toArray(); $ledger = DB::table('session_deduction_ledger')->get()->toArray();
        $body = array_replace($this->feePayload($f['invoice']), ['new_charge' => 7200]);
        $preview = $this->withToken($f['token'])->postJson($this->path($f).'/preview', $body)->assertOk()->json('preview_token');
        $this->withToken($f['token'])->postJson($this->path($f).'/apply', $body + ['preview_token' => $preview])->assertOk();
        $afterCourse = $f['course']->fresh()->getRawOriginal(); unset($beforeCourse['Charge'], $afterCourse['Charge']);
        $this->assertEquals($beforeCourse, $afterCourse); $this->assertSame(7200, (int) $f['course']->fresh()->Charge);
        $this->assertEquals($sessions, DB::table('ClassSession')->get()->toArray()); $this->assertEquals($ledger, DB::table('session_deduction_ledger')->get()->toArray());
    }


    public function test_unmatched_zero_net_void_history_blocks_without_changing_legacy_guard_scope(): void
    {
        config(['billing.agreed_fee_correction_enabled' => true]); $f = $this->fixture();
        $historical = Invoice::create(['StudentID' => $f['student']->id, 'StudentClassID' => $f['course']->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => 500, 'PaidAmount' => 0, 'Status' => 'void']);
        foreach ([500, -500] as $amount) Payment::create(['InvoiceID' => $historical->id, 'Amount' => $amount, 'PaidAt' => '2026-09-01', 'Method' => $amount > 0 ? 'cash' : 'void']);
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection($f['course']->ID));
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection($f['course']->ID, true));
        $this->withToken($f['token'])->postJson($this->path($f).'/preview', $this->feePayload($f['invoice']))->assertStatus(422)->assertJsonPath('code', 'payment_evidence_blocks');
    }

}

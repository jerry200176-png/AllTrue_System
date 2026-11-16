<?php

namespace Tests\Feature;

use App\Models\{Campus, Invoice, Payment, PaymentReport, StudentClass};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** #535: the paid-credit writer must conserve value without settling the existing B debt. */
class SubjectCreditTransferTest extends TestCase
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

    public function test_partial_transfer_creates_a_separate_manual_b_batch_and_preserves_existing_debt_and_cash(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $debt = Invoice::where('StudentClassID', $template->ID)->first()->getAttributes();
        $cash = Payment::where('InvoiceID', $invoice->id)->first()->getAttributes();
        $history = DB::table('ClassSession')->get()->toJson();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $new = StudentClass::findOrFail($r->json('transfer.target_course_id'));
        $this->assertSame([(int) $source->StudentID, (int) $template->SubjectID, 2, 3000, 'manual_occurrence'],
            [(int) $new->StudentID, (int) $new->SubjectID, (int) $new->SessionCount, (int) $new->Charge, $new->scheduling_policy]);
        $this->assertSame([6, 9000], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge]);
        $this->assertSame($debt, Invoice::find($debt['id'])->getAttributes());
        $this->assertSame($cash, Payment::find($cash['id'])->getAttributes());
        $this->assertSame($history, DB::table('ClassSession')->get()->toJson());
        $this->assertSame(12000, (int) Payment::sum('Amount'));
        $this->assertSame(0, (int) Payment::whereIn('Method', ['transfer_in', 'transfer_out'])->sum('Amount'));
        $this->assertDatabaseCount('contract_subject_credit_transfers', 1);
    }

    public function test_exact_replay_is_one_operation_and_changed_key_parameters_conflict(): void
    {
        [$token, $source, $template] = $this->fixture();
        $payload = $this->payload($token, $source, $template);
        $first = $this->send($token, $source, $payload)->assertCreated();
        $snapshot = $this->businessSnapshot();
        $this->send($token, $source, $payload)->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('transfer.id', $first->json('transfer.id'));
        $this->assertSame($snapshot, $this->businessSnapshot());
        $this->send($token, $source, array_replace($payload, ['sessions' => 3]))->assertStatus(409)->assertJsonPath('code', 'subject_credit_key_reused');
    }

    public function test_multiple_partial_moves_from_the_original_source_are_audited_and_conserved(): void
    {
        [$token, $source, $template] = $this->fixture();
        foreach ([2, 1] as $n) {
            $this->send($token, $source, $this->payload($token, $source, $template, $n))->assertCreated();
        }
        $this->assertSame([5, 7500], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge]);
        $this->assertDatabaseCount('contract_subject_credit_transfers', 2);
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    public function test_confirmation_binds_quantity_and_current_reservations(): void
    {
        [$token, $source, $template] = $this->fixture();
        $payload = $this->payload($token, $source, $template);
        $this->send($token, $source, array_replace($payload, ['sessions' => 3]))->assertStatus(409)->assertJsonPath('code', 'subject_credit_preview_changed');
        $this->appendClassSession($source, '2026-10-14', 'scheduled');
        $before = $this->businessSnapshot();
        $this->send($token, $source, $payload)->assertStatus(409)->assertJsonPath('code', 'subject_credit_preview_changed');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_write_role_campus_and_empty_scope_cannot_be_bypassed_with_a_valid_preview(): void
    {
        [$token, $source, $template] = $this->fixture();
        $payload = $this->payload($token, $source, $template);
        $campus = (int) \App\Models\Student::find($source->StudentID)->CampusID;
        $before = $this->businessSnapshot();
        $this->send('', $source, $payload)->assertStatus(401);
        $this->send($this->token($campus, false), $source, $payload)->assertStatus(403);
        $this->send($this->token((int) Campus::factory()->create()->id), $source, $payload)->assertStatus(403);
        $this->send($this->token(null), $source, $payload)->assertStatus(403);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('contract_subject_credit_transfers', 0);
    }

    public function test_write_cannot_repair_stored_counter_drift_or_accept_a_missing_schema(): void
    {
        [$token, $source, $template] = $this->fixture();
        $source->forceFill(['UsedSessions' => 0, 'RemainingSessions' => 8])->save();
        $payload = $this->payload($token, $source, $template);
        $before = $this->businessSnapshot();
        $this->send($token, $source, $payload)->assertStatus(409)->assertJsonPath('code', 'subject_credit_counters_require_review');
        $this->assertSame($before, $this->businessSnapshot());
        \Illuminate\Support\Facades\Schema::partialMock()->shouldReceive('hasTable')->with('contract_subject_credit_transfers')->andReturn(false);
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", ['target_course_id' => $template->ID, 'sessions' => 2])
            ->assertOk()->assertJsonPath('execution_available', false);
        $this->send($token, $source, $payload)->assertStatus(503)->assertJsonPath('code', 'subject_credit_schema_unready');
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/1/reverse", ['reason' => 'not ready'])
            ->assertStatus(503)->assertJsonPath('code', 'subject_credit_schema_unready');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_source_remaining_reservations_survive_and_all_three_contract_references_are_protected(): void
    {
        [$token, $source, $template] = $this->fixture();
        foreach (['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28'] as $day) {
            $this->appendClassSession($source, $day, 'scheduled');
        }
        DB::table('schedules')->insert(['student_id' => $source->StudentID, 'student_course_id' => $source->ID,
            'branch_id' => \App\Models\Student::find($source->StudentID)->CampusID, 'day_of_week' => 3,
            'schedule_date' => '2026-10-14', 'start_time' => '08:00:00', 'end_time' => '09:00:00', 'status' => 'scheduled', 'type' => 'normal']);
        $history = DB::table('ClassSession')->get()->toJson();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $this->assertSame($history, DB::table('ClassSession')->get()->toJson());
        $this->assertSame(0, (int) $source->fresh()->Stop);
        foreach ([$source->ID, $template->ID, $r->json('transfer.target_course_id')] as $id) {
            $this->assertContains('contract_subject_credit_transfers', StudentClass::operationalHistoryReasons([(int) $id]));
        }
    }

    public function test_unused_source_can_be_fully_moved_without_deleting_original_contract_or_payment(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture(used: 0);
        $report = $this->receipt($source, $invoice);
        $this->send($token, $source, $this->payload($token, $source, $template, 8))->assertCreated();
        $this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->assertJsonPath('session_count', 8);
        // A later canonical recount must preserve the explicit financial close; it must not reactivate zero credit.
        \App\Services\SessionDeductionService::recomputeCounters((int) $source->ID);
        $source->refresh();
        $this->assertSame([0, 0, 1, 'completed'], [(int) $source->SessionCount, (int) $source->Charge, (int) $source->Stop, $source->closed_reason]);
        $this->assertSame(12000, (int) Payment::where('Method', 'cash')->sum('Amount'));
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    public function test_a_failed_incoming_payment_rolls_back_every_business_write(): void
    {
        [$token, $source, $template] = $this->fixture();
        $payload = $this->payload($token, $source, $template);
        $before = $this->businessSnapshot();
        $fail = true;
        Payment::creating(function (Payment $p) use (&$fail): void {
            if ($fail && $p->Method === 'transfer_in') {
                throw new \RuntimeException('isolated incoming-payment failure');
            }
        });
        try {
            $this->send($token, $source, $payload)->assertStatus(500);
        } finally {
            $fail = false;
        }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('contract_subject_credit_transfers', 0);
    }

    /** in-app #563: a paid bill issued without item lines moves and reverses on its header totals. */
    public function test_a_bill_without_item_lines_transfers_and_reverses_on_its_header(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        \App\Models\InvoiceItem::where('InvoiceID', $invoice->id)->delete();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $this->assertSame([9000, 9000], [(int) $invoice->fresh()->TotalAmount, (int) $invoice->fresh()->PaidAmount]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Taipei'));
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse", ['reason' => 'header-only bill reversal'])->assertOk();
        $this->assertSame([12000, 12000], [(int) $invoice->fresh()->TotalAmount, (int) $invoice->fresh()->PaidAmount]);
        $this->assertSame([8, 12000], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge]);
        $this->assertSame(0, \App\Models\InvoiceItem::where('InvoiceID', $invoice->id)->count());
    }

    public function test_reverse_is_compensating_idempotent_and_refuses_a_reserved_target(): void
    {
        [$token, $source, $template] = $this->fixture();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse";
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00', 'Asia/Taipei'));
        $this->withToken($token)->postJson($url, ['reason' => 'isolated reversal'])->assertOk();
        $this->assertSame('2026-09-01', app(\App\Services\BillingPayableResolver::class)->lastPaidAtByStudentClassIds([(int) $source->ID])[(int) $source->ID]);
        $after = $this->businessSnapshot();
        $this->withToken($token)->postJson($url, ['reason' => 'isolated reversal'])->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($after, $this->businessSnapshot());
        $this->assertSame([8, 12000], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge]);
        $this->assertSame(12000, (int) Payment::sum('Amount'));

        [$token2, $source2, $template2] = $this->fixture();
        $r2 = $this->send($token2, $source2, $this->payload($token2, $source2, $template2))->assertCreated();
        $this->appendClassSession(StudentClass::findOrFail($r2->json('transfer.target_course_id')), '2026-10-14', 'scheduled');
        $this->withToken($token2)->postJson("/api/v1/student-classes/{$source2->ID}/subject-credit-transfer/{$r2->json('transfer.id')}/reverse", ['reason' => 'must refuse'])->assertStatus(409);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invoiceLinks')]
    public function test_original_receipt_cannot_remove_the_funds_already_carried_to_another_subject(bool $unlinked): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $report = $this->receipt($source, $invoice);
        if ($unlinked) {
            $report->forceFill(['payment_id' => null])->save();
            Payment::where('InvoiceID', $invoice->id)->update(['payment_report_id' => null]);
        }
        $printed = $this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->json();
        $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $afterPrint = $this->withToken($token)->getJson("/api/v1/payment-reports/{$report->id}/receipt")->assertOk()->json();
        foreach (['receipt_no', 'amount', 'payment_date', 'session_count', 'subject', 'class_type', 'schedule_mode', 'session_dates'] as $field) {
            $this->assertSame($printed[$field], $afterPrint[$field], 'Issued receipt field stays original: '.$field);
        }
        $before = $this->businessSnapshot();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => 'isolated original receipt correction'])
            ->assertStatus(409)->assertJsonPath('code', 'void_transferred_credit');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_receipt_void_within_untransferred_funds_remains_available(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $cash = Payment::where('InvoiceID', $invoice->id)->first();
        $cash->forceFill(['Amount' => 6000])->save();
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 6000, 'PaidAt' => '2026-09-01', 'Method' => 'cash']);
        $report = $this->receipt($source, $invoice);
        $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => 'isolated partial correction'])->assertOk();
        $this->assertSame(3000, (int) Payment::where('InvoiceID', $invoice->id)->sum('Amount'));
        $this->assertSame(6000, (int) Payment::sum('Amount'));
    }

    public function test_legacy_receipt_without_invoice_header_cannot_bypass_the_transferred_credit_guard(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $report = $this->receipt($source, $invoice);
        $report->forceFill(['InvoiceID' => null])->save();
        $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $before = $this->businessSnapshot();
        $this->withToken($token)->putJson("/api/v1/payment-reports/{$report->id}/void", ['void_reason' => 'isolated legacy correction'])
            ->assertStatus(409)->assertJsonPath('code', 'void_transferred_credit');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_ledger_preserves_receipt_allocation_and_describes_credit_as_non_cash_movement(): void
    {
        [$token, $source, $template, $invoice] = $this->fixture();
        $report = $this->receipt($source, $invoice);
        $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $r = $this->withToken($token)->getJson("/api/v1/accounting/ledger?student_id={$source->StudentID}")->assertOk();
        $row = collect($r->json('invoices'))->firstWhere('id', (int) $invoice->id);
        $cash = collect($row['payments'])->firstWhere('report_id', (int) $report->id);
        $out = collect($row['payments'])->firstWhere('method', 'transfer_out');
        $this->assertSame('applied', $cash['application_status']);
        $this->assertSame(0, $cash['unapplied_amount']);
        $this->assertSame(3000, $row['transferred_amount']);
        $this->assertFalse($out['is_void']);
        $this->assertSame('transferred', $out['application_status']);
    }

    public function test_missing_audit_or_failed_compensating_payment_rolls_back_the_whole_operation(): void
    {
        [$token, $source, $template] = $this->fixture(used: 0);
        $input = $this->payload($token, $source, $template, 8);
        $before = $this->businessSnapshot();
        $fail = true;
        \App\Models\SubjectCreditTransfer::creating(function () use (&$fail): void {
            if ($fail) throw new \RuntimeException('isolated audit failure');
        });
        try { $this->send($token, $source, $input)->assertStatus(500); } finally { $fail = false; }
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('contract_subject_credit_transfers', 0);
        $r = $this->send($token, $source, $input)->assertCreated();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse";
        $after = $this->businessSnapshot();
        $fail = true;
        Payment::creating(function (Payment $p) use (&$fail): void {
            if ($fail && $p->Method === 'transfer_reversal_in') throw new \RuntimeException('isolated reversal failure');
        });
        try { $this->withToken($token)->postJson($url, ['reason' => 'restore unused credit'])->assertStatus(500); } finally { $fail = false; }
        $this->assertSame($after, $this->businessSnapshot());
        $this->assertNull(DB::table('contract_subject_credit_transfers')->value('reversed_at'));
        $this->withToken($token)->postJson($url, ['reason' => 'restore unused credit'])->assertOk();
        $this->assertSame([8, 12000, 0, null], [(int) $source->fresh()->SessionCount, (int) $source->fresh()->Charge,
            (int) $source->fresh()->Stop, $source->fresh()->closed_reason]);
        $this->send($token, $source, $this->payload($token, $source, $template, 1))->assertCreated();
    }

    public function test_reversal_checks_roles_scope_drift_and_reason_before_writing(): void
    {
        [$token, $source, $template] = $this->fixture();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $url = "/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse";
        $body = ['reason' => 'restore unused credit'];
        $before = $this->businessSnapshot();
        $this->withToken('')->postJson($url, $body)->assertStatus(401);
        $campus = (int) \App\Models\Student::find($source->StudentID)->CampusID;
        foreach ([$this->token($campus, false), $this->token(null), $this->token((int) Campus::factory()->create()->id)] as $actor) {
            $this->withToken($actor)->postJson($url, $body)->assertStatus(403);
        }
        $this->withToken($token)->postJson($url, ['reason' => ''])->assertStatus(422);
        $this->assertSame($before, $this->businessSnapshot());
        DB::table('StudentClass')->where('ID', $r->json('transfer.target_course_id'))->update(['Charge' => 2999]);
        $changed = $this->businessSnapshot();
        $this->withToken($token)->postJson($url, $body)->assertStatus(409)->assertJsonPath('code', 'subject_credit_reverse_changed');
        $this->assertSame($changed, $this->businessSnapshot());
    }

    public function test_refund_uses_only_retained_credit_and_target_refund_disables_reversal(): void
    {
        [$token, $source, $template] = $this->fixture();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $this->withToken($token)->getJson("/api/v1/student-classes/{$source->ID}/refund/preview")->assertOk()
            ->assertJsonPath('paid', 9000)->assertJsonPath('refundable', 6000);
        $before = $this->businessSnapshot();
        $body = ['amount' => 6001, 'method' => 'cash', 'date' => '2026-09-30', 'reason' => 'isolated refund'];
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/refund", $body)->assertStatus(422);
        $this->assertSame($before, $this->businessSnapshot());
        $target = $r->json('transfer.target_course_id');
        $this->withToken($token)->postJson("/api/v1/student-classes/{$target}/refund", array_replace($body, ['amount' => 3000]))->assertOk();
        $after = $this->businessSnapshot();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse",
            ['reason' => 'must not reclaim refunded credit'])->assertStatus(409);
        $this->assertSame($after, $this->businessSnapshot());
        $this->assertSame(9000, (int) Payment::sum('Amount'));
    }

    public function test_unprojected_dated_reservations_block_source_movement_and_target_reversal(): void
    {
        [$token, $source, $template] = $this->fixture();
        $input = $this->payload($token, $source, $template);
        $campus = (int) \App\Models\Student::find($source->StudentID)->CampusID;
        $schedule = ['student_id' => $source->StudentID, 'student_course_id' => $source->ID, 'branch_id' => $campus,
            'schedule_date' => '2026-10-14', 'day_of_week' => 3, 'start_time' => '18:00', 'end_time' => '20:00',
            'status' => 'scheduled', 'type' => 'normal'];
        $id = DB::table('schedules')->insertGetId($schedule);
        $before = $this->businessSnapshot();
        $this->send($token, $source, $input)->assertStatus(422)->assertJsonPath('code', 'subject_credit_projection_pending');
        $this->assertSame($before, $this->businessSnapshot());
        DB::table('schedules')->where('id', $id)->update(['status' => 'cancelled']);
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        DB::table('schedules')->insert(array_replace($schedule, ['student_course_id' => $r->json('transfer.target_course_id'), 'type' => 'extra', 'status' => 'rescheduled']));
        $after = $this->businessSnapshot();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse",
            ['reason' => 'must retain pending reservation'])->assertStatus(409)->assertJsonPath('code', 'subject_credit_reverse_used');
        $this->assertSame($after, $this->businessSnapshot());
    }

    public function test_operational_test_campus_is_still_hidden_when_the_mutex_uses_an_explicit_campus_read(): void
    {
        [$token, $source, $template] = $this->fixture();
        $input = $this->payload($token, $source, $template);
        $campus = (int) \App\Models\Student::find($source->StudentID)->CampusID;
        DB::table('Campus')->where('id', $campus)->update(['is_test' => 1]);
        $before = $this->businessSnapshot();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertStatus(403);
        $this->send($token, $source, $input)->assertStatus(403);
        try {
            app(\App\Services\Billing\SubjectCreditPreview::class)->run($source, $input, null); // Same unrestricted campus scope as super_admin.
            $this->fail('Super-admin campus exception must not expose the operational test campus.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_exhausted_credit_does_not_freeze_cancellation_or_same_unit_attendance_correction(): void
    {
        [$token, $source, $template] = $this->fixture();
        $this->send($token, $source, $this->payload($token, $source, $template, 6))->assertCreated();
        $session = \App\Models\ClassSession::where('StudentClassID', $source->ID)->orderBy('id')->first();
        DB::transaction(fn () => $session->forceFill(['Status' => 'late'])->save());
        $this->assertSame('late', $session->fresh()->Status);
        DB::transaction(fn () => $session->forceFill(['Status' => 'cancelled'])->save());
        $this->assertSame('cancelled', $session->fresh()->Status);
        $id = DB::table('schedules')->insertGetId(['student_id' => $source->StudentID, 'student_course_id' => $source->ID,
            'branch_id' => \App\Models\Student::find($source->StudentID)->CampusID, 'day_of_week' => 2,
            'schedule_date' => '2026-09-01', 'start_time' => '08:00', 'end_time' => '09:00', 'status' => 'scheduled', 'type' => 'normal']);
        $booking = \App\Models\Schedule::findOrFail($id);
        DB::transaction(fn () => $booking->forceFill(['status' => 'cancelled'])->save());
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(12000, (int) Payment::sum('Amount'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invoiceLinks')]
    public function test_reversal_refuses_any_later_paid_invoice_linked_by_header_or_item(bool $itemOnly): void
    {
        [$token, $source, $template] = $this->fixture();
        $r = $this->send($token, $source, $this->payload($token, $source, $template))->assertCreated();
        $targetId = (int) $r->json('transfer.target_course_id');
        $extra = $this->withToken($token)->postJson('/api/v1/invoices', ['StudentID' => $source->StudentID,
            'StudentClassID' => $itemOnly ? $template->ID : $targetId, 'IssueDate' => '2026-10-01', 'TotalAmount' => 500,
            'Items' => [['Description' => 'isolated later bill', 'Amount' => 500, 'StudentClassID' => $targetId]]])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/invoices/'.$extra->json('id').'/payments', ['Amount' => 500, 'PaidAt' => '2026-09-30', 'Method' => 'cash'])->assertOk();
        $before = $this->businessSnapshot();
        $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/{$r->json('transfer.id')}/reverse",
            ['reason' => 'must retain later paid funds'])->assertStatus(409)->assertJsonPath('code', 'subject_credit_reverse_changed');
        $this->assertSame($before, $this->businessSnapshot());
    }

    public static function invoiceLinks(): array { return [[false], [true]]; }

    private function receipt(StudentClass $source, Invoice $invoice): PaymentReport
    {
        $cash = Payment::where('InvoiceID', $invoice->id)->orderBy('id')->first();
        $report = PaymentReport::create(['StudentID' => $source->StudentID, 'StudentClassID' => $source->ID, 'InvoiceID' => $invoice->id,
            'reported_by_name' => 'isolated payer', 'payment_date' => '2026-09-01', 'payment_method' => 'cash',
            'reported_amount' => $cash->Amount, 'status' => 'confirmed', 'confirmed_at' => now(), 'payment_id' => $cash->id,
            'report_token_hash' => hash('sha256', 'isolated receipt'), 'token_expires_at' => now()->addDay()]);
        $cash->forceFill(['payment_report_id' => $report->id])->save();

        return $report;
    }

    private function payload(string $token, StudentClass $source, StudentClass $target, int $sessions = 2): array
    {
        $input = ['target_course_id' => $target->ID, 'sessions' => $sessions];
        $preview = $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer/preview", $input)->assertOk();

        return $input + ['state_hash' => $preview->json('state_hash'), 'reason' => 'isolated same-unit transfer', 'idempotency_key' => bin2hex(random_bytes(12))];
    }

    private function send(string $token, StudentClass $source, array $input)
    {
        return $this->withToken($token)->postJson("/api/v1/student-classes/{$source->ID}/subject-credit-transfer", $input);
    }
}

<?php

namespace App\Operations\Strategies;

use App\Http\Controllers\BillingController;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\ContractAmendmentService;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\SessionDeductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Exact-case POP strategy (in-app #594, GitHub #5617, Founder 2026-11-25): one stopped count contract keeps only the lessons
 * it used. The manifest's past, never-attended `scheduled` lessons become `cancelled`; the contract goes from `from_count` to
 * `to_count` lessons, remaining 0, Charge = Rate x to_count (ContractAmendmentService::chargeForCount) and closed_reason
 * contract_amended; its one header-only bill follows the new Charge, and the bill status and course Paid flag come from the
 * canonical projection. Taught lessons, payments, reports and receipts are read, never written. Amounts come from the row.
 * No rollback(): POP cannot reach one for this strategy (not native-recovery, not co-committed). The execute snapshot keeps
 * the before values; undoing is a new Founder-reviewed operation (docs/incidents/RM-UNUSED-LESSONS-CLOSEOUT-5617.md).
 */
final class UnusedLessonsContractCloseoutStrategy
{
    /** @var array<string,array{course_id:int,campus_id:int,invoice_id:int,from_count:int,to_count:int,lessons:array<int,string>}> */
    public const MANIFESTS = [
        // Read-only triage db 2026-11-25 (#5617): tail lessons created 2026-11-23, no sign-in, learning record or ledger row.
        'closeout-unused-lessons-5617-20261125' => ['course_id' => 2201, 'campus_id' => 17, 'invoice_id' => 2821,
            'from_count' => 8, 'to_count' => 2, 'lessons' => [50637 => '2026-10-13', 50638 => '2026-10-20',
                50639 => '2026-10-27', 50640 => '2026-11-03', 50641 => '2026-11-10', 50642 => '2026-11-17']],
    ];

    private const EVENT = 'pop.unused_lessons_contract_closeout';

    public function plan(array $parameters): array
    {
        $reference = (string) ($parameters['decision_reference'] ?? '');
        $m = self::MANIFESTS[$reference] ?? null;
        if ($m === null) {
            return ['ok' => false, 'errors' => ['case_parameters_mismatch'], 'state' => 'unknown', 'snapshot' => []];
        }
        $live = $this->inspect($m);
        $ok = $live['errors'] === [];

        return ['ok' => $ok, 'errors' => $live['errors'], 'state' => $live['state'], 'reference' => $reference,
            'counts' => $live['counts'] + ['to_cancel' => $live['state'] === 'before' ? count($m['lessons']) : 0],
            'digest' => hash('sha256', json_encode([$live['state'], $live['facts']], JSON_THROW_ON_ERROR)),
            'snapshot' => $ok && $live['state'] === 'before' ? ['reference' => $reference] + $live['snapshot'] : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (!($plan['ok'] ?? false)) {
            throw new RuntimeException('unused_lessons_closeout_plan_not_ready');
        }
        if (($plan['state'] ?? null) === 'after') {
            // Applied before this request (or its record was lost): no before-values to claim, so no rollback authority.
            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => []];
        }
        $reference = (string) ($plan['reference'] ?? '');
        $m = self::MANIFESTS[$reference] ?? null;
        if ($m === null || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('unused_lessons_closeout_plan_not_ready');
        }

        return DB::transaction(function () use ($m, $reference, $context): array {
            $this->lock($m);
            $live = $this->inspect($m);
            if ($live['errors'] !== [] || $live['state'] !== 'before') {
                throw new RuntimeException('unused_lessons_closeout_drift:' . implode(',', array_slice($live['errors'] ?: ['not_before'], 0, 20)));
            }
            $snapshot = ['reference' => $reference] + $live['snapshot'];
            [$oldCharge, $newCharge] = $live['charges'];
            // Query builder on purpose, as PastScheduledSessionsCancelStrategy and the charge repairs: the ClassSession model
            // guards and observer (slot, mutability, artifact void, schedule log) have nothing to do on zero-activity rows,
            // and the StudentClass saved hook only resets the settlement-lock cache, which is called explicitly below.
            foreach (array_keys($m['lessons']) as $id) {
                if (DB::table('ClassSession')->where('id', $id)->where('StudentClassID', $m['course_id'])->where('Status', 'scheduled')
                    ->update(['Status' => 'cancelled', 'updated_at' => now()]) !== 1) {
                    throw new RuntimeException("unused_lessons_closeout_lesson_{$id}");
                }
            }
            if (DB::table('StudentClass')->where('ID', $m['course_id'])->where('SessionCount', $m['from_count'])->where('Charge', $oldCharge)
                ->update(['SessionCount' => $m['to_count'], 'RemainingSessions' => 0, 'Charge' => $newCharge,
                    'closed_reason' => ContractAmendmentService::CLOSED_REASON]) !== 1) {
                throw new RuntimeException('unused_lessons_closeout_course');
            }
            ClassSession::resetSettlementLockCache();
            if (DB::table('Invoice')->where('id', $m['invoice_id'])->where('TotalAmount', $oldCharge)
                ->update(['TotalAmount' => $newCharge, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('unused_lessons_closeout_invoice');
            }
            // Same money tail as PaidInvoiceChargeAlignmentStrategy::writeCorrection.
            $invoice = Invoice::findOrFail($m['invoice_id']);
            $projection = app(InvoiceAmountReconciliationService::class)->resolve($invoice);
            if ($projection['outstanding_amount'] !== 0 || $projection['overpaid_amount'] !== 0) {
                throw new RuntimeException('unused_lessons_closeout_money_projection');
            }
            $invoice->Status = Invoice::statusFromProjection($projection);
            $invoice->save();
            BillingController::syncStudentClassPaidFromInvoice($invoice);
            if ((int) StudentClass::findOrFail($m['course_id'])->Paid !== 1) {
                throw new RuntimeException('unused_lessons_closeout_settlement_projection');
            }
            $check = $this->inspect($m);
            if ($check['state'] !== 'after' || $check['errors'] !== []) {
                throw new RuntimeException('unused_lessons_closeout_not_applied');
            }
            $this->audit($m, $reference, (string) ($context['operation_id'] ?? ''));

            return ['ok' => true, 'updated' => count($m['lessons']), 'snapshot' => $snapshot];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $m = self::MANIFESTS[(string) ($plan['reference'] ?? '')] ?? null;
        if ($m === null) {
            $errors[] = 'manifest_missing';
        } else {
            $live = $this->inspect($m);
            if ($live['state'] !== 'after') $errors[] = 'closeout_not_applied';
            array_push($errors, ...$live['errors']);
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'checks' => ['lessons_cancelled', 'contract_closed_at_used_lessons', 'invoice_paid_at_new_charge', 'payments_reports_untouched']];
    }

    /** Course row first (attendance's own order), then the lessons, then the bill. */
    private function lock(array $m): void
    {
        DB::table('StudentClass')->where('ID', $m['course_id'])->lockForUpdate()->first(['ID']);
        DB::table('ClassSession')->whereIn('id', array_keys($m['lessons']))->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('Invoice')->where('id', $m['invoice_id'])->lockForUpdate()->first(['id']);
    }

    /**
     * Live facts with error codes. `common` must hold both before and after; the state is `before` or `after`
     * when every state-specific check of that side holds, else `drift` (its before-misses are reported).
     *
     * @return array{state:string,errors:list<string>,counts:array<string,int>,facts:array,snapshot:array,charges:array{int,int}}
     */
    private function inspect(array $m): array
    {
        [$cid, $iid, $from, $to] = [$m['course_id'], $m['invoice_id'], $m['from_count'], $m['to_count']];
        $c = DB::table('StudentClass')->where('ID', $cid)->first();
        if (!$c) {
            return ['state' => 'missing', 'errors' => ['course_missing'], 'counts' => [],
                'facts' => [], 'snapshot' => [], 'charges' => [0, 0]];
        }
        $common = [];
        $before = [];
        $after = [];
        $base = fn (int $n): int => (int) round((float) ($c->Rate ?? 0) * $n);
        $unit = strtolower(trim((string) ($c->rate_unit ?? 'session')));
        if ($unit !== 'session' && $unit !== '') $common[] = 'rate_unit';
        if ($base($to) <= 0) $common[] = 'rate_missing';
        if ((int) DB::table('Student')->where('id', $c->StudentID)->value('CampusID') !== $m['campus_id']) $common[] = 'campus_mismatch';
        if ((string) $c->ScheduleMode !== 'count') $common[] = 'schedule_mode';
        if ((int) ($c->PackageID ?? 0) > 0) $common[] = 'package';
        if ((int) $c->Stop !== 1) $common[] = 'not_stopped';
        if (!empty($c->settlement_locked_at)) $common[] = 'settlement_locked';
        $diagnostic = SessionDeductionService::batchExpectedUsedSessionDiagnostics([$cid])[$cid] ?? [];
        $used = max((int) ($diagnostic['expected_used'] ?? 0), (int) ($diagnostic['uncapped_used'] ?? 0));
        if ($used !== $to) $common[] = 'ledger_used_mismatch';
        if ((int) $c->UsedSessions !== $to) $common[] = 'used_sessions_mismatch';
        if ((int) $c->Pay !== 0) $common[] = 'pay_flag';
        if (DB::table('student_class_pricing_amendments')->where('student_class_id', $cid)->whereNull('voided_at')->exists()) $common[] = 'pricing_amendment_active';
        if (DB::table('ClassSession')->where('StudentClassID', $cid)->whereNotNull('session_charge')->exists()) $common[] = 'session_charge_adjustments';
        // Every competing money writer (PaidInvoiceChargeAlignmentStrategy::capture) leaves one of these rows.
        if (DB::table('invoice_corrections')->where(fn ($q) => $q->where('student_class_id', $cid)->orWhere('original_invoice_id', $iid)->orWhere('replacement_invoice_id', $iid))->exists()) $common[] = 'invoice_correction_present';
        foreach (['session_entitlement_transfers', 'contract_subject_credit_transfers'] as $table) {
            if (DB::table($table)->where(fn ($q) => $q->where('source_student_class_id', $cid)->orWhere('target_student_class_id', $cid))->exists()) $common[] = "{$table}_present";
        }
        // Bills of the contract by anchor or by item link (a null-anchor bill can carry course items).
        $invoiceIds = DB::table('Invoice')->where('StudentClassID', $cid)
            ->orWhereIn('id', DB::table('InvoiceItem')->where('StudentClassID', $cid)->select('InvoiceID'))->pluck('id')->all();
        $liveIds = DB::table('Invoice')->whereIn('id', $invoiceIds)->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($liveIds !== [$iid]) $common[] = 'invoice_scope';
        $i = DB::table('Invoice')->where('id', $iid)->first();
        if (!$i || (int) $i->StudentClassID !== $cid || (int) $i->StudentID !== (int) $c->StudentID) $common[] = 'invoice_owner';
        if (DB::table('InvoiceItem')->where(fn ($q) => $q->where('InvoiceID', $iid)->orWhere('StudentClassID', $cid))->exists()) $common[] = 'invoice_items';
        if ($i && !empty($i->billing_period)) $common[] = 'billing_period';
        if ($i && !empty($i->zero_amount_reason)) $common[] = 'zero_amount_reason';
        if (!$i || (int) $i->PaidAmount !== $base($to)) $common[] = 'invoice_paid_amount';
        $net = $i ? (int) app(InvoiceAmountReconciliationService::class)->resolve(Invoice::findOrFail($iid))['net_applied'] : -1;
        if ($net !== $base($to)) $common[] = 'invoice_net_applied';
        if (DB::table('payment_reports')->where(fn ($q) => $q->where('StudentClassID', $cid)->orWhere('InvoiceID', $iid))->where('status', 'pending')->exists()) $common[] = 'payment_report_pending';

        $today = now()->toDateString();
        $rows = DB::table('ClassSession')->whereIn('id', array_keys($m['lessons']))->get()->keyBy('id');
        $lessons = [];
        foreach ($m['lessons'] as $id => $date) {
            $s = $rows->get($id);
            if (!$s) {
                $common[] = "lesson_{$id}_missing";
                continue;
            }
            if ((int) $s->StudentClassID !== $cid || substr((string) $s->SessionDate, 0, 10) !== $date) $common[] = "lesson_{$id}_identity";
            if ($date >= $today) $common[] = "lesson_{$id}_not_past";
            $activity = DB::table('StudentSingIn')->where('ClassSessionID', $id)->count()
                + DB::table('LearningRecord')->where('ClassSessionID', $id)->count()
                + DB::table('session_deduction_ledger')->where('class_session_id', $id)->count();
            if ($activity !== 0) $common[] = "lesson_{$id}_activity";
            $lessons[$id] = ['status' => (string) $s->Status, 'activity' => $activity];
            if ($s->Status !== 'scheduled') $before[] = "lesson_{$id}_not_scheduled";
            if ($s->Status !== 'cancelled') $after[] = "lesson_{$id}_not_cancelled";
        }
        $otherScheduled = DB::table('ClassSession')->where('StudentClassID', $cid)->where('Status', 'scheduled')
            ->whereNotIn('id', array_keys($m['lessons']))->count();
        if ($otherScheduled > 0) $common[] = 'other_scheduled_lessons';
        // The legacy schedule rows ContractAmendmentService::execute cancels on the same close must not outlive it.
        if (app(ContractAmendmentService::class)->futureSchedules($cid) !== []) $common[] = 'future_schedule_present';

        $charge = (int) ($c->Charge ?? -1);
        $total = $i ? (int) $i->TotalAmount : -1;
        $status = $i ? (string) $i->Status : '';
        if ((int) $c->SessionCount !== $from) $before[] = 'session_count';
        if ((int) $c->RemainingSessions !== $from - $to) $before[] = 'remaining_sessions';
        if ($charge !== $base($from)) $before[] = 'charge_not_rate_times_from';
        if ((string) ($c->closed_reason ?? '') !== '') $before[] = 'closed_reason';
        if ((int) $c->Paid !== 0 || !empty($c->PayDate)) $before[] = 'paid_flag';
        if ($status !== 'partial') $before[] = 'invoice_not_partial';
        if ($total !== $base($from)) $before[] = 'invoice_total_not_rate_times_from';
        if ($before === [] && app(ContractAmendmentService::class)->chargeForCount(StudentClass::findOrFail($cid), $to) !== $base($to)) {
            $before[] = 'charge_rule_mismatch';
        }
        if ((int) $c->SessionCount !== $to || (int) $c->RemainingSessions !== 0 || $charge !== $base($to)
            || (string) ($c->closed_reason ?? '') !== ContractAmendmentService::CLOSED_REASON || (int) $c->Paid !== 1) $after[] = 'course_not_closed';
        if ($total !== $charge || $status !== 'paid') $after[] = 'invoice_not_paid_at_charge';

        $state = $before === [] ? 'before' : ($after === [] ? 'after' : 'drift');
        $errors = array_values(array_unique([...$common, ...($state === 'drift' ? ['state_drift', ...$before] : [])]));
        $courseFields = ['SessionCount' => (int) $c->SessionCount, 'RemainingSessions' => (int) $c->RemainingSessions, 'Charge' => $charge,
            'closed_reason' => $c->closed_reason, 'Paid' => (int) $c->Paid, 'PayDate' => $c->PayDate];
        $invoiceFields = ['TotalAmount' => $total, 'Status' => $status, 'updated_at' => $i->updated_at ?? null];

        return ['state' => $state, 'errors' => $errors,
            'counts' => ['lessons' => count($m['lessons']), 'from_count' => $from, 'to_count' => $to, 'ledger_used' => $used,
                'other_scheduled' => $otherScheduled, 'live_invoices' => count($liveIds)],
            'facts' => [$courseFields, $invoiceFields, (int) $c->UsedSessions, $used, $net, $lessons, $liveIds, $otherScheduled],
            'snapshot' => ['lessons' => array_map(fn (array $l): string => $l['status'], $lessons), 'course' => $courseFields, 'invoice' => $invoiceFields],
            'charges' => [$base($from), $base($to)]];
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. Ids and counts only. */
    private function audit(array $m, string $reference, string $operationId): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append(self::EVENT, 'success', ['campus_id' => $m['campus_id'], 'actor_type' => 'pop-runner',
            'subject_type' => 'student_class', 'subject_id' => $m['course_id'], 'correlation_id' => $correlationId],
            ['reason_code' => $reference, 'pop_operation_id' => $operationId, 'row_count' => count($m['lessons']),
                'old_session_count' => $m['from_count'], 'new_session_count' => $m['to_count'],
                'old_remaining_sessions' => $m['from_count'] - $m['to_count'], 'new_remaining_sessions' => 0, 'outcome' => 'success'], true);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('unused_lessons_closeout_audit_not_persisted');
        }
    }
}

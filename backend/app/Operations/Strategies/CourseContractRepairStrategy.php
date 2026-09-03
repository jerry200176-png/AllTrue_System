<?php

namespace App\Operations\Strategies;

use App\Services\CourseContinuityService;
use App\Services\SessionContractRecoveryService;
use App\Services\SessionDeductionService;
use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Catalog-bound adapter for a bounded, unpaid cross-contract reconciliation. */
final class CourseContractRepairStrategy
{
    /** @param array<string,mixed> $parameters */
    public function plan(array $parameters): array
    {
        $errors = [];
        $sourceId = (int) $parameters['source_student_class_id'];
        $targetId = (int) $parameters['target_student_class_id'];
        if ($sourceId <= 0 || $targetId <= 0 || $sourceId === $targetId) $errors[] = 'source_target_ids_invalid';
        if ((int) $parameters['source_charge'] < 0 || (int) $parameters['target_charge'] < 0) $errors[] = 'desired_charge_invalid';
        $source = DB::table('StudentClass')->where('ID', $sourceId)->first();
        $target = DB::table('StudentClass')->where('ID', $targetId)->first();
        if (!$source || !$target) $errors[] = 'source_or_target_course_missing';
        if ($source && (int) $source->StudentID !== (int) $parameters['student_id']) $errors[] = 'source_student_mismatch';
        if ($target && (int) $target->StudentID !== (int) $parameters['student_id']) $errors[] = 'target_student_mismatch';
        if ($source && (int) $source->by1 !== (int) $parameters['campus_id']) $errors[] = 'source_campus_mismatch';
        if ($target && (int) $target->by1 !== (int) $parameters['campus_id']) $errors[] = 'target_campus_mismatch';
        if ($source && (int) $source->SubjectID !== (int) $parameters['subject_id']) $errors[] = 'source_subject_mismatch';
        if ($target && (int) $target->SubjectID !== (int) $parameters['subject_id']) $errors[] = 'target_subject_mismatch';
        if ($source && (int) ($source->Charge ?? 0) !== (int) $parameters['expected_source_charge']) $errors[] = 'source_charge_drifted';
        if ($target && (int) ($target->Charge ?? 0) !== (int) $parameters['expected_target_charge']) $errors[] = 'target_charge_drifted';
        if ($source && $target && ((int) ($source->PackageID ?? 0) > 0 || (int) ($target->PackageID ?? 0) > 0)) $errors[] = 'package_contract_forbidden';
        if ($source && $target && ($source->settlement_locked_at || $target->settlement_locked_at)) $errors[] = 'settlement_locked';
        if ($this->hasPaymentEvidence([$sourceId, $targetId])) $errors[] = 'payment_evidence_present';
        if ($this->hasActiveGroup($sourceId, $targetId)) $errors[] = 'contract_group_already_exists';
        if ($source && $target) {
            $unexpected = DB::table('StudentClass')
                ->where('StudentID', (int) $parameters['student_id'])
                ->where('SubjectID', (int) $parameters['subject_id'])
                ->where('by1', (int) $parameters['campus_id'])
                ->where(function ($query) use ($sourceId, $targetId): void {
                    $query->whereNotIn('ID', [$sourceId, $targetId]);
                })
                ->where(function ($query): void {
                    $query->where('Stop', 0)->orWhereNull('Stop');
                })
                ->where(function ($query): void {
                    $query->whereNull('PackageID')->orWhere('PackageID', 0);
                })
                ->pluck('ID')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if ($unexpected !== []) $errors[] = 'unexpected_active_contracts';
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_.-]{2,95}$/', (string) $parameters['reason'])) $errors[] = 'reason_must_be_a_safe_code';
        if (!preg_match('/^[A-Za-z0-9_.:#-]{3,128}$/', (string) $parameters['decision_reference'])) $errors[] = 'decision_reference_invalid';
        if ($parameters['target_invoice_id']) {
            $invoice = DB::table('Invoice')->where('id', $parameters['target_invoice_id'])->first();
            if (!$invoice || (int) $invoice->StudentClassID !== $targetId) $errors[] = 'target_invoice_mismatch';
            if ($invoice && (int) $invoice->TotalAmount !== (int) $parameters['expected_target_invoice_total']) $errors[] = 'target_invoice_drifted';
            if ($invoice && ((int) $invoice->PaidAmount !== 0 || (string) ($invoice->Status ?? '') === 'void')) $errors[] = 'target_invoice_payment_boundary';
        }
        if ($parameters['source_invoice_id']) {
            $invoice = DB::table('Invoice')->where('id', $parameters['source_invoice_id'])->first();
            if (!$invoice || (int) $invoice->StudentClassID !== $sourceId) $errors[] = 'source_invoice_mismatch';
            if ($invoice && (int) $invoice->TotalAmount !== (int) $parameters['expected_source_invoice_total']) $errors[] = 'source_invoice_drifted';
            if ($invoice && ((int) $invoice->PaidAmount !== 0 || (string) ($invoice->Status ?? '') === 'void')) $errors[] = 'source_invoice_payment_boundary';
        }

        $transferIds = array_values(array_map('intval', $parameters['transfer_session_ids']));
        $preserveIds = array_values(array_map('intval', $parameters['preserve_session_ids']));
        if (count($transferIds) !== count(array_unique($transferIds)) || count($preserveIds) !== count(array_unique($preserveIds))) $errors[] = 'session_ids_must_be_unique';
        if (array_intersect($transferIds, $preserveIds) !== []) $errors[] = 'preserve_transfer_overlap';
        $allSessionIds = array_values(array_unique(array_merge($transferIds, $preserveIds)));
        $sessions = $allSessionIds === [] ? collect() : DB::table('ClassSession')->whereIn('id', $allSessionIds)->get();
        $expectations = [];
        foreach ($parameters['session_expectations'] as $expectation) $expectations[(int) $expectation['id']] = $expectation;
        if (count($expectations) !== count($parameters['session_expectations']) || array_diff(array_keys($expectations), $allSessionIds) || array_diff($allSessionIds, array_keys($expectations))) $errors[] = 'session_expectations_not_exact';
        foreach ($transferIds as $id) {
            $row = $sessions->firstWhere('id', $id);
            if (!$row || (int) $row->StudentClassID !== $sourceId) $errors[] = "transfer_session_{$id}_mismatch";
            $this->assertSessionExpectation($row, $expectations[$id] ?? null, $errors, $id);
        }
        foreach ($preserveIds as $id) {
            $row = $sessions->firstWhere('id', $id);
            if (!$row || (int) $row->StudentClassID !== $sourceId) $errors[] = "preserve_session_{$id}_mismatch";
            $this->assertSessionExpectation($row, $expectations[$id] ?? null, $errors, $id);
        }

        return [
            'ok' => $errors === [], 'errors' => array_values(array_unique($errors)),
            'source_student_class_id' => $sourceId, 'target_student_class_id' => $targetId,
            'transfer_session_ids' => $transferIds, 'preserve_session_ids' => $preserveIds,
            'will_change' => $errors === [] ? ['course_contract_group', 'ClassSession.StudentClassID', 'LearningRecord.StudentClassID', 'StudentSignIn.StudentClassID', 'session_deduction_ledger.student_class_id', 'StudentClass.Charge', 'Invoice.TotalAmount'] : [],
            'will_not_change' => ['attendance_content', 'learning_record_content', 'Payment', 'PaymentReport', 'student_identity'],
            'snapshot' => $this->snapshot($parameters, $sourceId, $targetId, $transferIds),
        ];
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    public function execute(array $plan, array $context): array
    {
        if (!$plan['ok']) throw new RuntimeException('COURSE_CONTRACT_REPAIR_BLOCKED: ' . implode('; ', $plan['errors']));
        return DB::transaction(function () use ($plan, $context): array {
            $p = $plan['snapshot']['parameters'];
            $group = app(CourseContinuityService::class)->createGroup([
                'student_id' => (int) $p['student_id'], 'campus_id' => (int) $p['campus_id'], 'subject_id' => (int) $p['subject_id'],
                'label' => 'POP contract repair ' . $context['operation_id'],
                'members' => [
                    ['student_class_id' => (int) $p['source_student_class_id'], 'relation_type' => 'original', 'decision_reason' => $p['reason']],
                    ['student_class_id' => (int) $p['target_student_class_id'], 'relation_type' => 'renewal', 'decision_reason' => $p['reason']],
                ],
            ], null, ['mode' => 'all', 'campus_ids' => []]);
            app(SessionContractRecoveryService::class)->recoverAndTransfer(
                (int) $p['source_student_class_id'], (int) $p['target_student_class_id'], $plan['transfer_session_ids'], (string) $p['reason'], null
            );
            $source = DB::table('StudentClass')->where('ID', $p['source_student_class_id'])->lockForUpdate()->first();
            $target = DB::table('StudentClass')->where('ID', $p['target_student_class_id'])->lockForUpdate()->first();
            if (!$source || !$target || (int) $source->Charge !== (int) $p['expected_source_charge'] || (int) $target->Charge !== (int) $p['expected_target_charge']) {
                throw new RuntimeException('course_charge_precondition_failed');
            }
            DB::table('StudentClass')->where('ID', $p['source_student_class_id'])->update(['Charge' => $p['source_charge']]);
            DB::table('StudentClass')->where('ID', $p['target_student_class_id'])->update(['Charge' => $p['target_charge']]);
            $this->updateInvoice($p['target_invoice_id'], $p['target_student_class_id'], $p['expected_target_invoice_total'], (int) $p['target_charge']);
            SecurityAuditEvent::append('pop.course_contract_repair', 'success', [
                'campus_id' => (int) $p['campus_id'], 'actor_type' => 'pop-runner', 'subject_type' => 'student_class',
                'subject_id' => (int) $p['source_student_class_id'],
            ], [
                'reason_code' => 'catalog_bound_course_contract_repair', 'transferred_session_count' => count($plan['transfer_session_ids']),
                'old_charge' => (int) $p['source_charge'], 'new_charge' => (int) $p['target_charge'], 'outcome' => 'success',
            ]);
            $snapshot = $plan['snapshot'];
            $snapshot['group_id'] = (int) $group->id;
            return ['ok' => true, 'group_id' => (int) $group->id, 'snapshot' => $snapshot, 'transferred_session_ids' => $plan['transfer_session_ids']];
        });
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $result */
    public function verify(array $plan, array $result): array
    {
        $p = $plan['snapshot']['parameters'];
        $errors = [];
        foreach ($plan['transfer_session_ids'] as $id) {
            if ((int) DB::table('ClassSession')->where('id', $id)->value('StudentClassID') !== (int) $p['target_student_class_id']) $errors[] = "session_{$id}_not_transferred";
            $this->assertMirrorOwnership($id, (int) $p['target_student_class_id'], $errors);
        }
        foreach ($plan['preserve_session_ids'] as $id) {
            if ((int) DB::table('ClassSession')->where('id', $id)->value('StudentClassID') !== (int) $p['source_student_class_id']) $errors[] = "session_{$id}_not_preserved";
            $this->assertMirrorOwnership($id, (int) $p['source_student_class_id'], $errors);
        }
        if ((int) DB::table('StudentClass')->where('ID', $p['source_student_class_id'])->value('Charge') !== (int) $p['source_charge']) $errors[] = 'source_charge_not_applied';
        if ((int) DB::table('StudentClass')->where('ID', $p['target_student_class_id'])->value('Charge') !== (int) $p['target_charge']) $errors[] = 'target_charge_not_applied';
        if ($p['target_invoice_id'] && (int) DB::table('Invoice')->where('id', $p['target_invoice_id'])->value('TotalAmount') !== (int) $p['target_charge']) $errors[] = 'target_invoice_not_applied';
        if ($p['source_invoice_id'] && (int) DB::table('Invoice')->where('id', $p['source_invoice_id'])->value('TotalAmount') !== (int) $p['expected_source_invoice_total']) $errors[] = 'source_invoice_changed';
        if ($this->hasPaymentEvidence([(int) $p['source_student_class_id'], (int) $p['target_student_class_id']])) $errors[] = 'payment_boundary_changed';
        if (!$this->hasExactActiveGroup((int) ($result['group_id'] ?? $this->groupIdForPair($p)), (int) $p['source_student_class_id'], (int) $p['target_student_class_id'])) $errors[] = 'continuity_group_missing_or_drifted';
        return ['ok' => $errors === [], 'errors' => $errors, 'checks' => ['session_ownership', 'learning_record_ownership', 'student_sign_in_ownership', 'ledger_ownership', 'source_charge', 'target_charge', 'invoice_total', 'continuity_group', 'payment_boundary']];
    }

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $context */
    public function rollback(array $snapshot, array $context): array
    {
        if (!$snapshot) throw new RuntimeException('POP rollback snapshot is missing; fail closed.');
        return DB::transaction(function () use ($snapshot): array {
            $p = $snapshot['parameters'];
            foreach ($snapshot['sessions'] as $session) {
                if ((int) DB::table('ClassSession')->where('id', $session['id'])->value('StudentClassID') !== (int) $p['target_student_class_id']) throw new RuntimeException('rollback_session_drifted');
                $expectedStatus = strtolower((string) $session['Status']) === 'cancelled' ? 'attended' : strtolower((string) $session['Status']);
                if (strtolower((string) DB::table('ClassSession')->where('id', $session['id'])->value('Status')) !== $expectedStatus) throw new RuntimeException('rollback_session_status_drifted');
                DB::table('ClassSession')->where('id', $session['id'])->update(['StudentClassID' => $session['StudentClassID'], 'Status' => $session['Status']]);
                $this->restoreRows('LearningRecord', 'ClassSessionID', $session['id'], $snapshot['learning_records'][(string) $session['id']] ?? []);
                $this->restoreRows('StudentSignIn', 'ClassSessionID', $session['id'], $snapshot['sign_ins'][(string) $session['id']] ?? []);
                $this->restoreRows('session_deduction_ledger', 'class_session_id', $session['id'], $snapshot['ledger'][(string) $session['id']] ?? []);
            }
            if ((int) DB::table('StudentClass')->where('ID', $p['source_student_class_id'])->value('Charge') !== (int) $p['source_charge']
                || (int) DB::table('StudentClass')->where('ID', $p['target_student_class_id'])->value('Charge') !== (int) $p['target_charge']) throw new RuntimeException('rollback_charge_drifted');
            DB::table('StudentClass')->where('ID', $p['source_student_class_id'])->update(['Charge' => $snapshot['source_charge']]);
            DB::table('StudentClass')->where('ID', $p['target_student_class_id'])->update(['Charge' => $snapshot['target_charge']]);
            if ($snapshot['target_invoice']) {
                $this->restoreInvoice($snapshot['target_invoice'], $snapshot['target_invoice_items'], (int) $p['target_student_class_id'], (int) $p['target_charge']);
            }
            SessionDeductionService::recomputeCounters((int) $p['source_student_class_id']);
            SessionDeductionService::recomputeCounters((int) $p['target_student_class_id']);
            if (!empty($snapshot['group_id'])) {
                $members = DB::table('course_contract_group_members')->where('group_id', $snapshot['group_id'])->whereNull('unlinked_at')->pluck('student_class_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $expectedMembers = collect([(int) $p['source_student_class_id'], (int) $p['target_student_class_id']])->sort()->values()->all();
                if ($members !== $expectedMembers) throw new RuntimeException('rollback_group_drifted');
                DB::table('course_contract_group_members')->where('group_id', $snapshot['group_id'])->delete();
                DB::table('course_contract_groups')->where('id', $snapshot['group_id'])->delete();
            }
            SecurityAuditEvent::append('pop.course_contract_repair.rollback', 'success', [
                'campus_id' => (int) $p['campus_id'], 'actor_type' => 'pop-runner', 'subject_type' => 'student_class',
                'subject_id' => (int) $p['source_student_class_id'],
            ], ['reason_code' => 'catalog_bound_course_contract_repair_rollback', 'outcome' => 'success']);
            return ['ok' => true, 'rolled_back_session_ids' => array_column($snapshot['sessions'], 'id')];
        });
    }

    /** @param array<int,int> $classIds */
    private function hasPaymentEvidence(array $classIds): bool
    {
        return DB::table('payment_reports')->whereIn('StudentClassID', $classIds)->exists()
            || DB::table('Invoice')->whereIn('StudentClassID', $classIds)->where('PaidAmount', '>', 0)->exists()
            || DB::table('Payment')->join('Invoice', 'Payment.InvoiceID', '=', 'Invoice.id')->whereIn('Invoice.StudentClassID', $classIds)->where(function ($q) { $q->whereNull('Payment.Method')->orWhere('Payment.Method', '!=', 'void'); })->exists();
    }

    private function hasActiveGroup(int $sourceId, int $targetId): bool
    {
        return DB::table('course_contract_group_members as a')->join('course_contract_group_members as b', 'a.group_id', '=', 'b.group_id')->where('a.student_class_id', $sourceId)->where('b.student_class_id', $targetId)->whereNull('a.unlinked_at')->whereNull('b.unlinked_at')->exists();
    }

    private function hasExactActiveGroup(int $groupId, int $sourceId, int $targetId): bool
    {
        if ($groupId <= 0) return false;
        $members = DB::table('course_contract_group_members')->where('group_id', $groupId)->whereNull('unlinked_at')->pluck('student_class_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = collect([$sourceId, $targetId])->sort()->values()->all();
        return $members === $expected;
    }

    private function groupIdForPair(array $parameters): int
    {
        return (int) (DB::table('course_contract_group_members as a')
            ->join('course_contract_group_members as b', 'a.group_id', '=', 'b.group_id')
            ->where('a.student_class_id', (int) $parameters['source_student_class_id'])
            ->where('b.student_class_id', (int) $parameters['target_student_class_id'])
            ->whereNull('a.unlinked_at')->whereNull('b.unlinked_at')->value('a.group_id') ?? 0);
    }

    /** @param array<string,mixed> $parameters */
    private function snapshot(array $parameters, int $sourceId, int $targetId, array $sessionIds): array
    {
        $source = DB::table('StudentClass')->where('ID', $sourceId)->first();
        $target = DB::table('StudentClass')->where('ID', $targetId)->first();
        if (!$source || !$target) return [];
        $invoice = $parameters['target_invoice_id'] ? DB::table('Invoice')->where('id', $parameters['target_invoice_id'])->first() : null;
        $sessions = $sessionIds === [] ? collect() : DB::table('ClassSession')->whereIn('id', $sessionIds)->get(['id', 'StudentClassID', 'Status']);
        $learningRecords = [];
        $signIns = [];
        $ledger = [];
        foreach ($sessionIds as $sessionId) {
            $learningRecords[(string) $sessionId] = DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->get()->map(fn ($row): array => (array) $row)->all();
            $signIns[(string) $sessionId] = DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->get()->map(fn ($row): array => (array) $row)->all();
            $ledger[(string) $sessionId] = DB::table('session_deduction_ledger')->where('class_session_id', $sessionId)->get()->map(fn ($row): array => (array) $row)->all();
        }
        return [
            'parameters' => $parameters, 'source_charge' => (int) ($source->Charge ?? 0), 'target_charge' => (int) ($target->Charge ?? 0),
            'target_invoice' => $invoice ? (array) $invoice : null,
            'target_invoice_items' => $invoice ? DB::table('InvoiceItem')->where('InvoiceID', $invoice->id)->get()->map(fn ($row) => (array) $row)->all() : [],
            'group_id' => null,
            'sessions' => $sessions->map(fn ($row): array => (array) $row)->all(),
            'learning_records' => $learningRecords, 'sign_ins' => $signIns, 'ledger' => $ledger,
        ];
    }

    private function updateInvoice(?int $invoiceId, int $studentClassId, int $expected, int $amount): void
    {
        if (!$invoiceId) return;
        $invoice = DB::table('Invoice')->where('id', $invoiceId)->lockForUpdate()->first();
        if (!$invoice || (int) $invoice->StudentClassID !== $studentClassId || (int) $invoice->TotalAmount !== $expected) throw new RuntimeException('invoice_precondition_failed');
        if ((int) $invoice->PaidAmount !== 0 || (string) ($invoice->Status ?? '') === 'void') throw new RuntimeException('invoice_payment_boundary_failed');
        $items = DB::table('InvoiceItem')->where('InvoiceID', $invoiceId)->lockForUpdate()->get();
        if ($items->count() > 1) throw new RuntimeException('invoice_items_ambiguous');
        DB::table('Invoice')->where('id', $invoiceId)->update(['TotalAmount' => $amount, 'updated_at' => now()]);
        if ($items->count() === 1) DB::table('InvoiceItem')->where('id', $items->first()->id)->update(['Amount' => $amount, 'updated_at' => now()]);
    }

    private function restoreInvoice(array $invoiceSnapshot, array $itemSnapshots, int $studentClassId, int $expectedCurrentTotal): void
    {
        $invoice = DB::table('Invoice')->where('id', $invoiceSnapshot['id'])->lockForUpdate()->first();
        if (!$invoice || (int) $invoice->StudentClassID !== $studentClassId || (int) $invoice->TotalAmount !== $expectedCurrentTotal || (int) $invoice->PaidAmount !== 0 || (string) ($invoice->Status ?? '') === 'void') throw new RuntimeException('rollback_invoice_drifted');
        $currentItemIds = DB::table('InvoiceItem')->where('InvoiceID', $invoiceSnapshot['id'])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $snapshotItemIds = collect($itemSnapshots)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        if ($currentItemIds !== $snapshotItemIds) throw new RuntimeException('rollback_invoice_items_drifted');
        DB::table('Invoice')->where('id', $invoiceSnapshot['id'])->update(['TotalAmount' => $invoiceSnapshot['TotalAmount'], 'Status' => $invoiceSnapshot['Status'], 'updated_at' => now()]);
        foreach ($itemSnapshots as $item) {
            $id = $item['id']; unset($item['id']);
            DB::table('InvoiceItem')->where('id', $id)->update($item);
        }
    }

    /** @param array<int,array<string,mixed>> $snapshots */
    private function restoreRows(string $table, string $foreignKey, int $sessionId, array $snapshots): void
    {
        $current = DB::table($table)->where($foreignKey, $sessionId)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $expected = collect($snapshots)->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        if ($current !== $expected) throw new RuntimeException('rollback_evidence_drifted');
        foreach ($snapshots as $row) {
            $id = $row['id']; unset($row['id']);
            DB::table($table)->where('id', $id)->update($row);
        }
    }

    private function assertMirrorOwnership(int $sessionId, int $studentClassId, array &$errors): void
    {
        foreach ([['LearningRecord', 'ClassSessionID', 'StudentClassID', 'learning_record'], ['StudentSingIn', 'ClassSessionID', 'StudentClassID', 'student_sign_in'], ['session_deduction_ledger', 'class_session_id', 'student_class_id', 'ledger']] as [$table, $foreignKey, $ownerKey, $label]) {
            $owners = DB::table($table)->where($foreignKey, $sessionId)->pluck($ownerKey)->map(fn ($id): int => (int) $id)->unique()->values()->all();
            if ($owners !== [] && $owners !== [$studentClassId]) $errors[] = "{$label}_{$sessionId}_drifted";
        }
    }

    private function assertSessionExpectation(?object $row, ?array $expected, array &$errors, int $id): void
    {
        if (!$row || !$expected) { $errors[] = "session_{$id}_expectation_missing"; return; }
        if (substr((string) $row->SessionDate, 0, 10) !== (string) $expected['date']) $errors[] = "session_{$id}_date_drifted";
        if (substr((string) $row->StartTime, 0, 5) !== substr((string) $expected['start_time'], 0, 5)) $errors[] = "session_{$id}_time_drifted";
        if (isset($expected['end_time']) && substr((string) $row->EndTime, 0, 5) !== substr((string) $expected['end_time'], 0, 5)) $errors[] = "session_{$id}_end_time_drifted";
        if (strtolower((string) $row->Status) !== strtolower((string) $expected['status'])) $errors[] = "session_{$id}_status_drifted";
    }
}

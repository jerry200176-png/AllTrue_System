<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Exact-case POP strategy (Founder decision 2026-10-05):
 * A) student 164 attended 7 lessons (Jun-Aug 2026) on contract 1249 that no invoice covers: bill them at $1,650 each on
 *    ONE new catch-up contract with three monthly invoices. Sessions, sign-ins, learning records and 1249 are never touched.
 * B) void orphan unpaid invoice 1053 of student 162 (its contract 2564 no longer exists; no lessons, payments or reports).
 * Eloquent creates are used on purpose so the Invoice/InvoiceItem/StudentClass hooks (waived-contract guards) run.
 */
final class MuzhaChenBillingCatchupStrategy
{
    private const REF = 'repair-muzha-chen-billing-catchup-20261005';
    private const STUDENT = 164;
    private const CAMPUS = 16;
    private const SOURCE = 1249;
    private const ORPHAN_INVOICE = 1053;
    private const ORPHAN_STUDENT = 162;
    private const ORPHAN_CLASS = 2564;
    private const ORPHAN_TOTAL = 6000;
    private const AUG_CUTOFF = '2026-08-14';
    private const RATE = 1650;
    private const BILLABLE = ['attended', 'completed', 'late']; // MonthlyBillingService::BILLABLE_STATUSES
    private const MONTHS = ['2026-06' => 3, '2026-07' => 3, '2026-08' => 1];
    private const COPY = ['GradeID', 'SubjectID', 'TeacherID', 'by1', 'Period', 'TotalHours', 'Pay', 'Rate', 'ClassType'];

    public function plan(array $parameters): array
    {
        $paramErrors = ($parameters['decision_reference'] ?? null) === self::REF ? [] : ['case_parameters_mismatch'];
        $s = $this->inspect(false);
        $errors = $this->beforeErrors($s);
        $state = 'before';
        if ($errors !== [] && $this->afterErrors($s) === []) {
            $errors = [];
            $state = 'after';
        }
        $errors = array_values(array_unique([...$paramErrors, ...$errors]));

        return ['ok' => $errors === [], 'errors' => $errors, 'state' => $state,
            'student_class_ids' => [self::SOURCE], 'invoice_ids' => [self::ORPHAN_INVOICE],
            'info' => ['may_billable_lessons' => $s['may_count'], 'may_covered' => $s['may_covered']],
            'snapshot' => $errors === [] ? ['orphan_invoice' => $s['orphan_old']] : []];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Already applied (retry after the execution record failed to persist): rebuild the rollback snapshot from live rows.
            return ['ok' => true, 'already_applied' => true, 'created' => 0, 'snapshot' => $this->rebuiltSnapshot($this->inspect(false))];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'before') {
            throw new RuntimeException('muzha_chen_plan_not_ready');
        }

        return DB::transaction(function () use ($context): array {
            $s = $this->inspect(true);
            $errors = $this->beforeErrors($s);
            if ($errors !== []) {
                throw new RuntimeException('muzha_chen_drift:' . implode(',', $errors));
            }
            /** @var StudentClass $src */
            $src = $s['source'];
            $attrs = [];
            foreach (self::COPY as $col) {
                if ($src->getAttribute($col) !== null) {
                    $attrs[$col] = $src->getAttribute($col);
                }
            }
            $total = array_sum(self::MONTHS) * self::RATE;
            $contract = StudentClass::query()->create($attrs + [
                'StudentID' => self::STUDENT, 'ScheduleMode' => 'date', 'StartDate' => '2026-06-01', 'EndDate' => '2026-08-31',
                'Charge' => $total, 'Paid' => 0, 'Stop' => 1, 'closed_reason' => 'settled_pending', 'SessionCount' => 0,
                'Memo' => '補收帳單合約：合約 ' . self::SOURCE . ' 於 2026-06 至 2026-08 已上課未開單，Founder 2026-10-05 決議每堂 $'
                    . self::RATE . ' 補開（' . self::REF . '）',
            ]);
            $invoiceIds = $itemIds = [];
            foreach (self::MONTHS as $month => $lessons) {
                $amount = $lessons * self::RATE;
                $invoice = Invoice::query()->create(['StudentID' => self::STUDENT, 'StudentClassID' => $contract->getKey(),
                    'IssueDate' => now()->toDateString(), 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
                    'ScheduleModeAtIssue' => 'date', 'Note' => self::REF, 'billing_period' => $month]);
                $item = InvoiceItem::query()->create(['InvoiceID' => $invoice->getKey(), 'StudentClassID' => $contract->getKey(),
                    'Description' => "{$month} 補收學費（{$lessons} 堂 × \$" . self::RATE . '）', 'Amount' => $amount,
                    'PeriodStart' => "{$month}-01", 'PeriodEnd' => Carbon::parse("{$month}-01")->endOfMonth()->toDateString()]);
                $invoiceIds[] = (int) $invoice->getKey();
                $itemIds[] = (int) $item->getKey();
            }
            $old = $s['orphan_old'];
            $n = Invoice::query()->where('id', self::ORPHAN_INVOICE)->where('Status', 'unpaid')->where('PaidAmount', 0)
                ->update(['Status' => 'void', 'Note' => $this->voidedNote($old['note'])]);
            if ($n !== 1) {
                throw new RuntimeException("muzha_chen_void_updated_{$n}_of_1");
            }
            $this->audit('pop.muzha_chen_billing_catchup', ['reason_code' => self::REF,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'contract_id' => (int) $contract->getKey(),
                'invoice_ids' => $invoiceIds, 'voided_invoice_id' => self::ORPHAN_INVOICE, 'billed_amount' => $total, 'outcome' => 'success']);

            return ['ok' => true, 'created' => 7, 'snapshot' => ['contract_id' => (int) $contract->getKey(),
                'invoice_ids' => $invoiceIds, 'item_ids' => $itemIds, 'orphan_invoice' => $old]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        $errors = array_values(array_unique([...$errors, ...$this->afterErrors($this->inspect(false))]));

        return ['ok' => $errors === [], 'errors' => $errors,
            'checks' => ['catchup_contract_three_invoices_three_items', 'orphan_invoice_void']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $ids = $snapshot['invoice_ids'] ?? null;
        $items = $snapshot['item_ids'] ?? null;
        $old = $snapshot['orphan_invoice'] ?? null;
        if (!is_array($ids) || count($ids) !== 3 || !is_array($items) || !is_array($old)
            || (int) ($snapshot['contract_id'] ?? 0) < 1 || (int) ($old['id'] ?? 0) !== self::ORPHAN_INVOICE) {
            throw new RuntimeException('muzha_chen_rollback_snapshot_invalid');
        }
        $contractId = (int) $snapshot['contract_id'];

        return DB::transaction(function () use ($ids, $items, $old, $contractId): array {
            $deleted = 0;
            $skipped = [];
            $invoices = Invoice::query()->with(['payments', 'items'])->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            foreach ($invoices as $inv) {
                $id = (int) $inv->getKey();
                $period = (string) $inv->getAttribute('billing_period');
                $reports = DB::table('payment_reports')->where('InvoiceID', $id)->exists();
                $itemIds = $inv->getRelationValue('items')->map(fn ($i) => (int) $i->getKey())->all();
                if ((int) $inv->getAttribute('StudentClassID') !== $contractId || $inv->getAttribute('Status') !== 'unpaid'
                    || (int) $inv->getAttribute('PaidAmount') !== 0 || $inv->getRelationValue('payments')->isNotEmpty() || $reports
                    || (int) $inv->getAttribute('TotalAmount') !== (self::MONTHS[$period] ?? -1) * self::RATE
                    || array_diff($itemIds, array_map('intval', $items)) !== []) {
                    $skipped[] = "invoice_{$id}";
                    continue;
                }
                InvoiceItem::query()->where('InvoiceID', $id)->delete();
                $inv->delete();
                $deleted++;
            }
            $contract = StudentClass::query()->lockForUpdate()->find($contractId);
            if ($contract) {
                $untouched = (int) $contract->getAttribute('Paid') === 0 && (int) $contract->getAttribute('Stop') === 1
                    && $contract->getAttribute('closed_reason') === 'settled_pending'
                    && (int) $contract->getAttribute('Charge') === array_sum(self::MONTHS) * self::RATE
                    && !Invoice::query()->where('StudentClassID', $contractId)->exists()
                    && !InvoiceItem::query()->where('StudentClassID', $contractId)->exists()
                    && !ClassSession::query()->where('StudentClassID', $contractId)->exists()
                    && !DB::table('payment_reports')->where('StudentClassID', $contractId)->exists();
                if ($untouched) {
                    $contract->delete();
                    $deleted++;
                } else {
                    $skipped[] = "contract_{$contractId}";
                }
            }
            $restored = $this->restoreOrphan($old);
            if ($restored === null) {
                $skipped[] = 'invoice_' . self::ORPHAN_INVOICE;
            }
            $this->audit('pop.muzha_chen_billing_catchup.rollback', ['reason_code' => self::REF,
                'deleted' => $deleted, 'skipped' => count($skipped), 'outcome' => 'success']);

            // Any skipped row means the repair is only partly undone: surface it for operator resolution.
            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'deleted' => $deleted, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** @return bool|null true restored, false already in the old state, null changed since (skip) */
    private function restoreOrphan(array $old): ?bool
    {
        $row = Invoice::query()->with('payments')->lockForUpdate()->find(self::ORPHAN_INVOICE);
        if (!$row) {
            return null;
        }
        if ($row->getAttribute('Status') === $old['status'] && (string) $row->getAttribute('Note') === $old['note']) {
            return false;
        }
        if ($row->getAttribute('Status') !== 'void' || (string) $row->getAttribute('Note') !== $this->voidedNote($old['note'])
            || (int) $row->getAttribute('TotalAmount') !== self::ORPHAN_TOTAL || (int) $row->getAttribute('PaidAmount') !== 0
            || $row->getRelationValue('payments')->isNotEmpty()
            || DB::table('payment_reports')->where('InvoiceID', self::ORPHAN_INVOICE)->exists()) {
            return null;
        }
        $n = Invoice::query()->where('id', self::ORPHAN_INVOICE)->where('Status', 'void')
            ->update(['Status' => $old['status'], 'Note' => $old['note']]);
        if ($n !== 1) {
            throw new RuntimeException('muzha_chen_rollback_orphan');
        }

        return true;
    }

    private function voidedNote(string $old): string
    {
        return $old === '' ? self::REF : $old . "\n" . self::REF;
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(string $event, array $metadata): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append($event, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'billing_catchup',
            'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('muzha_chen_audit_not_persisted');
        }
    }

    /** @return array<string,mixed> live state of both cases; locks the referenced rows when asked */
    private function inspect(bool $lock): array
    {
        $l = fn ($q) => $lock ? $q->lockForUpdate() : $q;
        $source = $l(StudentClass::query()->with('student:id,CampusID'))->find(self::SOURCE);
        $contractIds = $l(StudentClass::query()->where('StudentID', self::STUDENT))->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $catchupIds = StudentClass::query()->where('StudentID', self::STUDENT)->where('Memo', 'like', '%' . self::REF . '%')
            ->pluck('ID')->map(fn ($v) => (int) $v)->all();
        $dates = $l(ClassSession::query()->where('StudentClassID', self::SOURCE)->whereIn('Status', self::BILLABLE)
            ->where('SessionDate', '>=', '2026-05-01'))->orderBy('SessionDate')->pluck('SessionDate')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $invoices = $l(Invoice::query()->where(fn ($v) => $v->whereNull('Status')->orWhere('Status', '!=', 'void'))->with('items')->where(fn ($w) => $w->whereIn('StudentClassID', $contractIds)
            ->orWhereHas('items', fn ($i) => $i->whereIn('StudentClassID', $contractIds))))->orderBy('id')->get();
        $covered = fn (string $d): bool => $this->covered($d, $invoices, $contractIds);
        $may = array_values(array_filter($dates, fn ($d) => str_starts_with($d, '2026-05')));
        $orphan = $l(Invoice::query()->with('payments'))->find(self::ORPHAN_INVOICE);
        $catchup = $catchupIds === [] ? null : Invoice::query()->with('items')->whereIn('StudentClassID', $catchupIds)->orderBy('billing_period')->get();

        return [
            'source' => $source, 'dates' => array_values(array_filter($dates, fn ($d) => $d >= '2026-06-01')),
            'may_count' => count($may), 'may_covered' => $may !== [] && count(array_filter($may, $covered)) === count($may),
            'covered' => $covered, 'catchup_ids' => $catchupIds, 'catchup_invoices' => $catchup, 'orphan' => $orphan,
            'orphan_old' => ['id' => self::ORPHAN_INVOICE, 'status' => (string) $orphan?->getAttribute('Status'),
                'note' => (string) $orphan?->getAttribute('Note')],
            'orphan_reports' => DB::table('payment_reports')->where(fn ($w) => $w->where('InvoiceID', self::ORPHAN_INVOICE)
                ->orWhere('StudentClassID', self::ORPHAN_CLASS))->count(),
            'orphan_class_exists' => DB::table('StudentClass')->where('ID', self::ORPHAN_CLASS)->exists(),
        ];
    }

    /**
     * Covered = an item owned by one of the student's contracts (null item owner = the invoice's anchor contract) whose
     * PeriodStart AND PeriodEnd contain the date; or, for an invoice with no such period-bearing items, billing_period = month.
     */
    private function covered(string $date, $invoices, array $contractIds): bool
    {
        foreach ($invoices as $inv) {
            $anchor = (int) $inv->getAttribute('StudentClassID');
            $items = $inv->getRelationValue('items')->filter(fn ($i) => in_array((int) ($i->getAttribute('StudentClassID') ?: $anchor), $contractIds, true)
                && $i->getAttribute('PeriodStart') && $i->getAttribute('PeriodEnd'));
            if ($items->isEmpty()) {
                if (in_array($anchor, $contractIds, true) && (string) $inv->getAttribute('billing_period') === substr($date, 0, 7)) {
                    return true;
                }
                continue;
            }
            foreach ($items as $i) {
                if (substr((string) $i->getAttribute('PeriodStart'), 0, 10) <= $date && $date <= substr((string) $i->getAttribute('PeriodEnd'), 0, 10)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $s @return list<string> */
    private function beforeErrors(array $s): array
    {
        $e = [];
        $src = $s['source'];
        if (!$src) {
            $e[] = 'source_contract_missing';
        } else {
            if ((int) $src->getAttribute('StudentID') !== self::STUDENT) $e[] = 'source_contract_student';
            if ((int) $src->student?->getAttribute('CampusID') !== self::CAMPUS) $e[] = 'source_contract_campus';
            if ($src->getAttribute('ScheduleMode') !== 'date') $e[] = 'source_contract_not_date_mode';
            if (strtolower(trim((string) $src->getAttribute('ClassType'))) === 'tutoring') $e[] = 'source_contract_tutoring';
        }
        $counts = array_count_values(array_map(fn ($d) => substr($d, 0, 7), $s['dates']));
        ksort($counts);
        if ($counts !== self::MONTHS) $e[] = 'lessons_mismatch';
        if (array_filter($s['dates'], fn ($d) => str_starts_with($d, '2026-08') && $d > self::AUG_CUTOFF) !== []) $e[] = 'august_lesson_after_cutoff';
        foreach (array_unique(array_map(fn ($d) => substr($d, 0, 7), array_filter($s['dates'], $s['covered']))) as $m) {
            $e[] = "lesson_already_invoiced_{$m}";
        }
        if ($s['catchup_ids'] !== []) $e[] = 'catchup_exists';
        $o = $s['orphan'];
        if (!$o) {
            $e[] = 'orphan_invoice_missing';
        } else {
            if ((int) $o->getAttribute('StudentID') !== self::ORPHAN_STUDENT) $e[] = 'orphan_invoice_student';
            if ($o->getAttribute('Status') !== 'unpaid') $e[] = 'orphan_invoice_not_unpaid';
            if ((int) $o->getAttribute('PaidAmount') !== 0) $e[] = 'orphan_invoice_paid_amount';
            if ($o->getRelationValue('payments')->isNotEmpty()) $e[] = 'orphan_invoice_has_payments';
            if ((int) $o->getAttribute('TotalAmount') !== self::ORPHAN_TOTAL) $e[] = 'orphan_invoice_total';
            if ((int) $o->getAttribute('StudentClassID') !== self::ORPHAN_CLASS) $e[] = 'orphan_invoice_contract_id';
        }
        if ($s['orphan_reports'] > 0) $e[] = 'orphan_invoice_has_reports';
        if ($s['orphan_class_exists']) $e[] = 'orphan_contract_exists';

        return $e;
    }

    /** Applied: exactly one catch-up contract with the three invoices/items, and 1053 void with the reference. @return list<string> */
    private function afterErrors(array $s): array
    {
        $e = [];
        if (count($s['catchup_ids']) !== 1) {
            $e[] = 'catchup_contract_missing';
        } else {
            $byPeriod = collect($s['catchup_invoices'] ?? [])->keyBy(fn ($i) => (string) $i->getAttribute('billing_period'));
            foreach (self::MONTHS as $month => $lessons) {
                $inv = $byPeriod->get($month);
                $items = $inv?->getRelationValue('items');
                if (!$inv || (int) $inv->getAttribute('TotalAmount') !== $lessons * self::RATE
                    || $items->count() !== 1 || (int) $items->first()->getAttribute('Amount') !== $lessons * self::RATE) {
                    $e[] = "catchup_invoice_{$month}";
                }
            }
        }
        $o = $s['orphan'];
        if (!$o || $o->getAttribute('Status') !== 'void' || !str_contains((string) $o->getAttribute('Note'), self::REF)) {
            $e[] = 'orphan_invoice_not_void';
        }

        return $e;
    }

    /** @param array<string,mixed> $s */
    private function rebuiltSnapshot(array $s): array
    {
        $note = (string) $s['orphan']?->getAttribute('Note');
        $suffix = "\n" . self::REF;
        $old = $note === self::REF ? '' : (str_ends_with($note, $suffix) ? substr($note, 0, -strlen($suffix)) : $note);
        $invoices = collect($s['catchup_invoices'] ?? []);

        return ['contract_id' => (int) ($s['catchup_ids'][0] ?? 0),
            'invoice_ids' => $invoices->map(fn ($i) => (int) $i->getKey())->values()->all(),
            'item_ids' => $invoices->flatMap(fn ($i) => $i->getRelationValue('items')->map(fn ($x) => (int) $x->getKey()))->values()->all(),
            'orphan_invoice' => ['id' => self::ORPHAN_INVOICE, 'status' => 'unpaid', 'note' => $old]];
    }
}

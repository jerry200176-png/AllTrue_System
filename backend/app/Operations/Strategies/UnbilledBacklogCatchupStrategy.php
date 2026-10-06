<?php

namespace App\Operations\Strategies;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use App\Services\StudentClassPricingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generalised MuzhaChenBillingCatchupStrategy (Founder GO 2026-10-06). Monthly (date-mode) contracts whose attended lessons fall
 * outside [StartDate, EndDate] and that no invoice covers get, per contract and month, one catch-up contract cloned from the source
 * (period = the uncovered part of that month, Charge = sum of StudentClassPricingService::forDate per lesson), one unpaid invoice
 * and one item. Source contract, sessions, sign-ins and learning records are never touched (the catch-up contract owns no session).
 * The plan is computed live, scoped by campus_ids, and execute is bound to the dry-run by `expected_digest` (Td076 pattern).
 * A month with a lesson that has no per-session rate is `needs_director_amount`: reported, never executed.
 */
final class UnbilledBacklogCatchupStrategy
{
    private const REF = 'repair-unbilled-backlog-catchup-20261006';
    private const BILLABLE = ['attended', 'completed', 'late']; // MonthlyBillingService::BILLABLE_STATUSES
    private const COPY = ['GradeID', 'SubjectID', 'TeacherID', 'by1', 'Period', 'TotalHours', 'Pay', 'Rate', 'ClassType'];

    public function plan(array $parameters): array
    {
        $campuses = $this->campusIds($parameters['campus_ids'] ?? null);
        $want = (string) ($parameters['expected_digest'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== self::REF) $errors[] = 'case_parameters_mismatch';
        if ($campuses === []) $errors[] = 'campus_ids_required';
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) $errors[] = 'digest_format';
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        }
        $rows = $this->build($campuses);
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $ready = array_filter($rows, fn ($r) => $r['status'] === 'ready');
        $state = $want === '' ? 'unpinned' : ($ready === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));
        $totals = ['rows' => count($rows), 'ready_rows' => count($ready), 'ready_lessons' => array_sum(array_column($ready, 'lessons')),
            'ready_amount' => array_sum(array_column($ready, 'amount')), 'by_status' => array_count_values(array_column($rows, 'status'))];

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_ids' => $campuses, 'digest' => $digest, 'totals' => $totals, 'manifest' => $rows];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Nothing left to bill (retry after the execution record failed to persist). The rows this run created cannot be told
            // apart from earlier approved runs, so no snapshot is guessed: rollback would otherwise undo other runs. Operator resolves.
            return ['ok' => true, 'already_applied' => true, 'created' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') {
            throw new RuntimeException('unbilled_backlog_plan_not_pinned');
        }

        return DB::transaction(function () use ($plan, $context): array {
            StudentClass::query()->whereIn('ID', array_unique(array_column($plan['manifest'], 'contract_id')))->lockForUpdate()->get();
            $rows = $this->build($plan['campus_ids']);
            if (hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)) !== $plan['digest']) {
                throw new RuntimeException('unbilled_backlog_digest_drift');
            }
            $snapshot = [];
            foreach (array_filter($rows, fn ($r) => $r['status'] === 'ready') as $r) {
                $snapshot[] = $this->create($r);
            }
            $this->audit($snapshot[0]['contract_id'], count($plan['campus_ids']) === 1 ? $plan['campus_ids'][0] : null, ['reason_code' => self::REF,
                'operation_id' => (string) ($context['operation_id'] ?? ''), 'row_count' => count($snapshot),
                'outstanding_amount' => array_sum(array_column($snapshot, 'amount')), 'outcome' => 'success']);

            return ['ok' => true, 'created' => count($snapshot) * 3, 'snapshot' => ['rows' => $snapshot]];
        }, 3);
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        // Verify may run after drift without the plan gate; it still needs a plan that actually ran (fail closed otherwise).
        if (($plan['campus_ids'] ?? []) === []) $errors[] = 'verify_plan_missing';
        $done = [];
        foreach ($result['snapshot']['rows'] ?? [] as $r) {
            $done[$r['source_id'] . '|' . $r['month']] = true;
            $inv = Invoice::query()->with('items')->find($r['invoice_id']);
            $item = $inv?->getRelationValue('items')->first();
            if (!StudentClass::query()->whereKey($r['contract_id'])->exists() || !$inv || $inv->getAttribute('Status') === 'void'
                || (int) $inv->getAttribute('TotalAmount') !== $r['amount'] || $inv->getRelationValue('items')->count() !== 1
                || (int) $item->getAttribute('Amount') !== $r['amount']) {
                $errors[] = "catchup_invalid_{$r['source_id']}_{$r['month']}";
            }
        }
        foreach ($this->build($plan['campus_ids'] ?? []) as $r) {
            if (isset($done[$r['contract_id'] . '|' . $r['month']]) && in_array($r['status'], ['ready', 'needs_director_amount'], true)) {
                $errors[] = "still_uncovered_{$r['contract_id']}_{$r['month']}";
            }
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['one_contract_invoice_item_per_row', 'no_uncovered_executed_month']];
    }

    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        if (!is_array($rows)) {
            throw new RuntimeException('unbilled_backlog_rollback_snapshot_invalid');
        }

        return DB::transaction(function () use ($rows): array {
            $deleted = 0;
            $skipped = [];
            foreach ($rows as $r) {
                $cid = (int) $r['contract_id'];
                $inv = Invoice::query()->with(['payments', 'items'])->lockForUpdate()->find((int) $r['invoice_id']);
                $contract = StudentClass::query()->lockForUpdate()->find($cid);
                $item = $inv?->getRelationValue('items')->first();
                // Each created row must still equal what execute wrote; anything else changed since and is left alone.
                $invOk = !$inv || ((int) $inv->getAttribute('StudentClassID') === $cid && $inv->getAttribute('Status') === 'unpaid'
                    && (int) $inv->getAttribute('PaidAmount') === 0 && (int) $inv->getAttribute('TotalAmount') === $r['amount']
                    && $inv->getRelationValue('payments')->isEmpty() && $inv->getRelationValue('items')->count() === 1
                    && (int) $item->getKey() === (int) $r['item_id'] && (int) $item->getAttribute('Amount') === $r['amount']
                    && substr((string) $item->getAttribute('PeriodStart'), 0, 10) === $r['start']
                    && substr((string) $item->getAttribute('PeriodEnd'), 0, 10) === $r['end']
                    && !DB::table('payment_reports')->where('InvoiceID', $inv->getKey())->exists());
                // Identity: only a contract this operation created (Memo carries `[src:<source>]` + REF) is ever deleted.
                $memo = (string) ($contract?->getAttribute('Memo') ?? '');
                $cOk = !$contract || (str_contains($memo, '[src:' . (int) ($r['source_id'] ?? 0) . ']') && str_contains($memo, self::REF)
                    && (int) $contract->getAttribute('Paid') === 0 && (int) $contract->getAttribute('Charge') === $r['amount']
                    && Invoice::query()->where('StudentClassID', $cid)->count() === ($inv ? 1 : 0)
                    && InvoiceItem::query()->where('StudentClassID', $cid)->count() === ($inv ? 1 : 0)
                    && !DB::table('ClassSession')->where('StudentClassID', $cid)->exists()
                    && !DB::table('payment_reports')->where('StudentClassID', $cid)->exists());
                if (!$invOk || !$cOk) {
                    $skipped[] = "contract_{$cid}";
                    continue;
                }
                if ($inv) {
                    InvoiceItem::query()->where('InvoiceID', $inv->getKey())->delete();
                    $inv->delete();
                    $deleted++;
                }
                $contract?->delete();
                $deleted += $contract ? 1 : 0;
            }
            $this->audit((int) ($rows[0]['contract_id'] ?? 0), null, ['reason_code' => self::REF, 'row_count' => $deleted, 'outcome' => 'success'], '.rollback');

            // Any skipped row means the repair is only partly undone: surface it for operator resolution.
            return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'deleted' => $deleted, 'skipped_ids' => $skipped];
        }, 3);
    }

    /** Catch-up contracts of these students: `[src:<id>]` in Memo, recognised by the REF in Memo or in the invoice Note. */
    private function catchups(array $students)
    {
        $noted = Invoice::query()->whereIn('StudentID', $students)->where('Note', self::REF)->pluck('StudentClassID');

        // Both markers are required (staff can edit either field alone): Memo tag + REF AND an invoice Note = REF.
        return StudentClass::query()->whereIn('StudentID', $students)->where('Memo', 'like', '%[src:%')
            ->where('Memo', 'like', '%' . self::REF . '%')->whereIn('ID', $noted)->get();
    }

    /** @return list<int> */
    private function campusIds(mixed $raw): array
    {
        $raw = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        $ids = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $raw);

        return in_array(0, $ids, true) || count($ids) !== count($raw) ? [] : array_values(array_unique($ids));
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function create(array $r): array
    {
        $source = StudentClass::query()->findOrFail($r['contract_id']);
        $copy = [];
        foreach (self::COPY as $col) {
            if ($source->getAttribute($col) !== null) $copy[$col] = $source->getAttribute($col);
        }
        $amount = $r['amount'];
        $contract = StudentClass::query()->create($copy + [
            'StudentID' => $r['student_id'], 'ScheduleMode' => 'date', 'StartDate' => $r['start'], 'EndDate' => $r['end'],
            'Charge' => $amount, 'Paid' => 0, 'Stop' => 1, 'closed_reason' => 'settled_pending', 'SessionCount' => 0,
            'Memo' => "補收帳單合約：合約 {$r['contract_id']} 於 {$r['month']} 已上課 {$r['lessons']} 堂未開單，依單堂費率補開 [src:{$r['contract_id']}] " . self::REF]);
        $invoice = Invoice::query()->create(['StudentID' => $r['student_id'], 'StudentClassID' => $contract->getKey(),
            'IssueDate' => now()->toDateString(), 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
            'ScheduleModeAtIssue' => 'date', 'Note' => self::REF, 'billing_period' => $r['month']]);
        $item = InvoiceItem::query()->create(['InvoiceID' => $invoice->getKey(), 'StudentClassID' => $contract->getKey(),
            'Description' => "{$r['month']} 補收學費（{$r['lessons']} 堂）", 'Amount' => $amount, 'PeriodStart' => $r['start'], 'PeriodEnd' => $r['end']]);

        return ['source_id' => $r['contract_id'], 'contract_id' => (int) $contract->getKey(), 'invoice_id' => (int) $invoice->getKey(),
            'item_id' => (int) $item->getKey(), 'month' => $r['month'], 'amount' => $amount, 'start' => $r['start'], 'end' => $r['end']];
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(int $subjectId, ?int $campusId, array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append('pop.unbilled_backlog_catchup' . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'student_class',
            'subject_id' => $subjectId, 'campus_id' => $campusId, 'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) {
            throw new RuntimeException('unbilled_backlog_audit_not_persisted');
        }
    }

    /**
     * Ids-only manifest (no names): one row per contract and month that has attended lessons outside the contract dates.
     *
     * @param list<int> $campuses @return list<array<string,mixed>>
     */
    private function build(array $campuses): array
    {
        if ($campuses === []) {
            return [];
        }
        $sessions = DB::table('ClassSession as cs')->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')->whereIn('st.CampusID', $campuses)->where('sc.ScheduleMode', 'date')
            ->whereIn('cs.Status', self::BILLABLE)
            ->where(fn ($q) => $q->whereRaw('cs.SessionDate > sc.EndDate')->orWhereRaw('cs.SessionDate < sc.StartDate'))
            ->orderBy('cs.StudentClassID')->orderBy('cs.SessionDate')->orderBy('cs.id')->get(['cs.id as sid', 'cs.SessionDate as d', 'cs.StudentClassID as cid', 'st.CampusID as campus']);
        $ids = $sessions->pluck('cid')->unique()->values()->all();
        $contracts = StudentClass::query()->whereIn('ID', $ids)->get()->keyBy('ID');
        $owners = [];
        foreach ($this->catchups($contracts->pluck('StudentID')->unique()->all()) as $c) {
            if (preg_match('/\[src:(\d+)\]/', (string) $c->getAttribute('Memo'), $m)) $owners[(int) $m[1]][] = (int) $c->getKey();
        }
        $allOwners = array_merge($ids, ...array_values($owners));
        $invoices = Invoice::query()->where(fn ($v) => $v->whereNull('Status')->orWhere('Status', '!=', 'void'))->with('items')
            ->where(fn ($w) => $w->whereIn('StudentClassID', $allOwners)->orWhereHas('items', fn ($i) => $i->whereIn('StudentClassID', $allOwners)))->get();
        // Other contracts' coverage (same student + subject): non-void invoices anchored elsewhere, and live date-mode contracts.
        $students = $contracts->pluck('StudentID')->unique()->all();
        $studentInvoices = Invoice::query()->with('items')->whereIn('StudentID', $students)->where(fn ($v) => $v->whereNull('Status')->orWhere('Status', '!=', 'void'))->get();
        $subjects = StudentClass::query()->whereIn('ID', $studentInvoices->pluck('StudentClassID')->filter()->unique())->pluck('SubjectID', 'ID');
        $dated = StudentClass::query()->whereIn('StudentID', $students)->where('ScheduleMode', 'date')->where('Stop', 0)->get();
        $pricing = app(StudentClassPricingService::class);

        $rows = [];
        foreach ($sessions->groupBy(fn ($s) => $s->cid . '|' . substr((string) $s->d, 0, 7)) as $key => $group) {
            [$cid, $month] = explode('|', $key);
            $c = $contracts[(int) $cid];
            $dates = $group->map(fn ($s) => substr((string) $s->d, 0, 10))->all();
            $start = substr((string) $c->getAttribute('StartDate'), 0, 10);
            $end = substr((string) $c->getAttribute('EndDate'), 0, 10);
            $after = array_filter($dates, fn ($d) => $d > $end);
            $monthStart = "{$month}-01";
            $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();
            $from = $after ? max($monthStart, Carbon::parse($end)->addDay()->toDateString()) : $monthStart;
            $to = $after ? $monthEnd : min($monthEnd, Carbon::parse($start)->subDay()->toDateString());
            $own = [(int) $cid, ...($owners[(int) $cid] ?? [])];
            $covered = count(array_filter($dates, fn ($d) => $this->covered($d, $invoices, $own)));
            $other = count(array_filter($dates, fn ($d) => $this->covered($d, $invoices, $own) || $this->otherCovers($d, $c, $own, $studentInvoices, $subjects, $dated)));
            $rates = array_map(fn ($d) => $pricing->forDate($c, $d), $dates);
            $reason = match (true) {
                (int) ($c->getAttribute('PackageID') ?? 0) > 0 => 'package',
                in_array(strtolower(trim((string) $c->getAttribute('ClassType'))), ['tutoring', 'trial'], true) => 'tutoring_or_trial',
                $c->getAttribute('closed_reason') === 'waived' => 'waived',
                (int) $c->getAttribute('Stop') !== 0 && $c->getAttribute('closed_reason') !== 'settled_pending' => 'contract_closed',
                $covered === count($dates) => 'already_billed',
                $other === count($dates) => 'other_contract_covers_month',
                $other > 0 => 'partially_billed',
                $after && count($after) !== count($dates) => 'lessons_on_both_sides',
                default => null,
            };
            $priced = array_filter($rates, fn ($p) => $p['rate'] > 0 && $p['rate_unit'] === 'session') === $rates;
            $rows[] = ['contract_id' => (int) $cid, 'student_id' => (int) $c->getAttribute('StudentID'), 'campus_id' => (int) $group[0]->campus,
                'month' => $month, 'start' => $from, 'end' => $to, 'session_ids' => $group->pluck('sid')->map(fn ($v) => (int) $v)->all(),
                'lessons' => count($dates), 'amount' => $priced ? array_sum(array_column($rates, 'rate')) : null,
                'status' => $reason ? 'skipped' : ($priced ? 'ready' : 'needs_director_amount'), 'reason' => $reason];
        }
        usort($rows, fn ($a, $b) => [$a['campus_id'], $a['contract_id'], $a['month']] <=> [$b['campus_id'], $b['contract_id'], $b['month']]);

        return $rows;
    }

    /** Same student + subject but another contract: its invoice for that month/period, or its live date-mode contract range, holds the date. */
    private function otherCovers(string $date, StudentClass $c, array $own, $invoices, $subjects, $dated): bool
    {
        $subject = $c->getAttribute('SubjectID');
        if ($subject === null) return false;
        foreach ($invoices as $inv) {
            $anchor = (int) $inv->getAttribute('StudentClassID');
            if ((int) $inv->getAttribute('StudentID') !== (int) $c->getAttribute('StudentID') || in_array($anchor, $own, true)
                || (int) ($subjects[$anchor] ?? -1) !== (int) $subject) continue;
            // Same rule as covered(): dated items decide; billing_period only for an invoice without dated items.
            $items = $inv->getRelationValue('items')->filter(fn ($i) => $i->getAttribute('PeriodStart') && $i->getAttribute('PeriodEnd'));
            if ($items->isEmpty()) {
                if ((string) $inv->getAttribute('billing_period') === substr($date, 0, 7)) return true;
                continue;
            }
            foreach ($items as $i) {
                if (substr((string) $i->getAttribute('PeriodStart'), 0, 10) <= $date && $date <= substr((string) $i->getAttribute('PeriodEnd'), 0, 10)) return true;
            }
        }
        foreach ($dated as $o) {
            if ((int) $o->getKey() !== (int) $c->getKey() && !in_array((int) $o->getKey(), $own, true) && (int) $o->getAttribute('StudentID') === (int) $c->getAttribute('StudentID')
                && (int) $o->getAttribute('SubjectID') === (int) $subject && substr((string) $o->getAttribute('StartDate'), 0, 10) <= $date && $date <= substr((string) $o->getAttribute('EndDate'), 0, 10)) return true;
        }

        return false;
    }

    /** Covered = an owned item (null item owner = the invoice anchor) whose period holds the date; period-less invoices cover by billing_period. */
    private function covered(string $date, $invoices, array $own): bool
    {
        foreach ($invoices as $inv) {
            $anchor = (int) $inv->getAttribute('StudentClassID');
            $items = $inv->getRelationValue('items')->filter(fn ($i) => in_array((int) ($i->getAttribute('StudentClassID') ?: $anchor), $own, true)
                && $i->getAttribute('PeriodStart') && $i->getAttribute('PeriodEnd'));
            if ($items->isEmpty()) {
                if (in_array($anchor, $own, true) && (string) $inv->getAttribute('billing_period') === substr($date, 0, 7)) return true;
                continue;
            }
            foreach ($items as $i) {
                if (substr((string) $i->getAttribute('PeriodStart'), 0, 10) <= $date && $date <= substr((string) $i->getAttribute('PeriodEnd'), 0, 10)) return true;
            }
        }

        return false;
    }
}

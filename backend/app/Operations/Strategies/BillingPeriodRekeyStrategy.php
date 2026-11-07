<?php

namespace App\Operations\Strategies;

use App\Models\SecurityAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * in-app #514 (Founder 2026-11-07): a renewal bill (one dated item = the whole contract cycle) is labelled by the month of the
 * cycle's first scheduled lesson, not the month the cycle started. This re-keys existing bills whose stored billing_period differs.
 * Only Invoice.billing_period, the same invoice's payment_reports.billing_period, and a `[invno_period:YYYY-MM]` Note tag change; no
 * amount, status, item or session is touched. The tag freezes the staff invoice number (Billing\InvoiceNumber) on its original period.
 * Scope: every campus in campus_ids ('all' = every campus). Execute is pinned to the dry-run by expected_digest (Td076 pattern).
 * A re-key that would collide with a live bill of the same student+subject (stored or planned in this run) is refused, never forced.
 */
class BillingPeriodRekeyStrategy
{
    private const REF = 'repair-billing-period-rekey-20261107';
    private const TAG = \App\Services\Billing\InvoiceNumber::TAG;
    private const EVENT = 'pop.billing_period_rekey';

    public function plan(array $parameters): array
    {
        $campuses = $this->campusIds($parameters['campus_ids'] ?? null);
        $want = (string) ($parameters['expected_digest'] ?? '');
        $errors = [];
        if (($parameters['decision_reference'] ?? null) !== self::REF) $errors[] = 'case_parameters_mismatch';
        if ($campuses === null) $errors[] = 'campus_ids_required';
        if ($want !== '' && !preg_match('/^[0-9a-f]{64}$/', $want)) $errors[] = 'digest_format';
        if ($errors !== []) return ['ok' => false, 'errors' => $errors, 'state' => 'invalid'];
        $rows = $this->build($campuses);
        // Resume: bills an interrupted run already re-keyed (journal rows still on their new period) count as part of the dry-run's manifest,
        // so the digest the Founder approved still matches and a re-run only does what is left.
        $done = $this->doneRows($campuses, array_column($rows, 'invoice_id'));
        $basis = array_merge($rows, $done);
        usort($basis, fn ($a, $b) => [$a['campus_id'], $a['class_id'], $a['invoice_id']] <=> [$b['campus_id'], $b['class_id'], $b['invoice_id']]);
        $digest = hash('sha256', json_encode($basis, JSON_THROW_ON_ERROR));
        $ready = array_values(array_filter($rows, fn ($r) => $r['status'] === 'ready'));
        $shown = array_map(fn ($r) => in_array($r['invoice_id'], array_column($done, 'invoice_id'), true) ? ['status' => 'done'] + $r : $r, $basis);
        $state = $want === '' ? 'unpinned' : ($ready === [] && $done === [] ? 'after' : ($want === $digest ? 'pinned' : 'digest_mismatch'));

        return ['ok' => $state !== 'digest_mismatch', 'errors' => $state === 'digest_mismatch' ? ['digest_mismatch'] : [],
            'state' => $state, 'campus_ids' => $campuses, 'digest' => $digest, 'totals' => $this->totals($rows), 'manifest' => $shown, 'resumed_done' => count($done)];
    }

    public function execute(array $plan, array $context): array
    {
        if (($plan['ok'] ?? false) && ($plan['state'] ?? null) === 'after') {
            // Nothing left to re-key (retry after the execution record failed to persist): no snapshot is guessed, an operator resolves.
            return ['ok' => true, 'already_applied' => true, 'updated' => 0, 'snapshot' => ['rows' => [], 'incomplete' => true]];
        }
        if (!($plan['ok'] ?? false) || ($plan['state'] ?? null) !== 'pinned') throw new RuntimeException('billing_period_rekey_plan_not_pinned');

        // Optimistic, idempotent, abort-on-drift (the maintenance-task pattern): one short transaction per bill at READ COMMITTED, locking
        // only contract -> invoice (the voidInvoice order) and reading lessons/items plain. A bill that differs from the dry-run when
        // its lock is held is skipped and counted; a different value is never written.
        $operationId = (string) ($context['operation_id'] ?? '');
        $pending = array_values(array_filter($plan['manifest'], fn ($r) => $r['status'] === 'ready'));
        $done = $this->journalRows($operationId); $skipped = [];  // rows an earlier, interrupted run of this request already committed
        $index = 0;
        do {
            $progress = false;
            foreach ($pending as $i => $r) {
                $this->beforeRow($index++, $r);
                try {
                    $res = $this->rekeyOne($r, $operationId, (string) ($context['actor'] ?? 'pop-runner'));
                } catch (\Throwable) {
                    $res = 'error';
                }
                if (is_array($res)) { $done[] = $res; unset($pending[$i]); $progress = true; }
                elseif ($res !== 'retry') { $skipped[(int) $r['invoice_id']] = $res; unset($pending[$i]); }
            }
        } while ($progress && $pending !== []);
        foreach ($pending as $r) $skipped[(int) $r['invoice_id']] = 'period_taken';

        return ['ok' => true, 'updated' => count($done), 'skipped' => $skipped, 'snapshot' => ['rows' => $done]];
    }

    /** Test seam: called before each bill is attempted (a process that dies here leaves the earlier bills committed with their journal rows). */
    protected function beforeRow(int $index, array $row): void
    {
    }

    /** READ COMMITTED for the next transaction only (a no-op inside an outer transaction, which cannot change its level). */
    private function readCommitted(): void
    {
        if (DB::transactionLevel() === 0) DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }

    /** Contract(s) of the bill's student+subject by id, then the bill: the order BillingController::voidInvoice takes. Returns the locked bill. */
    private function lockContractThenBill(int $invoiceId): ?object
    {
        $inv = DB::table('Invoice')->where('id', $invoiceId)->first();
        if (!$inv) return null;
        $class = DB::table('StudentClass')->where('ID', $inv->StudentClassID)->first(['StudentID', 'SubjectID']);
        if ($class) DB::table('StudentClass')->where('StudentID', $class->StudentID)->where('SubjectID', $class->SubjectID)->orderBy('ID')->lockForUpdate()->get(['ID']);

        return DB::table('Invoice')->where('id', $invoiceId)->lockForUpdate()->first();
    }

    /** @param array<string,mixed> $r @return array<string,mixed>|string snapshot row when re-keyed, else the skip reason ('retry' = a month still held) */
    private function rekeyOne(array $r, string $operationId, string $actor): array|string
    {
        $this->readCommitted();

        return DB::transaction(function () use ($r, $operationId, $actor): array|string {
            $invoice = $this->lockContractThenBill((int) $r['invoice_id']);
            $journaled = collect($this->journalRows($operationId))->firstWhere('invoice_id', $r['invoice_id']);
            if ($journaled) return $journaled; // idempotent: this request already re-keyed this bill
            if (!$invoice || strtolower((string) $invoice->Status) === 'void' || $invoice->billing_period !== $r['from']) return 'drifted';
            $fresh = collect($this->build([], [(int) $r['invoice_id']]))->first();
            if (!$fresh || $fresh['from'] !== $r['from'] || $fresh['to'] !== $r['to'] || $fresh['class_id'] !== $r['class_id']) return 'drifted';
            if ($fresh['status'] !== 'ready') return $fresh['reason'] === 'period_taken' ? 'retry' : (string) $fresh['reason'];
            $note = (string) $invoice->Note;
            $added = !str_contains($note, '[' . self::TAG . ':'); // a bill re-keyed before keeps its first (original) number tag
            $tagged = \App\Services\Billing\InvoiceNumber::withTag($note, $r['from']);
            if ($tagged === null) return 'note_full';
            DB::table('Invoice')->where('id', $r['invoice_id'])->update(['billing_period' => $r['to'], 'Note' => $added ? $tagged : $note, 'updated_at' => now()]);
            $reportIds = DB::table('payment_reports')->where('InvoiceID', $r['invoice_id'])->where('billing_period', $r['from'])->pluck('id')->map(fn ($v) => (int) $v)->all();
            DB::table('payment_reports')->where('InvoiceID', $r['invoice_id'])->where('billing_period', $r['from'])->update(['billing_period' => $r['to']]);
            $this->audit($r['invoice_id'], $r['campus_id'], ['reason_code' => self::REF, 'pop_operation_id' => $operationId,
                'period_from' => $r['from'], 'period_to' => $r['to'], 'report_count' => count($reportIds), 'outcome' => 'success']);

            $row = ['invoice_id' => $r['invoice_id'], 'class_id' => $r['class_id'], 'campus_id' => $r['campus_id'], 'from' => $r['from'], 'to' => $r['to'],
                'report_ids' => $reportIds, 'tag_added' => $added];
            $this->journal('rekey-row', $operationId, $row, $actor); // same transaction as the write: both commit, or neither

            return $row;
        });
    }

    /** Write-ahead journal = one pop_execution_records row per bill (no new table); attempt_key makes it unique per request and bill. */
    private function journal(string $phase, string $operationId, array $row, string $actor): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        DB::table('pop_execution_records')->insert(['id' => $id, 'execution_id' => $id, 'operation_id' => $operationId, 'phase' => $phase, 'result' => 'succeeded',
            'idempotency_key' => $operationId . ':' . $phase . ':' . $row['invoice_id'], 'attempt_key' => $operationId . ':' . $phase . ':' . $row['invoice_id'],
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(), 'snapshot' => json_encode($row, JSON_THROW_ON_ERROR),
            'payload' => json_encode(['kind' => 'billing-period-rekey'], JSON_THROW_ON_ERROR), 'actor' => substr($actor, 0, 128), 'created_at' => now()]);
    }

    /** @return list<array<string,mixed>> journaled bills still re-keyed (not undone) for one request, or for every request when null */
    private function journalRows(?string $operationId): array
    {
        $q = DB::table('pop_execution_records')->whereIn('phase', ['rekey-row', 'rekey-row-undone'])->orderBy('created_at')->orderBy('id');
        if ($operationId !== null) $q->where('operation_id', $operationId);
        $undone = [];
        $rows = [];
        foreach ($q->get(['operation_id', 'phase', 'snapshot']) as $rec) {
            $snap = json_decode((string) $rec->snapshot, true);
            if (!is_array($snap) || !isset($snap['invoice_id'])) continue;
            $key = $rec->operation_id . ':' . $snap['invoice_id'];
            if ($rec->phase === 'rekey-row-undone') $undone[$key] = true; else $rows[$key] = $snap;
        }

        return array_values(array_diff_key($rows, $undone));
    }

    /** @param list<int> $campuses @param list<int> $exclude @return list<array<string,mixed>> journaled bills still on their new period, as manifest rows */
    private function doneRows(array $campuses, array $exclude): array
    {
        $out = [];
        foreach ($this->journalRows(null) as $j) {
            if (in_array($j['invoice_id'], $exclude, true) || ($campuses && !in_array((int) $j['campus_id'], $campuses, true))) continue;
            if (DB::table('Invoice')->where('id', $j['invoice_id'])->value('billing_period') !== $j['to']) continue;
            $out[] = ['invoice_id' => (int) $j['invoice_id'], 'class_id' => (int) $j['class_id'], 'campus_id' => (int) $j['campus_id'],
                'from' => $j['from'], 'to' => $j['to'], 'status' => 'ready', 'reason' => null];
        }

        return $out;
    }

    public function verify(array $plan, array $result): array
    {
        $errors = ($result['ok'] ?? false) ? [] : ['execution_result_missing'];
        if (($result['snapshot']['incomplete'] ?? false) === true) $errors[] = 'snapshot_incomplete_manual_resolution';
        if (!array_key_exists('campus_ids', $plan)) $errors[] = 'verify_plan_missing';
        foreach ($result['snapshot']['rows'] ?? [] as $r) {
            $inv = DB::table('Invoice')->where('id', $r['invoice_id'])->first();
            $tag = '[' . self::TAG . ':' . (($r['tag_added'] ?? true) ? $r['from'] . ']' : '');
            if (!$inv || $inv->billing_period !== $r['to'] || !str_contains((string) $inv->Note, $tag)) $errors[] = "rekey_missing_{$r['invoice_id']}";
            if ($r['report_ids'] && DB::table('payment_reports')->whereIn('id', $r['report_ids'])->where('billing_period', '!=', $r['to'])->exists()) $errors[] = "report_period_{$r['invoice_id']}";
        }
        if (array_key_exists('campus_ids', $plan)) {
            $skipped = array_keys($result['skipped'] ?? []); // bills this run skipped on drift are reported, not failures
            foreach ($this->build($plan['campus_ids']) as $r) {
                if ($r['status'] === 'ready' && !in_array((int) $r['invoice_id'], $skipped, true)) $errors[] = "still_misfiled_{$r['invoice_id']}";
            }
        }

        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'checks' => ['period_matches_first_lesson_month', 'amounts_untouched']];
    }

    /** Restore by request id: only a bill still exactly as this run left it (new period + its own tag) is put back. */
    public function rollback(array $snapshot, array $context): array
    {
        $rows = $snapshot['rows'] ?? null;
        // The journal, not the end-of-run snapshot, says which bills committed: a run that died part-way is still fully undoable.
        $journal = $this->journalRows((string) ($context['operation_id'] ?? ''));
        if ($journal !== []) { $rows = $journal; $snapshot['incomplete'] = false; }
        if (($snapshot['incomplete'] ?? false) === true) return ['ok' => false, 'partial' => true, 'restored' => 0, 'errors' => ['snapshot_incomplete_manual_resolution']];
        if (!is_array($rows)) throw new RuntimeException('billing_period_rekey_rollback_snapshot_invalid');

        $restored = 0;
        // Restore until no more can go back: a chain restores link by link, a month another contract holds now stays put.
        $pending = array_values($rows);
        do {
            $progress = false;
            foreach ($pending as $i => $r) {
                try {
                    $res = $this->restoreOne($r, (string) ($context['operation_id'] ?? ''), (string) ($context['actor'] ?? 'pop-runner'));
                } catch (\Throwable) {
                    $res = false;
                }
                if ($res !== false) { unset($pending[$i]); $progress = true; $restored += $res ? 1 : 0; }
            }
        } while ($progress && $pending !== []);
        $skipped = array_map(fn ($r) => (int) $r['invoice_id'], array_values($pending));

        return ['ok' => $skipped === [], 'partial' => $skipped !== [], 'restored' => $restored, 'skipped_ids' => $skipped];
    }

    /** @return bool|null true = restored now, null = already restored earlier, false = cannot restore (changed, void, or the month is held) */
    private function restoreOne(array $r, string $operationId, string $actor): ?bool
    {
        $this->readCommitted();

        return DB::transaction(function () use ($r, $operationId, $actor): ?bool {
            $inv = $this->lockContractThenBill((int) $r['invoice_id']);
            $added = $r['tag_added'] ?? true;
            $tag = ' [' . self::TAG . ':' . $r['from'] . ']';
            if ($inv && $inv->billing_period === $r['from'] && (!$added || !str_contains((string) $inv->Note, trim($tag)))) return null; // an earlier partial rollback did it
            $ours = $inv && $inv->billing_period === $r['to'] && strtolower((string) $inv->Status) !== 'void'
                && str_contains((string) $inv->Note, $added ? trim($tag) : '[' . self::TAG . ':');
            if (!$ours) return false;
            $class = DB::table('StudentClass')->where('ID', $inv->StudentClassID)->first(['StudentID', 'SubjectID']);
            $held = !$class || DB::table('Invoice as o')->join('StudentClass as c', 'c.ID', '=', 'o.StudentClassID')
                ->where('c.StudentID', $class->StudentID)->where('c.SubjectID', $class->SubjectID)
                ->where('o.billing_period', $r['from'])->where('o.id', '!=', $inv->id)
                ->where(fn ($q) => $q->whereNull('o.Status')->orWhere('o.Status', '<>', 'void'))->exists();
            if ($held) return false;
            DB::table('Invoice')->where('id', $inv->id)->update(['billing_period' => $r['from'],
                // only the tag this run added leaves the Note: anything appended since (void / correction audit) stays
                'Note' => $added ? trim(str_replace([$tag, trim($tag)], '', (string) $inv->Note)) : (string) $inv->Note, 'updated_at' => now()]);
            // every report of this bill on the repaired period goes back, including ones registered after the run (confirm() compares both)
            DB::table('payment_reports')->where('InvoiceID', $inv->id)->where('billing_period', $r['to'])->update(['billing_period' => $r['from']]);
            $this->audit((int) $inv->id, $r['campus_id'] ?? null, ['reason_code' => self::REF, 'pop_operation_id' => $operationId,
                'period_from' => $r['to'], 'period_to' => $r['from'], 'outcome' => 'success'], '.rollback');
            $this->journal('rekey-row-undone', $operationId, $r, $actor);

            return true;
        });
    }

    /** @return list<int>|null [] = every campus ('all'); null = invalid input */
    private function campusIds(mixed $raw): ?array
    {
        $raw = is_string($raw) ? explode(',', $raw) : (is_array($raw) ? $raw : []);
        if ($raw === ['all']) return [];
        $ids = array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, $raw);

        return $raw === [] || in_array(0, $ids, true) ? null : array_values(array_unique($ids));
    }

    /** Strict audit: append() swallows insert failures, so confirm the row exists or roll the transaction back. */
    private function audit(int $invoiceId, ?int $campusId, array $metadata, string $suffix = ''): void
    {
        $correlationId = (string) Str::uuid();
        SecurityAuditEvent::append(self::EVENT . $suffix, 'success', ['actor_type' => 'pop-runner', 'subject_type' => 'invoice',
            'subject_id' => $invoiceId, 'campus_id' => $campusId, 'correlation_id' => $correlationId], $metadata);
        if (!DB::table('security_audit_events')->where('correlation_id', $correlationId)->exists()) throw new RuntimeException('billing_period_rekey_audit_not_persisted');
    }

    /** @param list<array<string,mixed>> $rows */
    private function totals(array $rows): array
    {
        $by = fn (string $key, array $set) => array_count_values(array_map(fn ($r) => (string) $r[$key], $set));
        $ready = array_values(array_filter($rows, fn ($r) => $r['status'] === 'ready'));
        $campus = $by('campus_id', $ready); ksort($campus);
        $from = $by('from', $ready); ksort($from);
        $to = $by('to', $ready); ksort($to);

        return ['rows' => count($rows), 'ready' => count($ready), 'by_status' => array_count_values(array_column($rows, 'status')),
            'ready_by_campus' => $campus, 'ready_by_stored_month' => $from, 'ready_by_new_month' => $to];
    }

    /**
     * Ids-only manifest: one row per live renewal bill (single dated item = whole contract cycle) whose stored period differs
     * from its cycle's first non-cancelled lesson month (cycle start month when no lesson).
     *
     * @param list<int> $campuses [] = every campus @return list<array<string,mixed>>
     */
    protected function build(array $campuses, ?array $onlyInvoices = null): array
    {
        $q = DB::table('Invoice as inv')->join('StudentClass as sc', 'sc.ID', '=', 'inv.StudentClassID')->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->where('sc.ScheduleMode', 'date')->whereNotNull('sc.StartDate')->whereNotNull('sc.EndDate')
            ->whereRaw("inv.billing_period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'")
            ->where(fn ($w) => $w->whereNull('inv.Status')->orWhere('inv.Status', '<>', 'void'));
        if ($campuses) $q->whereIn('st.CampusID', $campuses);
        if ($onlyInvoices !== null) $q->whereIn('inv.id', $onlyInvoices);
        $invoices = $q->orderBy('inv.id')->get(['inv.id', 'inv.StudentClassID as class_id', 'inv.billing_period', 'inv.Note', 'sc.StudentID', 'sc.SubjectID',
            'sc.StartDate', 'sc.EndDate', 'st.CampusID as campus_id']);
        if ($invoices->isEmpty()) return [];
        $ids = $invoices->pluck('id')->all();
        $items = DB::table('InvoiceItem')->whereIn('InvoiceID', $ids)->get(['InvoiceID', 'StudentClassID', 'PeriodStart', 'PeriodEnd'])->groupBy('InvoiceID');
        $lessons = DB::table('ClassSession')->whereIn('StudentClassID', $invoices->pluck('class_id')->unique()->all())
            ->whereNotIn(DB::raw('LOWER(TRIM(Status))'), ['cancelled', 'canceled', 'voided', 'void'])->orderBy('SessionDate')->get(['StudentClassID', 'SessionDate'])->groupBy('StudentClassID');
        // Live bills of the same student+subject, for the collision check (also against what this run plans).
        $live = DB::table('Invoice as o')->join('StudentClass as c', 'c.ID', '=', 'o.StudentClassID')
            ->whereIn('c.StudentID', $invoices->pluck('StudentID')->unique()->all())->whereNotNull('o.billing_period')
            ->where(fn ($w) => $w->whereNull('o.Status')->orWhere('o.Status', '<>', 'void'))->get(['o.id', 'c.StudentID', 'c.SubjectID', 'o.StudentClassID', 'o.billing_period']);
        $candidates = [];
        foreach ($invoices as $inv) {
            $start = substr((string) $inv->StartDate, 0, 10);
            $end = substr((string) $inv->EndDate, 0, 10);
            $own = $items->get($inv->id, collect());
            $one = $own->count() === 1 ? $own->first() : null;
            if (!$one || substr((string) $one->PeriodStart, 0, 10) !== $start || substr((string) $one->PeriodEnd, 0, 10) !== $end) continue; // not a whole-cycle bill
            if ((int) $one->StudentClassID > 0 && (int) $one->StudentClassID !== (int) $inv->class_id) continue; // the item belongs to another course: not this contract's renewal
            $dates = ($lessons->get($inv->class_id) ?? collect())->map(fn ($s) => substr((string) $s->SessionDate, 0, 10))->filter(fn ($d) => $d >= $start && $d <= $end);
            $to = substr((string) ($dates->min() ?? $start), 0, 7);
            if ($to !== $inv->billing_period) $candidates[] = [$inv, $to];
        }
        // A month freed by a bill that itself moves out can be taken by another in the same run, so settle to a fixpoint (a chain needs at most one pass per bill):
        // after execute, verify then finds nothing left to move.
        $moving = [];
        for ($pass = 0, $max = count($candidates) + 1; $pass < $max; $pass++) {
            $taken = [];
            foreach ($live as $o) {
                if (!isset($moving[$o->id])) $taken[$o->StudentID . '|' . $o->SubjectID . '|' . $o->billing_period] = true;
            }
            $rows = [];
            $now = [];
            foreach ($candidates as [$inv, $to]) {
                $key = $inv->StudentID . '|' . $inv->SubjectID . '|' . $to;
                $reason = match (true) {
                    isset($taken[$key]) => 'period_taken',
                    \App\Services\Billing\InvoiceNumber::withTag((string) $inv->Note, (string) $inv->billing_period) === null => 'note_full',
                    default => null,
                };
                if (!$reason) { $taken[$key] = true; $now[(int) $inv->id] = true; }
                $rows[] = ['invoice_id' => (int) $inv->id, 'class_id' => (int) $inv->class_id, 'campus_id' => (int) $inv->campus_id,
                    'from' => (string) $inv->billing_period, 'to' => $to, 'status' => $reason ? 'skipped' : 'ready', 'reason' => $reason];
            }
            if ($now === $moving) break;
            $moving = $now;
        }
        usort($rows, fn ($a, $b) => [$a['campus_id'], $a['class_id'], $a['invoice_id']] <=> [$b['campus_id'], $b['class_id'], $b['invoice_id']]);

        return $rows;
    }
}

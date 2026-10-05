<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\SessionCorrection;
use App\Models\StudentClass;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk repair (2026-10-05, Founder chose "全部放進待對帳"): contracts closed as settled/completed while still
 * unpaid vanished from 帳務中心 and tuition alerts (renewMonthly / monthly pause wrote the reason with no
 * payment check; fixed in #3529). This flips only closed_reason -> 'settled_pending' so they re-enter the
 * reconciliation queue. Money (Invoice / Payment / Charge / Paid) is never touched.
 *
 * Default dry-run prints the candidates and a MANIFEST_JSON line. That list is committed as the immutable
 * Repair Manifest (operations/repairs/unpaid-hidden-closures.manifest.json) through a Founder-approved PR.
 * --execute only touches manifest rows, requires --expect-manifest-sha (sha256 of the committed file) and
 * fails closed unless every manifest row is still a live candidate with the same reason. Production also
 * needs --force + ALLOW_PROD_REPAIR=1. Ledger: one session_corrections row (session_id 0 = contract-level
 * key) whose snapshot_before holds the manifest hash and every old reason for --rollback.
 *
 * Paid=1 rows whose invoices are not covered by payment rows are listed as STALE_PAID_REVIEW only: the
 * director record flow rejects Paid=1, so they need a reviewed correction, not this flag flip.
 */
class RepairUnpaidHiddenClosures extends Command
{
    protected $signature = 'repair:unpaid-hidden-closures
                            {--execute}
                            {--verify}
                            {--rollback}
                            {--force}
                            {--manifest=operations/repairs/unpaid-hidden-closures.manifest.json}
                            {--expect-manifest-sha=}
                            {--actor=}';

    protected $description = 'Move unpaid contracts closed as settled/completed back to settled_pending (待對帳)';

    private const REF = 'repair-unpaid-hidden-closures';
    private const KEY_SESSION = 0;
    private const VISIBLE = ['settled_pending', 'contract_amended', 'waived'];

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }
        if ($this->option('rollback')) {
            return $this->rollback($execute);
        }
        $applied = $this->openCorrection();
        if ($applied) {
            $errors = $this->postErrors($applied);
            $this->line('REPAIR_STATE=ALREADY_APPLIED correction_id=' . $applied->id);
            foreach ($errors as $e) {
                $this->error('VERIFY: ' . $e);
            }

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($this->option('verify')) {
            $this->error('VERIFY: repair not applied (no open correction)');

            return self::FAILURE;
        }

        [$rows, $stale] = $this->candidates();
        if (!$execute) {
            $this->line('=== DRY RUN ' . self::REF . ' ===');
            foreach ($rows as $r) {
                $this->line(sprintf('course=%d student=%d campus=%s mode=%s reason=%s outstanding=%d end=%s',
                    $r['id'], $r['student_id'], $r['campus_id'] ?? '-', $r['mode'], $r['closed_reason'], $r['outstanding'], $r['end_date']));
            }
            foreach ($stale as $r) {
                $this->line(sprintf('STALE_PAID_REVIEW course=%d campus=%s reason=%s outstanding=%d', $r['id'], $r['campus_id'] ?? '-', $r['closed_reason'], $r['outstanding']));
            }
            $this->line('CANDIDATES=' . count($rows) . ' OUTSTANDING_TOTAL=' . array_sum(array_column($rows, 'outstanding')) . ' STALE_PAID=' . count($stale));
            $this->line('MANIFEST_JSON=' . json_encode(self::manifestRows($rows), JSON_THROW_ON_ERROR));
            $this->line('Dry-run complete; no data changed.');

            return self::SUCCESS;
        }

        $this->line('=== EXECUTE ' . self::REF . ' ===');
        [$manifest, $sha, $error] = $this->loadManifest();
        if ($error !== null) {
            $this->line('REPAIR_STATE=NOT_APPLIED');
            $this->error('MANIFEST: ' . $error);

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($manifest, $sha): void {
                $ids = array_column($manifest, 'id');
                StudentClass::query()->whereIn('ID', $ids)->lockForUpdate()->get();
                $drift = $this->drift($manifest, $this->candidates()[0]);
                if ($drift !== []) {
                    throw new \RuntimeException('manifest rows no longer candidates: ' . implode(',', $drift));
                }
                $n = DB::table('StudentClass')->whereIn('ID', $ids)->whereIn('closed_reason', ['settled', 'completed'])
                    ->update(['closed_reason' => 'settled_pending']);
                if ($n !== count($ids)) {
                    throw new \RuntimeException("updated {$n} of " . count($ids));
                }
                (new SessionCorrection([
                    'session_id' => self::KEY_SESSION, 'replaced_by_session_id' => null,
                    'correction_reason' => 'unpaid_hidden_closure_repair', 'decision_reference' => self::REF,
                    'decided_at' => now(), 'decided_by_actor' => substr((string) ($this->option('actor') ?: 'cli'), 0, 128),
                    'previous_status' => 'settled_or_completed', 'new_status' => 'settled_pending',
                    'snapshot_before' => ['manifest_sha256' => $sha, 'rows' => $manifest],
                ]))->save();
                SecurityAuditEvent::append('repair.unpaid_hidden_closures', 'applied', [
                    'actor_type' => 'system', 'subject_type' => 'student_class_batch',
                ], ['reason_code' => self::REF, 'outcome' => 'applied']);
            });
        } catch (\Throwable $e) {
            $this->line('REPAIR_STATE=NOT_APPLIED');
            $this->error('DRIFT: ' . $e->getMessage());

            return self::FAILURE;
        }
        $errors = $this->postErrors($this->openCorrection());
        foreach ($errors as $e) {
            $this->error('VERIFY: ' . $e);
        }
        $this->line($errors === [] ? 'REPAIR_STATE=APPLIED_AND_VERIFIED' : 'REPAIR_STATE=APPLIED_POSTVERIFY_FAILED');

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{0:list<array<string,mixed>>,1:?string,2:?string} rows, sha256, error */
    private function loadManifest(): array
    {
        $relative = ltrim((string) $this->option('manifest'), '/');
        $path = base_path('../' . $relative);
        if (str_contains($relative, '..') || !is_file($path)) {
            return [[], null, 'manifest file not found'];
        }
        $raw = (string) file_get_contents($path);
        $sha = hash('sha256', $raw);
        if (!hash_equals($sha, (string) $this->option('expect-manifest-sha'))) {
            return [[], $sha, 'sha256 mismatch: file is ' . $sha];
        }
        $rows = json_decode($raw, true)['rows'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return [[], $sha, 'manifest has no rows'];
        }

        return [self::manifestRows($rows), $sha, null];
    }

    /**
     * @param list<array<string,mixed>> $manifest
     * @param list<array<string,mixed>> $live
     * @return list<int> manifest ids that are not a live candidate with the same reason
     */
    private function drift(array $manifest, array $live): array
    {
        $now = collect($live)->pluck('closed_reason', 'id');

        return collect($manifest)->filter(fn ($r) => ($now[$r['id']] ?? null) !== $r['closed_reason'])->pluck('id')->values()->all();
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int,closed_reason:string}>
     */
    private static function manifestRows(array $rows): array
    {
        return collect($rows)->map(fn ($r) => ['id' => (int) $r['id'], 'closed_reason' => (string) $r['closed_reason']])
            ->sortBy('id')->values()->all();
    }

    /**
     * Closed as settled/completed with open debt per the invoice ledger. Same rule as
     * StudentClassController::courseNeedsPaymentReconciliation (#3529). Tutoring is always free.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>} candidates, stale Paid=1 rows
     */
    private function candidates(): array
    {
        $courses = StudentClass::query()->with('student:id,CampusID')
            ->where('Stop', 1)->whereIn('closed_reason', ['settled', 'completed'])
            ->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) <> 'tutoring'")
            ->orderBy('ID')->get();
        $outstanding = $this->outstanding($courses);
        [$rows, $stale] = [[], []];
        foreach ($courses as $c) {
            $owed = $outstanding[(int) $c->ID] ?? 0;
            if ($owed <= 0) {
                continue;
            }
            $row = ['id' => (int) $c->ID, 'student_id' => (int) $c->StudentID, 'campus_id' => $c->student?->CampusID,
                'mode' => (string) $c->ScheduleMode, 'closed_reason' => (string) $c->closed_reason,
                'outstanding' => $owed, 'end_date' => substr((string) $c->EndDate, 0, 10)];
            if ((int) $c->Paid === 1) {
                $stale[] = $row;
            } else {
                $rows[] = $row;
            }
        }

        return [$rows, $stale];
    }

    /**
     * Open debt per course: non-void invoices not covered by their payment rows (legacy: PaidAmount when no
     * rows); without invoices, Charge when not effectively paid.
     *
     * @param \Illuminate\Support\Collection<int, mixed> $courses
     * @return array<int,int>
     */
    private function outstanding($courses): array
    {
        $invoices = Invoice::query()->with('payments')->whereIn('StudentClassID', $courses->pluck('ID')->all() ?: [0])
            ->where(fn ($q) => $q->whereNull('Status')->orWhere('Status', '!=', 'void'))
            ->get()->groupBy('StudentClassID');
        $out = [];
        foreach ($courses as $c) {
            if (!$invoices->has($c->ID)) {
                $out[(int) $c->ID] = (int) $c->Charge > 0 && !$c->isEffectivelyPaid() ? (int) $c->Charge : 0;
                continue;
            }
            $owed = 0;
            foreach ($invoices->get($c->ID) as $invoice) {
                $a = $this->amounts->resolve($invoice, $c);
                $paid = $invoice->getRelationValue('payments')->isEmpty()
                    ? max(0, (int) $invoice->getAttribute('PaidAmount')) : (int) $a['net_applied'];
                $owed += max(0, (int) $a['total_amount'] - $paid);
            }
            $out[(int) $c->ID] = $owed;
        }

        return $out;
    }

    /**
     * A repaired row may legitimately move on (payment confirmed, written off). It is an error only when the
     * ledger still shows debt and the row is outside every reason the accounting queue lists, or is missing.
     *
     * @return list<string>
     */
    private function postErrors(?SessionCorrection $corr): array
    {
        if (!$corr) {
            return ['no open correction'];
        }
        $ids = collect($corr->snapshot_before['rows'] ?? [])->pluck('id')->map(fn ($id) => (int) $id);
        $courses = StudentClass::query()->whereIn('ID', $ids->all() ?: [0])->get()->keyBy('ID');
        $owed = $this->outstanding($courses->values());
        $bad = $ids->filter(fn ($id) => !$courses->has($id)
            || (($owed[$id] ?? 0) > 0 && !in_array((string) $courses[$id]->closed_reason, self::VISIBLE, true)))->values()->all();

        return $bad === [] ? [] : ['unpaid and hidden again, or missing: ' . implode(',', $bad)];
    }

    private function openCorrection(): ?SessionCorrection
    {
        return SessionCorrection::query()->where('session_id', self::KEY_SESSION)
            ->where('decision_reference', self::REF)->whereNull('rolled_back_at')->orderByDesc('id')->first();
    }

    private function rollback(bool $execute): int
    {
        $corr = $this->openCorrection();
        if (!$corr) {
            $this->error('No open ' . self::REF . ' correction');

            return self::FAILURE;
        }
        $rows = collect($corr->snapshot_before['rows'] ?? []);
        // Only rows still exactly as this repair left them (settled_pending); a confirmed payment or a waiver
        // moves closed_reason on, so those are skipped. The Paid flag is not trusted (it can be stale).
        $restorable = fn () => DB::table('StudentClass')->whereIn('ID', $rows->pluck('id')->all() ?: [0])
            ->where('closed_reason', 'settled_pending');
        $still = $restorable()->pluck('ID')->map(fn ($id) => (int) $id)->all();
        $this->line(($execute ? '=== EXECUTE ROLLBACK ===' : '=== DRY RUN ROLLBACK ===') . ' restore=' . count($still) . ' skip=' . ($rows->count() - count($still)));
        if (!$execute) {
            return self::SUCCESS;
        }
        $actor = substr((string) ($this->option('actor') ?: 'cli'), 0, 128);
        $restored = DB::transaction(function () use ($rows, $restorable, $corr, $actor): int {
            $locked = $restorable()->lockForUpdate()->pluck('ID')->map(fn ($id) => (int) $id)->all(); // re-check under lock
            $n = 0;
            foreach ($rows as $r) {
                if (in_array((int) $r['id'], $locked, true)) {
                    $n += DB::table('StudentClass')->where('ID', $r['id'])->where('closed_reason', 'settled_pending')
                        ->update(['closed_reason' => $r['closed_reason']]);
                }
            }
            if ($n !== count($locked)) {
                throw new \RuntimeException("rollback restored {$n} of " . count($locked) . ' locked rows');
            }
            $corr->rolled_back_at = now();
            $corr->decided_by_actor = substr($corr->decided_by_actor . ' | rollback:' . $actor, 0, 128);
            $corr->save();
            SecurityAuditEvent::append('repair.unpaid_hidden_closures', 'rolled_back', [
                'actor_type' => 'system', 'subject_type' => 'student_class_batch',
            ], ['reason_code' => self::REF, 'outcome' => 'rolled_back']);

            return $n;
        });
        $this->line("REPAIR_STATE=ROLLED_BACK restored={$restored}");

        return self::SUCCESS;
    }

    private function prodOk(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force') || env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');

            return false;
        }

        return true;
    }
}

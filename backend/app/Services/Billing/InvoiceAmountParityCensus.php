<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Amount parity census (#4991 prevention programme item 2, #3521): the three places a bill's amount lives must agree.
 *
 *   header   Invoice.TotalAmount
 *   items    SUM(InvoiceItem.Amount) of the same bill
 *   resolver InvoiceAmountReconciliationService::resolve()['total_amount'], the amount every billing screen shows
 *
 * Two mismatch types (the third pair follows from these two):
 *   header_vs_items     the bill has lines and they do not add up to the header
 *   header_vs_resolver  the screens show an amount that is not the stored header
 *
 * Read-only: SELECT only, never repairs. Bounded by config/amount_parity.php. A bill that agrees only because a rule
 * says the header is the amount is counted under a named exclusion, never dropped silently.
 */
final class InvoiceAmountParityCensus
{
    public const TYPE_HEADER_VS_ITEMS = 'header_vs_items';
    public const TYPE_HEADER_VS_RESOLVER = 'header_vs_resolver';

    /** Named reasons a live bill is not (or cannot be) a mismatch; every one is counted in the output. */
    public const EXCLUSIONS = [
        'voided',                // Status=void: not scanned, no screen shows it as owed
        'no_item_lines',         // header-only bill: nothing to add up
        'settled_amount_frozen', // money applied or paid: stored amount stays until a reviewed accounting correction
        'zero_fee_with_reason',  // NT$0 bill marked 免費／贈送 or 全額折扣 and the resolver agrees
        'no_course_on_header',   // package / sibling bill named only on its lines: the header is the amount
        'multi_course_bill',     // one bill for several courses: one course's lesson price is only its share
        'not_lesson_priced',     // count-mode or cross-month bill: the resolver has no second computation
        'resolver_error',        // the resolver threw for this bill (reported, never hidden)
    ];

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    /**
     * @return array{
     *   scanned:int, truncated:bool, truncated_reason:string|null, mismatch_count:int,
     *   by_type:array<string,int>, exclusions:array<string,int>, by_campus:array<int,array<string,int>>,
     *   findings:list<array{campus_id:int,invoice_id:int,type:string}>, findings_truncated:bool
     * }
     */
    public function run(): array
    {
        $rowCap = max(0, (int) config('amount_parity.row_cap'));
        $chunk = max(1, (int) config('amount_parity.chunk'));
        $findingCap = max(0, (int) config('amount_parity.finding_cap'));
        $deadline = microtime(true) + max(0, (int) config('amount_parity.time_limit_seconds'));

        $out = [
            'scanned' => 0, 'truncated' => false, 'truncated_reason' => null, 'mismatch_count' => 0,
            'by_type' => [self::TYPE_HEADER_VS_ITEMS => 0, self::TYPE_HEADER_VS_RESOLVER => 0],
            'exclusions' => array_fill_keys(self::EXCLUSIONS, 0),
            'by_campus' => [], 'findings' => [], 'findings_truncated' => false,
        ];
        $out['exclusions']['voided'] = Invoice::query()->where('Status', 'void')->count();

        $record = function (int $campusId, int $invoiceId, string $type, int $delta) use (&$out, $findingCap): void {
            $out['mismatch_count']++;
            $out['by_type'][$type]++;
            $out['by_campus'][$campusId][$type] = ($out['by_campus'][$campusId][$type] ?? 0) + 1;
            if (count($out['findings']) >= $findingCap) {
                $out['findings_truncated'] = true;

                return;
            }
            $out['findings'][] = ['campus_id' => $campusId, 'invoice_id' => $invoiceId, 'type' => $type];
            // The size of the difference stays in the server log; the command output carries ids and counts only.
            Log::info('amount_parity.finding', ['campus_id' => $campusId, 'invoice_id' => $invoiceId, 'type' => $type, 'delta' => $delta]);
        };

        // Newest first, so a bound drops the oldest bills.
        Invoice::query()->scopes('notVoided')->with(['items', 'payments', 'studentClass'])
            ->chunkByIdDesc($chunk, function ($invoices) use (&$out, $record, $rowCap, $deadline): bool {
                $campusByStudent = DB::table('Student')->whereIn('id', $invoices->pluck('StudentID')->unique()->all())->pluck('CampusID', 'id');
                $this->amounts->preloadSessions($invoices->pluck('StudentClassID')->filter()->unique()->values()->all());
                try {
                    foreach ($invoices as $invoice) {
                        $reason = $out['scanned'] >= $rowCap ? 'row_cap' : (microtime(true) >= $deadline ? 'time_limit' : null);
                        if ($reason !== null) {
                            $out['truncated'] = true;
                            $out['truncated_reason'] = $reason;

                            return false;
                        }
                        $out['scanned']++;
                        $this->check($invoice, (int) ($campusByStudent[$invoice->StudentID] ?? 0), $out['exclusions'], $record);
                    }
                } finally {
                    $this->amounts->forgetPreloadedSessions();
                }

                return true;
            }, 'id');

        ksort($out['by_campus']);

        return $out;
    }

    /**
     * @param array<string,int> $exclusions
     * @param callable(int,int,string,int):void $record
     */
    private function check(Invoice $invoice, int $campusId, array &$exclusions, callable $record): void
    {
        $id = (int) $invoice->getKey();
        $header = (int) $invoice->TotalAmount;
        $items = $invoice->items;

        if ($items->isEmpty()) {
            $exclusions['no_item_lines']++;
        } else {
            $sum = (int) $items->sum(fn ($item) => (int) $item->getAttribute('Amount'));
            if ($sum !== $header) {
                $record($campusId, $id, self::TYPE_HEADER_VS_ITEMS, $header - $sum);
            }
        }

        try {
            $verdict = $this->amounts->resolve($invoice);
        } catch (\Throwable $e) {
            $exclusions['resolver_error']++;
            Log::warning('amount_parity.resolver_error', ['invoice_id' => $id, 'error' => $e::class]);

            return;
        }

        if ($verdict['total_amount'] !== $verdict['stored_total_amount']) {
            $record($campusId, $id, self::TYPE_HEADER_VS_RESOLVER, $verdict['total_amount'] - $verdict['stored_total_amount']);
        } elseif ($verdict['amount_discrepancy']) {
            $exclusions['settled_amount_frozen']++;
        } elseif ($header === 0 && (string) $invoice->zero_amount_reason !== '') {
            $exclusions['zero_fee_with_reason']++;
        } elseif ($invoice->studentClass === null) {
            $exclusions['no_course_on_header']++;
        } elseif ($items->pluck('StudentClassID')->filter()->unique()->count() > 1) {
            $exclusions['multi_course_bill']++;
        } elseif ($verdict['computed_total_amount'] === null) {
            $exclusions['not_lesson_priced']++;
        }
    }
}

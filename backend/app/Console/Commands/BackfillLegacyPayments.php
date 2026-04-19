<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillLegacyPayments extends Command
{
    protected $signature = 'payments:backfill-legacy
        {--dry-run : 僅輸出計畫，不寫 DB}';

    protected $description = '為舊系統繳費課程（Paid=1, Charge>0, 無 confirmed PaymentReport）補建 Invoice + Payment + PaymentReport 記錄';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('[DRY-RUN] 模式：不寫入任何資料庫記錄');
        }

        $backfilled = 0;
        $skipped    = 0;
        $errors     = 0;

        // 查詢條件（冪等核心）：
        // 1. Paid=1（舊系統已繳費）
        // 2. Charge > 0（有金額，排除 46 筆 Charge=0 免費課程）
        // 3. NOT EXISTS confirmed PaymentReport（保護既有 24 筆正常收據）
        // 4. whereHas student（跳過孤立課程）
        //
        // NOTE: IDs are collected first to avoid the "chunk on modifying query" pitfall:
        // if we chunk while creating confirmed reports, the WHERE NOT EXISTS condition
        // causes OFFSET-based chunking to skip records (processed rows drop from the
        // result set mid-iteration). Collecting IDs upfront is safe and idempotent.
        $ids = StudentClass::where('Paid', 1)
            ->where('Charge', '>', 0)
            ->whereNotNull('Charge')
            ->whereNotExists(fn ($q) =>
                $q->from('payment_reports')
                  ->whereColumn('StudentClassID', 'StudentClass.ID')
                  ->where('status', 'confirmed')
            )
            ->whereHas('student')
            ->pluck('ID')
            ->all();

        foreach (array_chunk($ids, 50) as $idBatch) {
            $chunk = StudentClass::with('student', 'subjectRecord')
                ->whereIn('ID', $idBatch)
                ->get();
            foreach ($chunk as $sc) {
                    // Determine pay date (priority: PayDate → StartDate → today)
                    if (!empty($sc->PayDate)) {
                        $payDate    = substr($sc->PayDate, 0, 10);
                        $dateSource = 'PayDate';
                    } elseif (!empty($sc->StartDate)) {
                        $payDate    = substr($sc->StartDate, 0, 10);
                        $dateSource = 'StartDate';
                    } else {
                        $payDate    = Carbon::today()->toDateString();
                        $dateSource = 'today';
                    }

                    $note = "[舊系統補建] 付款日來源：{$dateSource}={$payDate}，付款方式不明";
                    $subjectName = $sc->subjectRecord?->Subject_Name ?? '課程';
                    $studentName = $sc->student->name;

                    if ($dryRun) {
                        $this->line(
                            sprintf('[DRY-RUN] SC #%d %s %s Charge=%d payDate=%s source=%s',
                                $sc->ID, $studentName, $subjectName, $sc->Charge, $payDate, $dateSource
                            )
                        );
                        $backfilled++;
                        continue;
                    }

                    try {
                        DB::transaction(function () use ($sc, $payDate, $note, &$backfilled) {
                            // 1. Invoice
                            $invoice = Invoice::create([
                                'StudentID'      => $sc->StudentID,
                                'StudentClassID' => $sc->ID,
                                'IssueDate'      => $payDate,
                                'TotalAmount'    => (int) $sc->Charge,
                                'PaidAmount'     => (int) $sc->Charge,
                                'Status'         => 'paid',
                                'Note'           => '[系統補建]',
                                'reconciled_at'  => Carbon::now(),
                                'reconciled_by'  => null,
                            ]);

                            // 2. Payment
                            $payment = Payment::create([
                                'InvoiceID' => $invoice->id,
                                'Amount'    => (int) $sc->Charge,
                                'PaidAt'    => $payDate,
                                'Method'    => 'cash',
                                'Note'      => '[系統補建] 舊系統繳費記錄',
                            ]);

                            // 3. PaymentReport (confirmed, backfill_note set, token immediately expired)
                            $report = PaymentReport::create([
                                'StudentID'         => $sc->StudentID,
                                'StudentClassID'    => $sc->ID,
                                'InvoiceID'         => $invoice->id,
                                'reported_by_name'  => $sc->student->name,
                                'payment_date'      => $payDate,
                                'payment_method'    => 'cash',
                                'reported_amount'   => $sc->Charge,
                                'status'            => 'confirmed',
                                'confirmed_by'      => null,
                                'confirmed_at'      => Carbon::now(),
                                'payment_id'        => $payment->id,
                                'backfill_note'     => $note,
                                'report_token_hash' => hash('sha256', 'legacy-backfill-' . $sc->ID),
                                'token_expires_at'  => Carbon::now(), // immediately expired, not usable for parent portal
                            ]);

                            $this->line(sprintf('[OK] SC #%d Report#%d Invoice#%d', $sc->ID, $report->id, $invoice->id));

                            Log::info('PaymentReport legacy-backfill', [
                                'sc_id'      => $sc->ID,
                                'report_id'  => $report->id,
                                'invoice_id' => $invoice->id,
                                'payment_id' => $payment->id,
                                'dry_run'    => false,
                            ]);

                            $backfilled++;
                        });
                    } catch (\Throwable $e) {
                        $this->error(sprintf('[ERROR] SC #%d: %s', $sc->ID, $e->getMessage()));
                        Log::error('PaymentReport legacy-backfill error', [
                            'sc_id' => $sc->ID,
                            'error' => $e->getMessage(),
                        ]);
                        $errors++;
                }
            }
        }

        if ($dryRun) {
            $this->info("[DRY-RUN] Would backfill: {$backfilled} records");
        } else {
            $this->info("Backfill complete: {$backfilled} backfilled, {$skipped} skipped" . ($errors ? ", {$errors} errors" : ''));
        }

        return $errors > 0 ? 1 : 0;
    }
}

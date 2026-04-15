<?php

namespace App\Console\Commands;

use App\Models\StudentClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BackfillPaidFromInvoice extends Command
{
    protected $signature = 'alltrue:backfill-paid-from-invoice
        {--dry-run : List affected rows without writing}
        {--since= : Only process invoices with Payment.PaidAt >= this date (Y-m-d)}';

    protected $description = 'Sync StudentClass.Paid/PayDate from Invoice+Payment for courses that have payments but Paid=0';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $since = $this->option('since');

        $query = DB::table('Invoice')
            ->join('Payment', 'Payment.InvoiceID', '=', 'Invoice.id')
            ->join('StudentClass', function ($join) {
                $join->on('Invoice.StudentClassID', '=', 'StudentClass.ID');
            })
            ->where(function ($q) {
                $q->where('StudentClass.Paid', 0)
                  ->orWhereNull('StudentClass.Paid');
            })
            ->where('StudentClass.Stop', 0);

        if ($since) {
            $query->where('Payment.PaidAt', '>=', $since);
        }

        $rows = $query
            ->select(
                'StudentClass.ID as class_id',
                'StudentClass.StudentID as student_id',
                'StudentClass.Paid as old_paid',
                'StudentClass.PayDate as old_pay_date',
                'Invoice.id as invoice_id',
                DB::raw('MAX(Payment.PaidAt) as max_paid_at')
            )
            ->groupBy('StudentClass.ID', 'StudentClass.StudentID', 'StudentClass.Paid', 'StudentClass.PayDate', 'Invoice.id')
            ->get();

        $classMap = [];
        foreach ($rows as $row) {
            $cid = (int) $row->class_id;
            if (!isset($classMap[$cid]) || $row->max_paid_at > ($classMap[$cid]->max_paid_at ?? '')) {
                $classMap[$cid] = $row;
            }
        }

        if (empty($classMap)) {
            $this->info('No StudentClass records need backfill.');
            return 0;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . count($classMap) . ' StudentClass record(s) to update:');
        $this->table(
            ['StudentClass.ID', 'StudentID', 'Old Paid', 'Old PayDate', 'Invoice ID', 'Max PaidAt'],
            collect($classMap)->map(fn ($r) => [
                $r->class_id, $r->student_id, $r->old_paid, $r->old_pay_date ?? '(null)',
                $r->invoice_id, $r->max_paid_at,
            ])->toArray()
        );

        if ($dryRun) {
            $this->info('[DRY-RUN] No changes written.');
            return 0;
        }

        $updated = 0;
        foreach ($classMap as $cid => $row) {
            $sc = StudentClass::where('ID', $cid)->first();
            if (!$sc) {
                continue;
            }
            $paidDate = $row->max_paid_at ? substr($row->max_paid_at, 0, 10) : now()->toDateString();
            $sc->Paid = 1;
            if (empty($sc->PayDate)) {
                $sc->PayDate = $paidDate;
            }
            $sc->save();
            $updated++;

            Log::info('[BackfillPaid] synced', [
                'student_class_id' => $cid,
                'invoice_id' => $row->invoice_id,
                'old_paid' => $row->old_paid,
                'old_pay_date' => $row->old_pay_date,
                'new_pay_date' => $sc->PayDate,
            ]);
        }

        $this->info("Updated {$updated} StudentClass record(s).");
        return 0;
    }
}

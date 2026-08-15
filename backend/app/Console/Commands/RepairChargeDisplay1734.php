<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off display repair for GitHub #1734 / in-app #230.
 * Invoice/Payment already recorded 13,200. Only StudentClass.Charge is stale (24,750).
 */
class RepairChargeDisplay1734 extends Command
{
    protected $signature = 'repair:charge-display-1734
                            {--execute}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--rollback}';

    protected $description = 'Align SC#1052 Charge to Rate×SessionCount without touching invoices or payments';

    private const CLASS_ID = 1052;
    private const STUDENT_ID = 72;
    private const INVOICE_ID = 539;
    private const PAYMENT_ID = 514;
    private const FROM_CHARGE = 24750;
    private const TO_CHARGE = 13200;
    private const SNAPSHOT_DEFAULT = 'storage/app/repair-snapshots/1734-charge-display.json';

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }

        $plan = $this->plan();
        if ($plan['error'] !== null) {
            $this->error((string) $plan['error']);
            return self::FAILURE;
        }

        $this->line($execute ? '=== EXECUTE repair:charge-display-1734 ===' : '=== DRY RUN repair:charge-display-1734 ===');
        $this->line(json_encode($plan['summary'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!$execute || $plan['already']) {
            return self::SUCCESS;
        }

        $path = (string) ($this->option('snapshot') ?: base_path(self::SNAPSHOT_DEFAULT));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'case' => 1734,
            'generated_at' => now()->toIso8601String(),
            'actor' => (string) ($this->option('actor') ?: 'repair:charge-display-1734'),
            'before' => $plan['before'],
            'will_change' => $plan['summary'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line('Snapshot: ' . $path);

        DB::transaction(function () use ($plan): void {
            $row = DB::table('StudentClass')->where('ID', self::CLASS_ID)->lockForUpdate()->first();
            if (!$row || (int) $row->Charge !== self::FROM_CHARGE) {
                throw new RuntimeException('REPAIR_1734_DRIFT');
            }
            $invoiceTotal = (int) DB::table('Invoice')->where('id', self::INVOICE_ID)->lockForUpdate()->value('TotalAmount');
            $paymentAmount = (int) DB::table('Payment')->where('id', self::PAYMENT_ID)->lockForUpdate()->value('Amount');
            if ($invoiceTotal !== self::TO_CHARGE || $paymentAmount !== self::TO_CHARGE) {
                throw new RuntimeException('REPAIR_1734_FINANCIAL_GATE_BLOCKED');
            }
            DB::table('StudentClass')->where('ID', self::CLASS_ID)->update([
                'Charge' => self::TO_CHARGE,
            ]);
        });

        $this->info('Applied #1734 Charge display repair; invoices/payments untouched.');
        return self::SUCCESS;
    }

    /** @return array{error:?string,already:bool,before:array<string,mixed>,summary:array<string,mixed>} */
    private function plan(): array
    {
        $course = DB::table('StudentClass')->where('ID', self::CLASS_ID)->first();
        $invoice = DB::table('Invoice')->where('id', self::INVOICE_ID)->first();
        $payment = DB::table('Payment')->where('id', self::PAYMENT_ID)->first();
        $before = compact('course', 'invoice', 'payment');

        if ($course && (int) $course->Charge === self::TO_CHARGE) {
            return ['error' => null, 'already' => true, 'before' => $before, 'summary' => ['already_applied' => true, 'charge' => self::TO_CHARGE]];
        }
        if (!$course || !$invoice || !$payment) {
            return ['error' => 'REPAIR_1734_MISSING_ROW', 'already' => false, 'before' => $before, 'summary' => []];
        }
        if ((int) $course->StudentID !== self::STUDENT_ID
            || (int) $course->Rate !== 1650
            || (int) $course->SessionCount !== 8
            || (int) $course->Charge !== self::FROM_CHARGE
            || (string) ($course->rate_unit ?? 'session') !== 'session') {
            return ['error' => 'REPAIR_1734_COURSE_DRIFT', 'already' => false, 'before' => $before, 'summary' => []];
        }
        if ((int) $invoice->StudentClassID !== self::CLASS_ID
            || (int) $invoice->TotalAmount !== self::TO_CHARGE
            || (int) $invoice->PaidAmount !== self::TO_CHARGE
            || strtolower((string) $invoice->Status) !== 'paid') {
            return ['error' => 'REPAIR_1734_INVOICE_DRIFT', 'already' => false, 'before' => $before, 'summary' => []];
        }
        if ((int) $payment->InvoiceID !== self::INVOICE_ID || (int) $payment->Amount !== self::TO_CHARGE) {
            return ['error' => 'REPAIR_1734_PAYMENT_DRIFT', 'already' => false, 'before' => $before, 'summary' => []];
        }

        return [
            'error' => null,
            'already' => false,
            'before' => $before,
            'summary' => [
                'student_class' => self::CLASS_ID,
                'charge' => self::FROM_CHARGE . ' -> ' . self::TO_CHARGE,
                'billing' => 'Invoice 539 / Payment 514 stay 13200 paid; no receipt mutation',
            ],
        ];
    }

    private function rollback(): int
    {
        $course = DB::table('StudentClass')->where('ID', self::CLASS_ID)->first();
        if (!$course) {
            $this->error('REPAIR_1734_MISSING_ROW');
            return self::FAILURE;
        }
        if ((int) $course->Charge === self::FROM_CHARGE) {
            $this->line('Nothing to roll back; Charge already 24750.');
            return self::SUCCESS;
        }
        if (!$this->option('execute')) {
            $this->line('=== DRY RUN ROLLBACK repair:charge-display-1734 ===');
            $this->line('WOULD restore Charge 13200 -> 24750; invoices untouched.');
            return self::SUCCESS;
        }
        if (!$this->prodOk()) {
            return self::FAILURE;
        }
        if ((int) $course->Charge !== self::TO_CHARGE) {
            $this->error('REPAIR_1734_DRIFT');
            return self::FAILURE;
        }
        DB::table('StudentClass')->where('ID', self::CLASS_ID)->update(['Charge' => self::FROM_CHARGE]);
        $this->info('Rolled back #1734 Charge to 24750.');
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

<?php

namespace Tests\Unit;

use App\Services\Billing\ContractMoneyState;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/** Synthetic in-memory SQL; no Laravel environment, application credentials or external database. */
class ContractCorrectionPaymentGuardTest extends TestCase
{
    private Manager $database;

    protected function setUp(): void
    {
        $this->database = new Manager();
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->getContainer()->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->database->getContainer());
        $this->database->schema()->create('Invoice', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('StudentClassID');
            $table->integer('PaidAmount')->default(0);
            $table->string('Status')->nullable();
        });
        $this->database->schema()->create('Payment', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('InvoiceID');
            $table->integer('Amount');
            $table->string('Method')->nullable();
            $table->integer('payment_report_id')->nullable();
        });
        $this->database->schema()->create('payment_reports', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('StudentClassID');
            $table->integer('InvoiceID');
            $table->integer('payment_id')->nullable();
            $table->string('reported_amount'); // Preserve the decimal strings returned by MySQL without SQLite coercion.
            $table->string('status');
        });
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    /** @dataProvider receipts */
    public function test_reversed_history_is_not_active_payment(array $payments, int $storedPaid, bool $blocked, bool $legacy = false, ?string $reportedAmount = null): void
    {
        $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid', 'PaidAmount' => $storedPaid]);
        $receiptId = null;
        $receiptAmount = 0;
        foreach ($payments as [$amount, $method]) {
            $isReceipt = $amount > 0 && $method !== 'void';
            $firstReceipt = $isReceipt && $receiptId === null;
            $paymentId = $this->database->table('Payment')->insertGetId(['InvoiceID' => $id, 'Amount' => $amount, 'Method' => $method,
                'payment_report_id' => (!$isReceipt || ($firstReceipt && !$legacy)) ? 903 : null]);
            if ($firstReceipt) {
                $receiptId = $paymentId;
                $receiptAmount = $amount;
            }
        }
        if ($receiptId !== null) {
            $this->database->table('payment_reports')->insert(['id' => 903, 'StudentClassID' => 901, 'InvoiceID' => $id,
                'payment_id' => $receiptId, 'reported_amount' => $reportedAmount ?? $receiptAmount, 'status' => 'voided']);
        }
        $this->assertSame($blocked, ContractMoneyState::blocksUnpaidCorrection(901));
        $this->assertSame(count($payments), $this->database->table('Payment')->count(), 'Guard never rewrites history');
    }

    public static function receipts(): array
    {
        return [
            'full reversal' => [[[8000, 'cash'], [-8000, 'void']], 0, false],
            'PR4217 whole decimal report remains valid' => [[[8000, 'cash'], [-8000, 'void']], 0, false, false, '8000.00'],
            'PR4217 fractional report fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, '8000.50'],
            'PR4217 fractional legacy report fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, true, '8000.50'],
            'PR4217 malformed report amount fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, '8000oops'],
            'PR4217 exponent report amount fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, '8e3'],
            'PR4217 whitespace report amount fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, ' 8000.00'],
            'PR4217 nonfinite report amount fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, 'INF'],
            'PR4217 overflowing report amount fails closed' => [[[8000, 'cash'], [-8000, 'void']], 0, true, false, '999999999999999999999999.00'],
            'PR4217 split reversals sharing one report fail closed' => [[[8000, 'cash'], [-4000, 'void'], [-4000, 'void']], 0, true],
            'PR4217 full reversal plus zero void row fails closed' => [[[8000, 'cash'], [-8000, 'void'], [0, 'void']], 0, true],
            'PR4217 unequal split reversals sharing one report fail closed' => [[[8000, 'cash'], [-1000, 'void'], [-2000, 'void'], [-5000, 'void']], 0, true],
            'legacy reverse-linked receipt' => [[[8000, 'cash'], [-8000, 'void']], 0, false, true],
            'equal-value receipt hidden by duplicate reversal' => [[[8000, 'cash'], [-8000, 'void'], [8000, 'cash'], [-8000, 'void']], 0, true],
            'positive void convention' => [[[8000, 'cash'], [8000, 'void']], 0, false],
            'negative cash convention' => [[[8000, 'cash'], [-8000, 'cash']], 0, false],
            'partial reversal' => [[[8000, 'cash'], [-7500, 'void']], 500, true],
            'surviving receipt' => [[[8000, 'cash'], [-8000, 'void'], [500, 'cash']], 0, true],
            'stored payment discrepancy' => [[[8000, 'cash'], [-8000, 'void']], 500, true],
            'negative stored discrepancy' => [[], -500, true],
            'duplicate reversal with surviving receipt' => [[[8000, 'cash'], [-8000, 'void'], [500, 'cash'], [-8000, 'void']], 0, true],
            'unmatched reversal' => [[[-8000, 'void']], 0, true],
            'no receipt' => [[], 0, false],
        ];
    }

    public function test_unlinked_reversal_fails_closed_even_when_net_is_zero(): void
    {
        $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid']);
        foreach ([8000, -8000] as $amount) {
            $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $amount, 'Method' => $amount < 0 ? 'void' : 'cash']);
        }
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection(901));
        $this->assertTrue(ContractMoneyState::hasActivePayment(901), 'Other callers retain their historical guard');
    }

    public function test_two_reports_cannot_claim_the_same_legacy_receipt(): void
    {
        $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid']);
        $receipt = $this->database->table('Payment')->insertGetId(['InvoiceID' => $id, 'Amount' => 8000, 'Method' => 'cash']);
        $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => 8000, 'Method' => 'cash']);
        foreach ([903, 905] as $reportId) {
            $this->database->table('payment_reports')->insert(['id' => $reportId, 'StudentClassID' => 901, 'InvoiceID' => $id,
                'payment_id' => $receipt, 'reported_amount' => 8000, 'status' => 'voided']);
            $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => -8000, 'Method' => 'void', 'payment_report_id' => $reportId]);
        }
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection(901));
    }

    public function test_reversal_on_another_invoice_cannot_cancel_a_surviving_receipt(): void
    {
        foreach ([500, -500] as $amount) {
            $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid']);
            $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $amount, 'Method' => 'cash']);
        }
        $this->assertTrue(ContractMoneyState::blocksUnpaidCorrection(901));
        $this->assertFalse(ContractMoneyState::blocksUnpaidCorrection(902));
    }
}

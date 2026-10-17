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
    public function test_reversed_history_is_not_active_payment(array $payments, int $storedPaid, bool $blocked): void
    {
        $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid', 'PaidAmount' => $storedPaid]);
        foreach ($payments as [$amount, $method]) {
            $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $amount, 'Method' => $method]);
        }
        $this->assertSame($blocked, ContractMoneyState::hasActivePayment(901));
        $this->assertSame(count($payments), $this->database->table('Payment')->count(), 'Guard never rewrites history');
    }

    public static function receipts(): array
    {
        return [
            'full reversal' => [[[8000, 'cash'], [-8000, 'void']], 0, false],
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

    public function test_reversal_on_another_invoice_cannot_cancel_a_surviving_receipt(): void
    {
        foreach ([500, -500] as $amount) {
            $id = $this->database->table('Invoice')->insertGetId(['StudentClassID' => 901, 'Status' => 'unpaid']);
            $this->database->table('Payment')->insert(['InvoiceID' => $id, 'Amount' => $amount, 'Method' => 'cash']);
        }
        $this->assertTrue(ContractMoneyState::hasActivePayment(901));
        $this->assertFalse(ContractMoneyState::hasActivePayment(902));
    }
}

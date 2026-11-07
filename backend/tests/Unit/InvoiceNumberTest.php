<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Services\Billing\InvoiceNumber;
use PHPUnit\Framework\TestCase;

/** in-app #514: an issued invoice number never changes when its billing_period is re-keyed. */
class InvoiceNumberTest extends TestCase
{
    private function invoice(array $attrs): Invoice
    {
        $i = new Invoice($attrs);
        $i->id = 123;

        return $i;
    }

    public function test_number_follows_billing_period(): void
    {
        $this->assertSame('INV-202605-000123', InvoiceNumber::for($this->invoice(['billing_period' => '2026-05'])));
    }

    public function test_rekeyed_invoice_keeps_its_original_number(): void
    {
        $this->assertSame('INV-202604-000123', InvoiceNumber::for($this->invoice(['billing_period' => '2026-05', 'Note' => 'x [invno_period:2026-04]'])));
    }
}

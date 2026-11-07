<?php

namespace Tests\Feature\Billing;

use App\Exports\InvoicesExport;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** in-app #514: the [invno_period] tag never reaches an export or the deleted-course note thread. */
class InvoiceTagHiddenEverywhereTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_hides_the_tag(): void
    {
        DB::table('Student')->insert(['id' => 1, 'name' => 's', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1]);
        Invoice::create(['StudentID' => 1, 'IssueDate' => '2026-05-01', 'TotalAmount' => 1, 'PaidAmount' => 0, 'Status' => 'unpaid', 'billing_period' => '2026-05', 'Note' => '備註 [invno_period:2026-04]']);
        $rows = (new InvoicesExport)->collection();
        $this->assertSame('備註', $rows->first()->Note);
    }
}

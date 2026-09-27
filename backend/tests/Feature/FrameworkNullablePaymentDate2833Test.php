<?php

namespace Tests\Feature;

use App\Http\Controllers\AccountingController;
use App\Models\PaymentReport;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class FrameworkNullablePaymentDate2833Test extends TestCase
{
    public function test_accounting_projections_preserve_missing_and_dated_model_contracts(): void
    {
        $controller = app(AccountingController::class);
        $ledger = new ReflectionMethod(AccountingController::class, 'ledgerReportRow');
        $projection = new ReflectionMethod(AccountingController::class, 'transformPaymentReport');

        // Unpersisted models exercise the defensive contract; the SQL date is
        // NOT NULL. This does not establish that production contains null dates.
        foreach ([
            [null, null, 'LEGACY', false],
            ['2026-09-27', '2026-09-27', '202609', true],
            [CarbonImmutable::parse('2026-09-30 23:45', 'America/New_York'), '2026-09-30', '202609', true],
        ] as [$input, $date, $period, $prepaid]) {
            $report = (new PaymentReport())->forceFill([
                'id' => 73,
                'StudentID' => 0,
                'StudentClassID' => 0,
                'payment_date' => $input,
                'reported_by_name' => 'Isolated date fixture',
                'payment_method' => 'cash',
                'reported_amount' => '125.00',
                'status' => 'confirmed',
            ]);
            $report->setRelation('student', null);
            $report->setRelation('studentClass', null);
            $report->setRelation('confirmedByUser', null);
            $before = $report->getAttributes();

            $ledgerRow = $ledger->invoke($controller, $report);
            $projected = $projection->invoke($controller, $report, [
                0 => ['first_live' => '2026-10-01', 'first_any' => '2026-10-01'],
            ]);

            $this->assertSame($date, $ledgerRow['payment_date']);
            $this->assertSame('RCPT-' . $period . '-000073', $ledgerRow['receipt_no']);
            $this->assertSame($date, $projected['payment_date']);
            $this->assertSame($prepaid, $projected['is_prepaid']);
            $this->assertSame($before, $report->getAttributes(), 'Projection must not mutate the source date');
            $this->assertFalse($report->exists);
        }
    }
}

<?php

namespace Tests\Unit;

use App\Exports\StudentBillWorksheet;
use App\Exports\BillListExport;
use Illuminate\Support\Facades\File;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class StudentBillWorksheetTest extends TestCase
{
    public function test_existing_factory_keeps_duplicate_and_invalid_student_names_on_valid_distinct_sheets(): void
    {
        $names = [str_repeat('合成', 20) . '[]/?*:', str_repeat('合成', 20) . '[]/?*:', 'SAME', 'same', "'[]/?*:'"];
        $export = new BillListExport([], array_map(fn ($name) => ['name' => $name, 'sources' => []], $names), '2026-11-08T15:00:00Z');
        $sheets = $export->sheets();
        $titles = array_map(fn ($sheet) => $sheet->title(), $sheets);
        $this->assertCount(5, array_unique(array_map('mb_strtolower', $titles)));
        foreach ($titles as $title) {
            $this->assertLessThanOrEqual(31, mb_strlen($title));
            $this->assertDoesNotMatchRegularExpression('/[\/\\\\?*:\[\]]/', $title);
        }
        $this->assertSame('same-2', $titles[3]);
        $this->assertSame('學生', $titles[4]);
        $this->assertSame($names[0], $sheets[0]->array()[0][0]);
    }

    public function test_real_workbook_keeps_literal_text_typed_evidence_and_recorded_money(): void
    {
        $contract = [
            'subject' => '合成英文', 'start_date' => '2026-06-01', 'current_rate' => 123.45, 'rate_unit' => 'hour',
            'lessons' => [[
                'date' => '2026-06-12', 'ordinal' => null, 'teacher' => '合成老師',
                'start_time' => '16:00:00', 'end_time' => '17:30:00', 'minutes' => 90,
                'historical_unit_fee' => null, 'note' => '評量關聯不足，堂次序號未能確認',
            ]],
            'invoices' => [[
                'invoice_no' => 'INV-SYNTHETIC', 'status' => 'unpaid', 'total_amount' => 5000,
                'calculated_applied_amount' => 0, 'voided_amount' => 5000, 'outstanding_amount' => 5000,
                'payments' => [
                    ['paid_at' => '2026-06-02', 'receipt_no' => 'RCPT-000001', 'application_status' => 'voided', 'amount' => 5000, 'applied_amount' => 0, 'unapplied_amount' => 0, 'method' => 'cash'],
                    ['paid_at' => '2026-06-03', 'receipt_no' => 'RCPT-000001', 'application_status' => 'voided', 'amount' => -5000, 'applied_amount' => 0, 'unapplied_amount' => 0, 'method' => 'void'],
                ],
                'unpaid_reports' => [['payment_date' => '2026-06-04', 'status' => 'pending', 'amount' => 1000, 'payment_method' => 'transfer']],
            ]],
        ];
        $student = ['name' => '=1+1', 'sources' => [[
            'title' => '合成英文／合約', 'summary' => ['=1+1', '合成英文', '剩 2 堂', '未繳', '—', 5000, 0, 5000],
            'contracts' => [$contract],
        ]]];
        $empty = ['name' => '合成學生乙', 'sources' => [['title' => '合成方案', 'summary' => ['合成學生乙', '合成方案', '剩 1 堂', '要開帳單', '—', 0, 0, 0], 'contracts' => []]]];
        $sheets = [
            new StudentBillWorksheet($student, '合成學生甲', '2026-11-08T15:00:00Z'),
            new StudentBillWorksheet($empty, '合成學生乙', '2026-11-08T15:00:00Z'),
        ];
        $export = new class($sheets) implements WithMultipleSheets {
            public function __construct(private array $sheets) {}
            public function sheets(): array { return $this->sheets; }
        };
        $path = storage_path('framework/testing/student-bill-preview.xlsx');
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, Excel::raw($export, \Maatwebsite\Excel\Excel::XLSX));
        $binder = Cell::getValueBinder();
        $workbook = null;
        try {
            Cell::setValueBinder(new DefaultValueBinder());
            $workbook = IOFactory::load($path);
            $this->assertSame(['合成學生甲', '合成學生乙'], $workbook->getSheetNames());
            $sheet = $workbook->getSheet(0);
            $this->assertSame(18.0, $sheet->getColumnDimension('A')->getWidth());
            $this->assertSame(38.0, $sheet->getColumnDimension('H')->getWidth());
            $this->assertContains('A1:F1', $sheet->getMergeCells());
            $this->assertSame('=1+1', $sheet->getCell('A1')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
            $this->assertSame('2026-11-08 23:00', $sheet->getCell('B2')->getFormattedValue());
            $this->assertSame('2026-06-01', $sheet->getCell('D9')->getFormattedValue());
            $this->assertSame('2026-06-12', $sheet->getCell('A12')->getFormattedValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('A12')->getDataType());
            $this->assertSame('16:00', $sheet->getCell('D12')->getFormattedValue());
            $this->assertSame('17:30', $sheet->getCell('E12')->getFormattedValue());
            $this->assertSame(90, $sheet->getCell('F12')->getValue());
            $this->assertSame('未能確認', $sheet->getCell('B12')->getValue());
            $this->assertSame('未記錄', $sheet->getCell('G12')->getValue());
            $this->assertSame('目前合約單價（非歷史堂費）', $sheet->getCell('A10')->getValue());
            $this->assertSame(123.45, $sheet->getCell('B10')->getValue());
            $this->assertSame('每小時', $sheet->getCell('C10')->getValue());
            $this->assertSame(5000, $sheet->getCell('B14')->getValue());
            $this->assertSame(0, $sheet->getCell('D14')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D14')->getDataType());
            $this->assertSame(-5000, $sheet->getCell('D17')->getValue());
            $this->assertSame('尚未有已確認收據', $sheet->getCell('B18')->getValue());
            $this->assertSame('待核帳', $sheet->getCell('C18')->getValue());
            $this->assertNull($sheet->getCell('E18')->getValue());
            $this->assertSame(0, $workbook->getSheet(1)->getCell('F7')->getValue());
            $this->assertStringNotContainsString('到班', json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE));
        } finally {
            $workbook?->disconnectWorksheets();
            Cell::setValueBinder($binder);
        }
    }
}

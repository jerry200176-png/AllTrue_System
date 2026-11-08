<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Read-only presentation of server-scoped teaching and existing accounting evidence. */
final class StudentBillWorksheet extends DefaultValueBinder implements FromArray, WithTitle, WithStyles, WithColumnFormatting, WithColumnWidths, WithCustomValueBinder, WithStrictNullComparison
{
    private array $rows = [];
    private array $headingRows = [];
    private array $lessonRows = [];
    private array $dateCells = [];
    private array $rateRows = [];

    public function __construct(array $student, private string $sheetTitle, string $generatedAt)
    {
        $this->add([(string) $student['name'], null, null, null, null, null, '教務／帳務明細'], true);
        $this->add(['匯出時間（台北）', Date::dateTimeToExcel(CarbonImmutable::parse($generatedAt)->setTimezone('Asia/Taipei'))]);
        $this->add(['僅含本次篩選的帳單；上課紀錄與收款分列，不以堂數乘單價重新計費。']);
        foreach ($student['sources'] as $source) {
            $this->add([]);
            $this->add([(string) $source['title']], true);
            $this->add(['學生', '科目', '期間／剩幾堂', '狀態', '到期／逾期', '應收', '已收', '未收'], true);
            $this->add($source['summary']); // A selected package's shared amounts appear here once only.
            foreach ($source['contracts'] as $contract) {
                $this->contract($contract);
            }
            if ($source['contracts'] === []) {
                $this->add(['未有可確認的合約明細；未擴大至學生其他課程。']);
            }
        }
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
            return true;
        }
        return parent::bindValue($cell, $value);
    }

    public function columnFormats(): array
    {
        return ['A' => 'yyyy-mm-dd'];
    }

    public function columnWidths(): array
    {
        return ['A' => 18, 'B' => 20, 'C' => 22, 'D' => 14, 'E' => 14, 'F' => 16, 'G' => 16, 'H' => 38];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->freezePane('A4');
        $sheet->mergeCells('A1:F1')->mergeCells('G1:H1');
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
        foreach ($this->headingRows as $row) {
            $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        }
        foreach ($this->lessonRows as $row) {
            $sheet->getStyle("D{$row}:E{$row}")->getNumberFormat()->setFormatCode('hh:mm');
        }
        foreach ($this->dateCells as $cell) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }
        foreach ($this->rows as $index => $row) {
            if (is_string($row[0]) && count(array_filter($row, fn ($value) => $value !== null)) === 1) {
                $number = $index + 1;
                $sheet->mergeCells("A{$number}:H{$number}");
                $sheet->getRowDimension($number)->setRowHeight(30);
            }
        }
        foreach ($this->rateRows as $row) {
            $sheet->getRowDimension($row)->setRowHeight(32);
        }
        $sheet->getStyle('A1:H' . count($this->rows))->getAlignment()->setVertical('top')->setWrapText(true);
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        return [];
    }

    private function contract(array $contract): void
    {
        $this->add([]);
        $this->add(['科目／合約', (string) $contract['subject'], '合約起日', self::date($contract['start_date'] ?? null)], true);
        $this->dateCells[] = 'D' . count($this->rows);
        $unit = match ($contract['rate_unit'] ?? null) {
            'hour' => '每小時',
            'session' => '每堂',
            default => '單位未記錄',
        };
        $this->add(['目前合約單價（非歷史堂費）', $contract['current_rate'] ?? '未記錄', $unit]);
        $this->rateRows[] = count($this->rows);
        $this->add(['已上課紀錄', '堂次', '老師（系統紀錄）', '紀錄開始', '紀錄結束', '時長（分鐘）', '歷史單堂費', '資料註記'], true);
        foreach ($contract['lessons'] as $lesson) {
            $this->add([
                self::date($lesson['date']), $lesson['ordinal'] ?? '未能確認', $lesson['teacher'] ?? '未記錄',
                self::time($lesson['start_time'] ?? null), self::time($lesson['end_time'] ?? null),
                $lesson['minutes'] ?? '未能確認', $lesson['historical_unit_fee'] ?? '未記錄', $lesson['note'] ?? '',
            ]);
            $this->lessonRows[] = count($this->rows);
        }
        if ($contract['lessons'] === []) {
            $this->add(['未有符合條件的已上課紀錄；預排、未來、請假及取消不當作實際上課。']);
        }
        foreach ($contract['warnings'] ?? [] as $warning) {
            $this->add([(string) $warning]);
        }
        foreach ($contract['invoices'] as $invoice) {
            $this->add(['帳單', (string) $invoice['invoice_no'], '狀態', self::status($invoice['status'])], true);
            if (!empty($invoice['scope_note'])) {
                $this->add([(string) $invoice['scope_note']]);
            }
            $this->add(['帳單總額', $invoice['total_amount'], '已入帳', $invoice['calculated_applied_amount'], '沖銷', $invoice['voided_amount'], '未收', $invoice['outstanding_amount']]);
            $this->add(['付款日期', '收據編號', '紀錄狀態', '紀錄金額', '本帳單入帳', '超收待核對', '方式', '資料註記'], true);
            foreach ($invoice['payments'] as $payment) {
                $this->add([
                    self::date($payment['paid_at']), ($payment['receipt_no'] ?? null) ?: '未有可確認收據', self::status($payment['application_status']),
                    $payment['amount'], $payment['applied_amount'], $payment['unapplied_amount'], self::method($payment['method']), '',
                ]);
            }
            foreach ($invoice['unpaid_reports'] as $report) {
                $this->add([self::date($report['payment_date']), ($report['receipt_no'] ?? null) ?: '尚未有已確認收據', self::status($report['status']), $report['amount'], null, null, self::method($report['payment_method']), '回報金額不當作已入帳']);
            }
        }
        foreach ($contract['shared_invoice_references'] ?? [] as $invoiceNo) {
            $this->add(['跨合約帳單 ' . $invoiceNo . ' 已於本工作表前列完整列示；未重複列入本合約金額。']);
        }
        if ($contract['invoices'] === [] && empty($contract['shared_invoice_references'])) {
            $this->add(['未有可確認的本合約帳單；不以合約單價推算收據或帳單總額。']);
        }
        if (!empty($contract['unmatched_reports'])) {
            $this->add(['未對到本合約帳單的回報／收據', '收據編號', '紀錄狀態', '回報金額', null, null, '方式', '資料註記'], true);
            foreach ($contract['unmatched_reports'] as $report) {
                $this->add([self::date($report['payment_date']), ($report['receipt_no'] ?? null) ?: '尚未有已確認收據', self::status($report['status']), $report['amount'], null, null, self::method($report['payment_method']), '未當作本合約已入帳']);
            }
        }
    }

    private function add(array $row, bool $heading = false): void
    {
        $this->rows[] = array_pad($row, 8, null);
        if ($heading) {
            $this->headingRows[] = count($this->rows);
        }
    }

    private static function date(?string $value): float|string
    {
        $value = substr((string) $value, 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '未記錄';
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? Date::dateTimeToExcel($date) : '未能確認';
    }

    private static function time(?string $value): float|string
    {
        if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', (string) $value, $parts)
            || (int) $parts[1] > 23 || (int) $parts[2] > 59 || (int) ($parts[3] ?? 0) > 59) {
            return '未記錄';
        }
        return ((int) $parts[1] * 3600 + (int) $parts[2] * 60 + (int) ($parts[3] ?? 0)) / 86400;
    }

    private static function status(string $value): string
    {
        return match ($value) {
            'paid', 'confirmed', 'applied' => '已入帳',
            'unpaid' => '未收', 'partial', 'partially_applied' => '部分入帳',
            'void', 'voided' => '已作廢／沖銷', 'refunded' => '已退款',
            'pending' => '待核帳', 'rejected' => '已退回',
            'confirmed_without_payment' => '已確認，收款紀錄待核對',
            'confirmed_outside_selected_invoice' => '已確認，未對到本合約帳單',
            'overpayment_pending_review' => '超收需核對', default => '需核對',
        };
    }

    private static function method(string $value): string
    {
        return match ($value) {
            'cash' => '現金', 'transfer' => '轉帳', 'refund' => '退款',
            'void' => '沖銷', 'transfer_in' => '轉入餘額', default => '其他／未記錄',
        };
    }
}

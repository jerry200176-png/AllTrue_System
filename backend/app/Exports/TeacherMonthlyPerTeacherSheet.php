<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * 每位老師獨立 Sheet（格式對應 新莊中平分校_2025-12_刷卡清單.xlsx）
 *
 * 欄位佈局（10 欄，A-J）：
 *   A  老師名
 *   B  刷卡時間（YYYY-MM-DD HH:MM:SS）
 *   C  日期（YYYY-MM-DD）
 *   D  時間（hh:MM AM/PM）
 *   E  ─── 分隔 ───
 *   F  日期（YYYY-MM-DD(星期)）
 *   G  跑校（本版空白）
 *   H  上班（當日 min SignInDT）
 *   I  下班（當日 max SignOutDT）
 *   J  加班（本版空白）
 */
class TeacherMonthlyPerTeacherSheet implements FromArray, WithTitle, WithStyles
{
    private static array $weekdayMap = ['日', '一', '二', '三', '四', '五', '六'];

    private string $teacherName;
    private Collection $records;  // teacher's TeacherSingIn rows for the month
    private string $yearMonth;    // "YYYY-MM"
    private string $campusName;
    private string $sheetTitle;

    public function __construct(
        string $teacherName,
        Collection $records,
        string $yearMonth,
        string $campusName
    ) {
        $this->teacherName = $teacherName;
        $this->records     = $records;
        $this->yearMonth   = $yearMonth;
        $this->campusName  = $campusName;
        // Excel sheet name ≤ 31 chars, strip forbidden chars; must not be empty
        $sanitized = $this->sanitizeSheetName($teacherName);
        $this->sheetTitle = $sanitized !== '' ? $sanitized : 'Sheet';
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function array(): array
    {
        $title = "{$this->campusName} {$this->yearMonth} 老師刷卡清單";

        $rows = [
            [$title, null, null, null, null, null, null, null, null, null],
            ['老師名', '刷卡時間', '日期', '時間', null, '日期', '跑校', '上班', '下班', '加班'],
        ];

        $leftRows  = $this->buildSwipeRows();
        $rightRows = $this->buildCalendarRows();

        $total = max(count($leftRows), count($rightRows));
        for ($i = 0; $i < $total; $i++) {
            $left  = $leftRows[$i]  ?? [null, null, null, null];
            $right = $rightRows[$i] ?? [null, null, null, null, null];
            $rows[] = array_merge($left, [null], $right);
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        // Merge title row
        $sheet->mergeCells('A1:J1');

        return [
            1 => [
                'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 12],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F5496']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                                'vertical'   => Alignment::VERTICAL_CENTER],
            ],
            2 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9E1F2']],
            ],
        ];
    }

    // ─── Private helpers ──────────────────────────────────────────

    /**
     * 左側流水帳：每筆 TeacherSingIn → SignInDT 行 + SignOutDT 行（若不為 null）
     */
    private function buildSwipeRows(): array
    {
        $rows = [];
        foreach ($this->records as $rec) {
            if (! $rec->sign_in_dt) {
                continue;
            }
            $signIn = Carbon::parse($rec->sign_in_dt);
            $rows[] = [
                $this->teacherName,
                $signIn->format('Y-m-d H:i:s'),
                $signIn->format('Y-m-d'),
                $signIn->format('h:i A'),
            ];

            if (! empty($rec->sign_out_dt)) {
                $signOut = Carbon::parse($rec->sign_out_dt);
                $rows[]  = [
                    $this->teacherName,
                    $signOut->format('Y-m-d H:i:s'),
                    $signOut->format('Y-m-d'),
                    $signOut->format('h:i A'),
                ];
            }
        }
        return $rows;
    }

    /**
     * 右側月曆摘要：每日一行，上班 = min(SignInDT)，下班 = max(SignOutDT)
     */
    private function buildCalendarRows(): array
    {
        // Aggregate per-day sign-in / sign-out
        $daySignIn  = [];  // date => earliest SignInDT string
        $daySignOut = [];  // date => latest SignOutDT string

        foreach ($this->records as $rec) {
            if (! $rec->sign_in_dt) {
                continue;
            }
            $date = substr((string) $rec->sign_in_dt, 0, 10);

            if (! isset($daySignIn[$date]) || $rec->sign_in_dt < $daySignIn[$date]) {
                $daySignIn[$date] = $rec->sign_in_dt;
            }
            if (! empty($rec->sign_out_dt)) {
                if (! isset($daySignOut[$date]) || $rec->sign_out_dt > $daySignOut[$date]) {
                    $daySignOut[$date] = $rec->sign_out_dt;
                }
            }
        }

        $rows    = [];
        $current = Carbon::createFromFormat('Y-m', $this->yearMonth)->startOfMonth();
        $end     = $current->copy()->endOfMonth();

        while ($current->lte($end)) {
            $dateStr    = $current->format('Y-m-d');
            $weekday    = self::$weekdayMap[$current->dayOfWeek];
            $dateLabel  = "{$dateStr}({$weekday})";

            $signInTime  = isset($daySignIn[$dateStr])
                ? Carbon::parse($daySignIn[$dateStr])->format('h:i A')
                : null;
            $signOutTime = isset($daySignOut[$dateStr])
                ? Carbon::parse($daySignOut[$dateStr])->format('h:i A')
                : null;

            $rows[] = [$dateLabel, null, $signInTime, $signOutTime, null];
            $current->addDay();
        }

        return $rows;
    }

    private function sanitizeSheetName(string $name): string
    {
        // Remove Excel-forbidden chars
        $name = preg_replace('/[\/\\\\?\*:\[\]]/', '', $name);
        return mb_substr($name, 0, 31);
    }
}

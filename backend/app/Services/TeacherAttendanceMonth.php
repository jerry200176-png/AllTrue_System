<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * 老師月出勤：把 TeacherSingIn 列轉成「每天一列」。
 * 月匯出 xlsx 與網頁月檢視共用，兩邊數字才會一樣。
 *
 * 規則（ADP / Frappe HRMS 做法）：
 * - 有上下班的那組才算工時；同一天多組相加（中間去別校的時間不算）。
 * - 只刷一次（沒有下班，或下班是系統 00:05 自動補的 23:59）→ 標異常、不算工時，
 *   上班／下班留空，註記「只刷一次 HH:mm」，等主任補登。
 * - 今天還沒刷下班 → 「上班中」，不算異常。
 */
class TeacherAttendanceMonth
{
    /** CloseOrphanTeacherSignIns 寫入的 Memo */
    public const AUTO_CLOSE_MEMO = '系統自動補登簽退';

    private const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

    /**
     * @param Collection $records    單一老師的列：id, sign_in_dt, sign_out_dt, memo
     * @param array      $adjustedIds 有人工修正紀錄的 TeacherSingIn id => true
     * @param array      $runDates    'Y-m-d' => true：當天在 2 間以上分校刷卡
     * @return list<array{date:string,label:string,run_school:bool,sign_in:?string,sign_out:?string,minutes:?int,note:string,anomaly:bool,corrected:bool}>
     */
    public static function days(Collection $records, string $yearMonth, array $adjustedIds = [], array $runDates = [], ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $byDate = $records->filter(fn ($r) => $r->sign_in_dt)
            ->groupBy(fn ($r) => substr((string) $r->sign_in_dt, 0, 10));

        $days = [];
        $day = Carbon::createFromFormat('Y-m-d', $yearMonth . '-01')->startOfDay();
        $end = $day->copy()->endOfMonth();
        for (; $day->lte($end); $day->addDay()) {
            $date = $day->toDateString();
            $pairs = [];
            $lone = [];
            $openIn = null;
            $corrected = false;
            foreach ($byDate->get($date, collect())->sortBy('sign_in_dt') as $rec) {
                $corrected = $corrected || isset($adjustedIds[$rec->id]);
                $in = Carbon::parse($rec->sign_in_dt)->startOfMinute();
                $autoClosed = ($rec->memo ?? null) === self::AUTO_CLOSE_MEMO && ! isset($adjustedIds[$rec->id]);
                if ($rec->sign_out_dt && ! $autoClosed) {
                    $pairs[] = [$in, Carbon::parse($rec->sign_out_dt)->startOfMinute()];
                } elseif (! $rec->sign_out_dt && $date === $today) {
                    $openIn = $in;
                } else {
                    $lone[] = $in->format('H:i');
                }
            }

            $notes = array_map(fn ($t) => "只刷一次 {$t}", $lone);
            if ($openIn) {
                $notes[] = '上班中';
            }
            if ($corrected) {
                $notes[] = '已修正';
            }

            $days[] = [
                'date'       => $date,
                'label'      => $date . '(' . self::WEEKDAYS[$day->dayOfWeek] . ')',
                'run_school' => isset($runDates[$date]),
                'sign_in'    => $pairs ? min(array_column($pairs, 0))->format('H:i') : $openIn?->format('H:i'),
                'sign_out'   => $pairs ? max(array_column($pairs, 1))->format('H:i') : null,
                'minutes'    => $pairs ? array_sum(array_map(fn ($p) => $p[0]->diffInMinutes($p[1]), $pairs)) : null,
                'note'       => implode('、', $notes),
                'anomaly'    => $lone !== [],
                'corrected'  => $corrected,
            ];
        }

        return $days;
    }

    /** @return array{days_present:int,minutes:int,anomaly_days:int,corrected_days:int,run_days:int} */
    public static function totals(array $days): array
    {
        $count = fn (callable $f) => count(array_filter($days, $f));

        return [
            'days_present'   => $count(fn ($d) => $d['sign_in'] !== null || $d['anomaly']),
            'minutes'        => array_sum(array_column($days, 'minutes')),
            'anomaly_days'   => $count(fn ($d) => $d['anomaly']),
            'corrected_days' => $count(fn ($d) => $d['corrected']),
            'run_days'       => $count(fn ($d) => $d['run_school']),
        ];
    }
}

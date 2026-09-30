<?php

namespace Tests\Unit;

use App\Services\TeacherAttendanceMonth;
use PHPUnit\Framework\TestCase;

/** 老師月出勤每日一列：只刷一次不算工時、系統補簽退算異常、修正後照新時間算。 */
class TeacherAttendanceMonthTest extends TestCase
{
    private static function rec(int $id, string $in, ?string $out, ?string $memo = null): object
    {
        return (object) ['id' => $id, 'sign_in_dt' => $in, 'sign_out_dt' => $out, 'memo' => $memo];
    }

    private static function day(array $days, string $date): array
    {
        return collect($days)->firstWhere('date', $date);
    }

    public function test_daily_rows_and_totals(): void
    {
        $auto = TeacherAttendanceMonth::AUTO_CLOSE_MEMO;
        $days = TeacherAttendanceMonth::days(collect([
            self::rec(1, '2026-08-01 09:59:24', '2026-08-01 17:53:20'),
            self::rec(2, '2026-08-03 21:31:15', null),                      // 只刷一次
            self::rec(3, '2026-08-04 13:00:00', '2026-08-04 15:00:00'),     // 兩組相加
            self::rec(4, '2026-08-04 18:00:00', '2026-08-04 21:30:00'),
            self::rec(5, '2026-08-05 20:10:00', '2026-08-05 23:59:00', $auto), // 系統補簽退
            self::rec(6, '2026-08-06 13:00:00', '2026-08-06 21:00:00', $auto), // 補簽退後主任修正
            self::rec(7, '2026-08-31 14:00:00', null),                      // 今天，上班中
            self::rec(8, '2026-08-10 18:00:00', '2026-08-10 09:00:00'),     // 壞資料：下班早於上班
        ]), '2026-08', [6 => true], ['2026-08-04' => true], '2026-08-31');

        $this->assertCount(31, $days);
        $this->assertSame('2026-08-01(六)', $days[0]['label']);

        $d = self::day($days, '2026-08-01');
        $this->assertSame(['09:59', '17:53', 474, '', false], [$d['sign_in'], $d['sign_out'], $d['minutes'], $d['note'], $d['anomaly']]);

        $d = self::day($days, '2026-08-03');
        $this->assertSame([null, null, null, '只刷一次 21:31', true], [$d['sign_in'], $d['sign_out'], $d['minutes'], $d['note'], $d['anomaly']]);

        $d = self::day($days, '2026-08-04');
        $this->assertSame(['13:00', '21:30', 330, true], [$d['sign_in'], $d['sign_out'], $d['minutes'], $d['run_school']]);

        $d = self::day($days, '2026-08-05');
        $this->assertSame([null, null, '只刷一次 20:10', true], [$d['sign_in'], $d['minutes'], $d['note'], $d['anomaly']]);

        $d = self::day($days, '2026-08-06');
        $this->assertSame(['13:00', '21:00', 480, '已修正', false], [$d['sign_in'], $d['sign_out'], $d['minutes'], $d['note'], $d['anomaly']]);

        $d = self::day($days, '2026-08-31');
        $this->assertSame(['14:00', null, null, '上班中', false], [$d['sign_in'], $d['sign_out'], $d['minutes'], $d['note'], $d['anomaly']]);

        $d = self::day($days, '2026-08-10');
        $this->assertSame([null, '只刷一次 18:00', true], [$d['minutes'], $d['note'], $d['anomaly']]);

        $this->assertSame([
            'days_present'   => 7,
            'minutes'        => 474 + 330 + 480,
            'anomaly_days'   => 3,
            'corrected_days' => 1,
            'run_days'       => 1,
        ], TeacherAttendanceMonth::totals($days));
    }
}

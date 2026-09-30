<?php

namespace Tests\Unit;

use App\Services\TeacherAttendanceMonth;
use PHPUnit\Framework\TestCase;

/** 老師月出勤每日一列：只刷一次不算工時、系統補簽退算異常、修正後照新時間算。 */
class TeacherAttendanceMonthTest extends TestCase
{
    private static function rec(int $id, string $in, ?string $out, ?string $memo = null, int $campus = 1): object
    {
        return (object) ['id' => $id, 'sign_in_dt' => $in, 'sign_out_dt' => $out, 'memo' => $memo, 'campus_id' => $campus];
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
            'late_days'      => 0,
            'missed_days'    => 0,
            'admin_days'     => 7,
        ], TeacherAttendanceMonth::totals($days));
    }

    /** 有課才判斷遲到／缺卡；遲到每間分校各自比第一堂；寬限 10 分鐘。 */
    public function test_status_against_classes(): void
    {
        $c = fn (string $start, int $campus = 1) => ['start' => $start, 'campus_id' => $campus];
        $days = TeacherAttendanceMonth::days(collect([
            self::rec(1, '2026-09-01 10:10:59', '2026-09-01 12:00:00'),        // 寬限內 → 準時
            self::rec(2, '2026-09-02 10:11:00', '2026-09-02 12:00:00'),        // 晚 11 分 → 遲到
            self::rec(3, '2026-09-03 09:50:00', '2026-09-03 12:00:00'),        // 跑校：A 校準時
            self::rec(4, '2026-09-03 14:55:00', '2026-09-03 18:00:00', null, 2), // B 校 15:00 課，準時
            self::rec(5, '2026-09-04 09:00:00', '2026-09-04 12:00:00'),        // 沒課 → 行政出勤
            self::rec(6, '2026-09-07 09:00:00', '2026-09-07 12:00:00'),        // A 校有刷、B 校有課沒刷 → 缺卡
            self::rec(7, '2026-09-10 10:30:00', null),                         // 今天 → 遲到但上班中
        ]), '2026-09', [], [], '2026-09-10 16:00:00', [
            '2026-09-01' => [$c('10:00'), $c('13:00')],
            '2026-09-02' => [$c('10:00')],
            '2026-09-03' => [$c('10:00'), $c('15:00', 2)],
            '2026-09-05' => [$c('10:00')],                  // 有課沒刷 → 缺卡
            '2026-09-07' => [$c('09:00'), $c('15:00', 2)],
            '2026-09-10' => [$c('10:00'), $c('17:00', 2)],  // B 校 17:00 還沒到
            '2026-09-20' => [$c('10:00')],                  // 未來，不判斷
        ]);

        $got = fn (string $date) => [self::day($days, $date)['status'], self::day($days, $date)['late_minutes']];
        $this->assertSame(['on_time', null], $got('2026-09-01'));
        $this->assertSame(['late', 11], $got('2026-09-02'));
        $this->assertSame(['on_time', null], $got('2026-09-03'));
        $this->assertSame(['admin', null], $got('2026-09-04'));
        $this->assertSame(['missed', null], $got('2026-09-05'));
        $this->assertSame(['missed', null], $got('2026-09-07'));
        $this->assertSame(['late', 30], $got('2026-09-10'));
        $this->assertSame([null, null], $got('2026-09-20'));
        $this->assertSame([null, null], $got('2026-09-06'));
        $this->assertSame('10:00', self::day($days, '2026-09-05')['first_class']);

        $t = TeacherAttendanceMonth::totals($days);
        $this->assertSame([2, 2, 1], [$t['late_days'], $t['missed_days'], $t['admin_days']]);
    }

    /** 補卡改了時間：狀態依新時間算，但保留「原本遲到幾分」；跨校自動簽退算異常。 */
    public function test_correction_keeps_original_late_and_cross_campus_close_is_anomaly(): void
    {
        $fixed = self::rec(1, '2026-09-01 10:00:00', '2026-09-01 12:00:00');
        $fixed->original_sign_in_dt = '2026-09-01 10:40:00';
        $cross = self::rec(2, '2026-09-02 09:00:00', '2026-09-02 13:00:00', TeacherAttendanceMonth::CROSS_CAMPUS_MEMO);

        $days = TeacherAttendanceMonth::days(collect([$fixed, $cross]), '2026-09', [1 => true], [], '2026-09-30 12:00:00', [
            '2026-09-01' => [['start' => '10:00', 'campus_id' => 1]],
        ]);

        $d = self::day($days, '2026-09-01');
        $this->assertSame(['on_time', 40, true], [$d['status'], $d['original_late_minutes'], $d['corrected']]);

        $d = self::day($days, '2026-09-02');
        $this->assertSame([true, null, '跨校未簽退 09:00'], [$d['anomaly'], $d['minutes'], $d['note']]);
    }
}

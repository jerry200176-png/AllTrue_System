<?php

namespace Tests\Unit;

use App\Services\AttendanceEffectsService;
use App\Support\AttendanceStatus;
use PHPUnit\Framework\TestCase;

/**
 * #765 出缺席狀態語意化 — 驗證 registry 與競品表一致，且扣堂/計薪集合正確。
 * 金錢邏輯安全網：任何狀態的 deductible/payable 改動都會在此被擋下。
 */
class AttendanceStatusSemanticsTest extends TestCase
{
    /**
     * @test 競品 backerwebapp 對照表：扣堂 / 計薪 / 需日誌。
     * @dataProvider statusTable
     */
    public function status_metadata_matches_spec(string $code, bool $deduct, bool $payable, bool $log): void
    {
        $this->assertTrue(AttendanceStatus::exists($code), "$code 應存在");
        $this->assertSame($deduct, AttendanceStatus::isDeductible($code), "$code 扣堂");
        $this->assertSame($payable, AttendanceStatus::isPayable($code), "$code 計薪");
        $this->assertSame($log, AttendanceStatus::requiresLog($code), "$code 需日誌");
    }

    public static function statusTable(): array
    {
        // code, deductible(扣堂), payable(計薪), requires_log(需日誌)
        return [
            // 既有（行為不變）
            'present' => ['present', true,  true,  true],
            'late'    => ['late',    true,  true,  true],
            'absent'  => ['absent',  false, false, false],
            'leave'   => ['leave',   false, false, false],
            // #765 新增（依 issue 表）
            'trial'           => ['trial',           false, false, true],
            'trial_absent'    => ['trial_absent',    false, false, false],
            'tutoring'        => ['tutoring',        false, false, true],
            'tutoring_absent' => ['tutoring_absent', false, false, false],
            'duty'            => ['duty',            false, true,  false],  // 值班：計薪但不扣堂
            'makeup'          => ['makeup',          true,  true,  true],   // 補課：扣堂且計薪
            'suspended'       => ['suspended',       false, false, false],
        ];
    }

    /** @test 扣堂集合：present/late/makeup；不含 duty/trial/tutoring */
    public function deductible_codes_set(): void
    {
        $set = AttendanceStatus::deductibleCodes();
        foreach (['present', 'late', 'makeup'] as $c) {
            $this->assertContains($c, $set, "$c 應扣堂");
        }
        foreach (['duty', 'trial', 'tutoring', 'absent', 'leave', 'suspended'] as $c) {
            $this->assertNotContains($c, $set, "$c 不應扣堂");
        }
    }

    /** @test 計薪 session 集合：含 attended/late/completed/duty；不含 trial/tutoring_attend/suspended */
    public function payable_session_statuses_set(): void
    {
        $set = AttendanceStatus::payableSessionStatuses();
        foreach (['attended', 'late', 'completed', 'duty'] as $s) {
            $this->assertContains($s, $set, "$s 應計薪");
        }
        foreach (['trial', 'tutoring_attend', 'tutoring_absent', 'suspended', 'absent', 'leave'] as $s) {
            $this->assertNotContains($s, $set, "$s 不應計薪");
        }
    }

    /** @test session 狀態映射：makeup→attended（扣+薪）、duty→duty（薪不扣）、trial→trial（皆否） */
    public function session_status_mapping(): void
    {
        $this->assertSame('attended', AttendanceEffectsService::sessionStatusFromAttendanceStatus('makeup'));
        $this->assertSame('duty', AttendanceEffectsService::sessionStatusFromAttendanceStatus('duty'));
        $this->assertSame('trial', AttendanceEffectsService::sessionStatusFromAttendanceStatus('trial'));
        $this->assertSame('attended', AttendanceEffectsService::sessionStatusFromAttendanceStatus('present'));
        $this->assertSame('leave', AttendanceEffectsService::sessionStatusFromAttendanceStatus('excused'));
    }

    /** @test 值班映射為 duty，且只能計薪、不可扣堂。 */
    public function duty_is_payable_without_being_deductible(): void
    {
        $this->assertSame('duty', AttendanceEffectsService::sessionStatusFromAttendanceStatus('duty'));
        $this->assertTrue(AttendanceStatus::isPayable('duty'));
        $this->assertFalse(AttendanceStatus::isDeductible('duty'));
    }
}

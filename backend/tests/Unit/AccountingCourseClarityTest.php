<?php

namespace Tests\Unit;

use App\Models\StudentClass;
use App\Support\AccountingCourseClarity;
use Tests\TestCase;

class AccountingCourseClarityTest extends TestCase
{
    public function test_class_type_labels(): void
    {
        $this->assertSame('輔導', AccountingCourseClarity::classTypeLabel('tutoring'));
        $this->assertSame('試聽', AccountingCourseClarity::classTypeLabel('trial'));
        $this->assertSame('', AccountingCourseClarity::classTypeLabel(null));
    }

    public function test_completed_stop_is_history(): void
    {
        $sc = new StudentClass();
        $sc->Stop = 1;
        $sc->closed_reason = 'completed';
        $sc->ScheduleMode = 'count';

        $life = AccountingCourseClarity::lifecycle($sc);
        $this->assertTrue($life['is_history']);
        $this->assertSame('history_completed', $life['code']);
    }

    public function test_first_session_keeps_live_date_for_prepaid(): void
    {
        $sc = new StudentClass();
        $sc->StartDate = '2026-03-01';
        $result = AccountingCourseClarity::firstSession([
            'first_live' => '2026-05-01',
            'first_any' => '2026-04-20',
        ], $sc);

        $this->assertSame('2026-05-01', $result['date']);
        $this->assertSame('session', $result['source']);
        $this->assertSame('', $result['note']);
    }

    public function test_history_course_without_live_sessions_uses_contract_start(): void
    {
        $sc = new StudentClass();
        $sc->Stop = 1;
        $sc->closed_reason = 'completed';
        $sc->ScheduleMode = 'count';
        $sc->StartDate = '2026-03-01';

        $result = AccountingCourseClarity::firstSession([
            'first_live' => null,
            'first_any' => null,
        ], $sc);

        $this->assertNull($result['date']);
        $this->assertSame('2026-03-01', $result['display']);
        $this->assertSame('contract', $result['source']);
        $this->assertStringContainsString('歷史', $result['note']);
    }

    public function test_zero_reason_for_trial_and_tutoring(): void
    {
        $this->assertSame('trial', AccountingCourseClarity::zeroReason(0, 'trial'));
        $this->assertSame('tutoring', AccountingCourseClarity::zeroReason(0, 'tutoring'));
        $this->assertSame('zero', AccountingCourseClarity::zeroReason(0, 'one_on_one'));
        $this->assertNull(AccountingCourseClarity::zeroReason(8800, 'trial'));
    }
}

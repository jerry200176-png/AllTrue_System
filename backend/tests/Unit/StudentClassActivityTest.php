<?php

namespace Tests\Unit;

use App\Models\StudentClass;
use App\Services\StudentClassActivity;
use Tests\TestCase;

/** in-app #427: the one 進行中／歷史 verdict behind the course list, the duplicate prompt and the create guard. */
class StudentClassActivityTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: ?string, 3: ?string, 4: bool}> */
    public static function cases(): array
    {
        return [
            'open count course' => [[], 5, 'unpaid', null, true],
            'finished: used up and paid' => [[], 0, 'paid', 'completed', false],
            'finished free trial' => [['ClassType' => 'trial'], 0, 'free', 'completed', false],
            'used up but still owed' => [[], 0, 'unpaid', null, true],
            'paid, partial minutes left (rounds to 0 lessons)' => [['RemainingMinutes' => 30], 0, 'paid', null, true],
            'paid, explicit zero minutes' => [['RemainingMinutes' => 0], 0, 'paid', 'completed', false],
            'paid, stale whole-lesson minutes after self-heal' => [['RemainingMinutes' => 60, 'SessionDuration' => 60], 0, 'paid', 'completed', false],
            'used up, verdict pending review' => [[], 0, 'review_required', null, true],
            'closed: settled_pending' => [['closed_reason' => 'settled_pending'], 2, 'unpaid', 'settled_pending', false],
            'closed: waived' => [['closed_reason' => 'waived'], 3, 'paid', 'waived', false],
            'closed: converted trial, Stop never set' => [['closed_reason' => 'converted_trial'], 1, 'free', 'converted_trial', false],
            'closed: amended contract, stopped stays listed' => [['closed_reason' => 'contract_amended', 'Stop' => 1], 0, 'unpaid', null, false],
            'stopped (paused)' => [['Stop' => 1], 4, 'unpaid', null, false],
            'stopped and finished' => [['Stop' => 1], 0, 'paid', 'completed', false],
            'monthly running' => [['ScheduleMode' => 'date'], 0, 'paid', null, true],
            'monthly stopped' => [['ScheduleMode' => 'date', 'Stop' => 1], 0, 'paid', 'completed', false],
            'package member reads the pool' => [['PackageID' => 7], 0, 'paid', null, true],
        ];
    }

    /**
     * @dataProvider cases
     * @param array<string, mixed> $attrs
     */
    public function test_verdict(array $attrs, int $ownRemaining, ?string $settlement, ?string $reason, bool $active): void
    {
        $course = new StudentClass();
        $course->forceFill(array_merge(['Stop' => 0, 'ScheduleMode' => 'count', 'closed_reason' => null, 'PackageID' => null], $attrs));

        $this->assertSame($reason, StudentClassActivity::historyReason($course, $ownRemaining, $settlement));
        $this->assertSame($active, StudentClassActivity::isActive($course, $reason));
    }

    public function test_self_heal_matches_purchased_minus_used(): void
    {
        $course = (new StudentClass())->forceFill(['ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingMinutes' => null]);
        $this->assertSame(['used' => 1, 'remaining' => 7], StudentClassActivity::selfHealedUsage($course, 1));
        $this->assertSame(['used' => 8, 'remaining' => 0], StudentClassActivity::selfHealedUsage($course, 12));

        $partial = (new StudentClass())->forceFill(['ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingMinutes' => 90]);
        $this->assertNull(StudentClassActivity::selfHealedUsage($partial, 1), 'minutes stay authoritative (#613 A1)');
        $this->assertNull(StudentClassActivity::selfHealedUsage((new StudentClass())->forceFill(['ScheduleMode' => 'date', 'SessionCount' => 4]), 1));
    }
}

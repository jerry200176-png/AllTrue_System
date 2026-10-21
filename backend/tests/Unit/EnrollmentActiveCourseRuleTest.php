<?php

namespace Tests\Unit;

use App\Services\EnrollmentActiveCourseRule;
use PHPUnit\Framework\TestCase;

/**
 * in-app #427: the 「已有進行中課程」 prompt counts exactly the courses the 學生管理 list shows.
 * The same fixture is asserted against the page verdict in
 * frontend/src/components/__tests__/StudentActiveCourseParity.test.js.
 */
class EnrollmentActiveCourseRuleTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/active_course_parity.json';

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function parityRows(): array
    {
        $rows = json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR)['rows'];
        $cases = [];
        foreach ($rows as $row) {
            $cases[$row['case']] = [$row];
        }

        return $cases;
    }

    /**
     * @dataProvider parityRows
     * @param array<string, mixed> $row
     */
    public function test_rule_matches_the_shared_parity_fixture(array $row): void
    {
        $this->assertSame($row['expected_active'], EnrollmentActiveCourseRule::isActive($row), $row['case']);
        if (array_key_exists('expected_remaining', $row)) {
            $this->assertSame($row['expected_remaining'], EnrollmentActiveCourseRule::remainingSessions($row), $row['case']);
        }
    }

    public function test_any_closed_reason_ends_the_course(): void
    {
        foreach (['settled', 'settled_pending', 'waived', 'completed', 'contract_amended', 'converted_trial', 'usage_settled'] as $reason) {
            $this->assertFalse(EnrollmentActiveCourseRule::isActive([
                'status' => 'active', 'closed_reason' => $reason, 'payment_type' => 'session',
                'remaining_sessions' => 5, 'payment_status' => 'unpaid',
            ]), $reason);
        }
    }
}

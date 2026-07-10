<?php

namespace Tests\Feature;

use App\Models\PayrollTeacherBranchRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for in-app #198 (GH #1151): a part-time teacher's per-branch rate override
 * is effective-dated. `latestForTeacherBranch(asOf)` must pick the rule effective on/before
 * the payroll month — so a correction dated in the FUTURE (e.g. today) does NOT apply to a
 * past month, and a correction dated at the month start DOES. This is why the #198 fix
 * defaults the editor's effective date to the viewed month's first day.
 */
class PayrollTeacherRuleEffectiveDatingTest extends TestCase
{
    use RefreshDatabase;

    private const TEACHER = 90420;
    private const BRANCH = 17;

    private function rule(string $effectiveFrom, int $junior): void
    {
        PayrollTeacherBranchRule::create([
            'teacher_user_id' => self::TEACHER,
            'branch_id' => self::BRANCH,
            'base_rates' => ['high' => 400, 'junior' => $junior, 'elementary' => 300, 'tutoring' => 200],
            'headcount_bonus' => 50,
            'effective_from' => $effectiveFrom,
            'use_branch_default' => false,
            'created_at' => now(),
        ]);
    }

    public function test_future_dated_correction_does_not_apply_to_the_month_under_review(): void
    {
        // The #198 situation: old override 400 effective in May; a 350 correction dated 7/10 (future vs June).
        $this->rule('2026-05-01', 400);
        $this->rule('2026-07-10', 350);

        // June payroll (asOf = end of June) still resolves the OLD 400 rule — reproduces #198.
        $june = PayrollTeacherBranchRule::latestForTeacherBranch(self::TEACHER, self::BRANCH, '2026-06-30');
        $this->assertNotNull($june);
        $this->assertSame(400, (int) $june->base_rates['junior'], 'future-dated correction must not retroactively fix June');
    }

    public function test_month_start_dated_correction_applies_to_that_month(): void
    {
        // The #198 FIX behaviour: correction dated at the reviewed month's first day.
        $this->rule('2026-05-01', 400);
        $this->rule('2026-06-01', 350);

        $june = PayrollTeacherBranchRule::latestForTeacherBranch(self::TEACHER, self::BRANCH, '2026-06-30');
        $this->assertSame(350, (int) $june->base_rates['junior'], 'correction effective from the month start applies to that month');

        // May (before the correction) keeps the old rate — no accidental retroactive change further back.
        $may = PayrollTeacherBranchRule::latestForTeacherBranch(self::TEACHER, self::BRANCH, '2026-05-31');
        $this->assertSame(400, (int) $may->base_rates['junior']);

        // July onward also uses the corrected rate (latest wins).
        $july = PayrollTeacherBranchRule::latestForTeacherBranch(self::TEACHER, self::BRANCH, '2026-07-31');
        $this->assertSame(350, (int) $july->base_rates['junior']);
    }
}

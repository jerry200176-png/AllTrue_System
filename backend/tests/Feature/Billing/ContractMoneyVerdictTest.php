<?php

namespace Tests\Feature\Billing;

use App\Models\Student;
use App\Services\Billing\ContractMoneyVerdict;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ARCH2 PR1: the Verdict interface over the same fixtures as the characterization test (no caller moved yet). */
class ContractMoneyVerdictTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private function verdict(\App\Models\StudentClass $course): array
    {
        $v = app(ContractMoneyVerdict::class)->forCourse($course);

        return [$v->status, $v->owed, $v->applied, $v->outstanding, $v->source, $v->currentPeriod];
    }

    public function test_paid_flag_loses_to_an_unpaid_invoice_and_wins_only_without_any_invoice(): void
    {
        $this->assertSame(['unpaid', 10000, 0, 10000, 'invoice', '2026-08'], $this->verdict($this->course([['2026-08', 10000, 'unpaid', 0, []]], ['Paid' => 1])));
        $this->assertSame(['paid', 10000, 10000, 0, 'legacy_flag', null], $this->verdict($this->course([], ['Paid' => 1])));
        $this->assertSame(['paid', 10000, 10000, 0, 'invoice', '2026-08'], $this->verdict($this->course([['2026-08', 10000, 'paid', 10000, [[10000, 'cash']]]])));
    }

    public function test_stored_paid_amount_never_counts_only_payment_rows(): void
    {
        $this->assertSame(['unpaid', 10000, 0, 10000, 'invoice', '2026-08'], $this->verdict($this->course([['2026-08', 10000, 'unpaid', 10000, []]])));
    }

    public function test_partial_stopped_trial_and_unbilled(): void
    {
        $this->assertSame(['partial', 10000, 4000, 6000, 'invoice', '2026-08'],
            $this->verdict($this->course([['2026-08', 10000, 'partial', 4000, [[4000, 'cash']]]], ['Stop' => 1, 'closed_reason' => 'settled_pending'])));
        $this->assertSame(['free', 0, 0, 0, 'none', null], $this->verdict($this->course([], ['Charge' => 0, 'Rate' => 0])));
        $this->assertSame(['unbilled', 6000, 0, 6000, 'none', null],
            $this->verdict($this->course([], ['ScheduleMode' => 'date', 'Charge' => 6000, 'EndDate' => '2026-07-31'], ['2026-07-06'])));
    }

    public function test_batch_is_keyed_by_course_and_matches_single(): void
    {
        $student = Student::create(['name' => 'batch', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $a = $this->course([['2026-08', 10000, 'partial', 3000, [[3000, 'cash']]]], [], [], $student);
        $b = $this->course([], ['Charge' => 0, 'Rate' => 0], [], $student);
        $all = app(ContractMoneyVerdict::class)->forCourses([$a, $b]);

        $this->assertSame([(int) $a->ID, (int) $b->ID], array_keys($all));
        $this->assertSame(['partial', 7000, 'free'], [$all[$a->ID]->status, $all[$a->ID]->outstanding, $all[$b->ID]->status]);
        $this->assertTrue($all[$b->ID]->isSettled());
    }

    public function test_lessons_are_tagged_with_the_money_state_of_the_invoice_covering_them(): void
    {
        $course = $this->course([['2026-08', 6000, 'partial', 2000, [[2000, 'cash']]]],
            ['ScheduleMode' => 'date', 'Charge' => 6000], ['2026-07-20', '2026-08-03', '2026-08-10']);
        $tags = array_column(app(ContractMoneyVerdict::class)->lessons($course), 'payment', 'date');

        $this->assertSame(['2026-07-20' => 'no_invoice', '2026-08-03' => 'partial', '2026-08-10' => 'partial'], $tags);
    }
}

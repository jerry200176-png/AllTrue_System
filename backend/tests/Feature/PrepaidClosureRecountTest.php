<?php

namespace Tests\Feature;

use App\Models\{Invoice, Payment};
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

/** #5197: financial closure of zero prepaid credit must survive the canonical recount. */
class PrepaidClosureRecountTest extends TestCase
{
    use RefreshDatabase;
    use SubjectCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fully_refunded_unused_contract_is_not_reopened_by_recounting(): void
    {
        [$token, $course] = $this->fixture(used: 0);
        $this->withToken($token)->postJson("/api/v1/student-classes/{$course->ID}/refund", [
            'amount' => 12000, 'method' => 'cash', 'date' => '2026-09-30', 'reason' => 'isolated complete refund',
        ])->assertOk();
        $this->assertSame([0, 1, 'refunded'], [(int) $course->fresh()->SessionCount, (int) $course->fresh()->Stop, $course->fresh()->closed_reason]);
        $payments = Payment::orderBy('id')->get()->toJson();
        $invoices = Invoice::orderBy('id')->get()->toJson();
        SessionDeductionService::recomputeCounters((int) $course->ID);
        $this->assertSame(1, (int) $course->fresh()->Stop);
        $this->assertSame('refunded', $course->fresh()->closed_reason);
        $this->assertSame($payments, Payment::orderBy('id')->get()->toJson());
        $this->assertSame($invoices, Invoice::orderBy('id')->get()->toJson());
    }

    public function test_explicit_zero_count_completion_stays_closed_and_date_mode_stays_unchanged(): void
    {
        [, $course] = $this->fixture(used: 0);
        $course->forceFill(['SessionCount' => 0, 'Stop' => 1, 'closed_reason' => 'completed'])->save();
        SessionDeductionService::recomputeCounters((int) $course->ID);
        $this->assertSame(1, (int) $course->fresh()->Stop);
        $this->assertSame(0, (int) $course->fresh()->RemainingSessions);

        [, $monthly] = $this->fixture(used: 0);
        $monthly->forceFill(['ScheduleMode' => 'date', 'SessionCount' => 0, 'Stop' => 0, 'closed_reason' => null])->save();
        SessionDeductionService::recomputeCounters((int) $monthly->ID);
        $this->assertSame(0, (int) $monthly->fresh()->Stop);
        $this->assertSame(0, (int) $monthly->fresh()->RemainingSessions);
    }
}

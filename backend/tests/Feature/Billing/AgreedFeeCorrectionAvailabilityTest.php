<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\AgreedFeeCorrection;
use Tests\Support\AgreedFeeFixture;
use Tests\Support\AgreedFeeRefreshDatabase;
use Tests\TestCase;

/** Read-only UI hint on GET /student-classes/{id}/invoices mirrors the correction controller's gates. */
class AgreedFeeCorrectionAvailabilityTest extends TestCase
{
    use AgreedFeeRefreshDatabase, AgreedFeeFixture;

    private function availability(array $f): mixed
    {
        return $this->withToken($f['token'])
            ->getJson('/api/v1/student-classes/'.$f['course']->ID.'/invoices')
            ->assertOk()
            ->json('agreed_fee_correction_available');
    }

    public function test_false_while_flag_is_off_by_default(): void
    {
        $f = $this->fixture();
        $f['user']->update(['type' => 'S']);
        $this->assertFalse(config('billing.agreed_fee_correction_enabled'));
        $this->assertFalse($this->availability($f));
    }

    public function test_true_for_allowlisted_course_and_super_admin_with_flag_on(): void
    {
        $f = $this->fixture();
        $f['user']->update(['type' => 'S']);
        config(['billing.agreed_fee_correction_enabled' => true]);
        $this->assertTrue($this->availability($f));
    }

    public function test_true_for_director_of_the_course_campus_with_flag_on(): void
    {
        $f = $this->fixture();
        config(['billing.agreed_fee_correction_enabled' => true]);
        $this->assertTrue($this->availability($f));
    }

    public function test_false_for_course_outside_the_allowlist(): void
    {
        $f = $this->fixture();
        $f['user']->update(['type' => 'S']);
        config(['billing.agreed_fee_correction_enabled' => true,
            'billing.agreed_fee_correction_course_ids' => [(int) $f['course']->ID + 1]]);
        $this->assertFalse($this->availability($f));
        config(['billing.agreed_fee_correction_course_ids' => [(int) $f['course']->ID],
            'billing.agreed_fee_correction_campus_ids' => [2]]);
        $this->assertFalse($this->availability($f));
    }

    public function test_helper_refuses_teacher_and_director_of_another_campus(): void
    {
        $f = $this->fixture();
        config(['billing.agreed_fee_correction_enabled' => true]);
        $course = $f['course']->fresh();
        $this->assertTrue(AgreedFeeCorrection::isAvailableFor($course, 'super_admin', []));
        $this->assertTrue(AgreedFeeCorrection::isAvailableFor($course, 'director', [1]));
        $this->assertFalse(AgreedFeeCorrection::isAvailableFor($course, 'teacher', [1]));
        $this->assertFalse(AgreedFeeCorrection::isAvailableFor($course, 'director', [2]));
        $this->assertFalse(AgreedFeeCorrection::isAvailableFor($course, 'pending', [1]));
    }
}

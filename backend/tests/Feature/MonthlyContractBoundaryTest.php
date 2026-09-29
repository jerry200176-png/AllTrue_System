<?php

namespace Tests\Feature;

use App\Models\StudentClass;
use App\Services\MonthlyContractBoundaryService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonthlyContractBoundaryTest extends TestCase
{
    public function test_paid_course_extension_requires_renewal_but_package_and_unchanged_boundary_do_not(): void
    {
        $source = new StudentClass(['ScheduleMode' => 'date', 'Paid' => 1, 'EndDate' => '2026-09-30']);
        $service = app(MonthlyContractBoundaryService::class);
        $this->assertTrue($service->extensionRequiresRenewal($source, ['EndDate' => '2026-10-31']));
        $this->assertFalse($service->extensionRequiresRenewal($source, ['EndDate' => '2026-09-30']));
        $source->PackageID = 1;
        $this->assertFalse($service->extensionRequiresRenewal($source, ['EndDate' => '2026-10-31']));
    }

    public function test_invalid_date_is_a_validation_error(): void
    {
        $source = new StudentClass(['ScheduleMode' => 'date', 'Paid' => 1, 'EndDate' => '2026-09-30']);
        $this->expectException(ValidationException::class);
        app(MonthlyContractBoundaryService::class)->extensionRequiresRenewal($source, ['EndDate' => 'bad-date']);
    }
}

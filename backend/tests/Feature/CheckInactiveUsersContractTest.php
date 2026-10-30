<?php

namespace Tests\Feature;

use App\Console\Commands\CheckInactiveUsers;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #977: preserve the existing pure template calculation on Carbon 3. */
class CheckInactiveUsersContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inactivity_template_keeps_absolute_whole_days(): void
    {
        Carbon::setTestNow('2026-10-25 12:00:00');
        $picker = new \ReflectionMethod(CheckInactiveUsers::class, 'pickTemplate');
        $command = app(CheckInactiveUsers::class);
        // last_login_at is an optional legacy attribute, not a new schema field.
        // Exercise the picker without inventing columns or changing eligibility.
        foreach ([[now()->subDays(8), 'firm_reminder'], [now()->subDays(4), 'gentle_reminder'],
            [now()->addDays(8), 'firm_reminder'], [null, 'gentle_reminder']] as [$date, $expected]) {
            $user = new User();
            $user->setRawAttributes(['last_login_at' => $date]);
            $this->assertSame($expected, $picker->invoke($command, $user));
        }
    }
}

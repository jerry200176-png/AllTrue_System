<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentClassPricingAmendment;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #4008: 免費／贈送 eligibility is judged by the rate in force for the requested term, not by any positive amendment ever recorded. */
class FreeGiftAmendmentPeriodTest extends TestCase
{
    use RefreshDatabase;

    private const RECORD = '/api/v1/payment-reports/director-record';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-13 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function amend(StudentClass $c, string $from, int $rate): void
    {
        StudentClassPricingAmendment::create(['student_class_id' => $c->ID, 'effective_from' => $from, 'rate' => $rate, 'source_reference' => 't', 'reason' => 't']);
    }

    public function test_a_future_rate_rise_does_not_block_marking_the_current_free_term(): void
    {
        $c = $this->monthlyCourse($this->student());
        $this->amend($c, '2026-12-01', 900);
        $this->withToken($this->directorToken())->postJson(self::RECORD, $this->payload($c, ['free_gift' => true]))
            ->assertOk()->assertJsonPath('zero_amount_reason', Invoice::ZERO_REASON_FREE_GIFT);
    }

    public function test_an_old_positive_amendment_replaced_by_a_zero_rate_one_does_not_block(): void
    {
        $c = $this->monthlyCourse($this->student());
        $this->amend($c, '2026-09-01', 800);
        $this->amend($c, '2026-10-01', 0);
        $this->withToken($this->directorToken())->postJson(self::RECORD, $this->payload($c, ['free_gift' => true]))->assertOk();
    }

    public function test_a_positive_rate_in_force_for_the_term_still_refuses(): void
    {
        $c = $this->monthlyCourse($this->student());
        $this->amend($c, '2026-10-01', 800);
        $this->withToken($this->directorToken())->postJson(self::RECORD, $this->payload($c, ['free_gift' => true]))
            ->assertStatus(422)->assertJsonPath('code', 'free_gift_course_has_fee');
    }

    private function payload(StudentClass $course, array $over = []): array
    {
        return array_merge(['student_class_id' => $course->ID, 'payment_date' => '2026-10-13', 'payment_method' => 'cash', 'amount' => 0], $over);
    }

    private function directorToken(int $campusId = 1): string
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function monthlyCourse(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 0, 'Paid' => 0,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }
}

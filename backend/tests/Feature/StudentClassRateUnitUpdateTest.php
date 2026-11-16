<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\SessionDeductionLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class StudentClassRateUnitUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_rate_unit_updates_db_put_response_index_and_hour_charge(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 750,
            'rate_unit' => 'session',
            'SessionCount' => 8,
            'TotalHours' => 16,
            'Charge' => 6000,
        ]);

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 750,
            'rate_unit' => 'hour',
            'sessions_purchased' => 8,
            'payment_type' => 'session',
        ]);

        $response->assertOk()
            ->assertJsonPath('rate_unit', 'hour')
            ->assertJsonPath('Rate', 750);

        $course->refresh();
        $this->assertSame('hour', $course->rate_unit);
        $this->assertSame(12000, (int) $course->Charge);

        $index = $this->authJson('GET', '/api/v1/student-classes?branch_id=1&per_page=10', $token);
        $index->assertOk()
            ->assertJsonPath('data.0.rate_unit', 'hour')
            ->assertJsonPath('data.0.rate_per_30min', 750)
            ->assertJsonPath('data.0.total_hours', 16)
            ->assertJsonPath('data.0.charge', 12000)
            ->assertJsonPath('data.0.effective_charge', 12000);
    }

    public function test_session_rate_unit_keeps_per_session_charge_formula(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 750,
            'rate_unit' => 'hour',
            'SessionCount' => 8,
            'TotalHours' => 16,
            'Charge' => 12000,
        ]);

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'subject' => 'Math',
            'class_type' => 'one_on_one',
            'rate_per_30min' => 750,
            'rate_unit' => 'session',
            'sessions_purchased' => 8,
            'payment_type' => 'session',
        ]);

        $response->assertOk()->assertJsonPath('rate_unit', 'session');
        $this->assertSame(6000, (int) $course->refresh()->Charge);
    }

    public function test_invalid_rate_unit_is_rejected(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse();

        $response = $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'rate_unit' => 'weekly',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['rate_unit']);
        $this->assertSame('session', $course->refresh()->rate_unit);
    }

    /** #5379: changing an unbilled, unused hourly lesson to two hours must price two hours. */
    public function test_hourly_duration_only_edit_updates_quantity_and_fee(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'TotalHours' => 1, 'SessionDuration' => 60, 'Charge' => 1200,
        ]);
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'duration_hours' => 2,
        ])->assertOk()->assertJsonPath('TotalHours', 2)->assertJsonPath('Charge', 2400);
        $this->assertSame(2400, (int) $course->refresh()->Charge);
        $this->authJson('GET', "/api/v1/alerts/tuition-slip/{$course->ID}", $token)
            ->assertOk()->assertJsonPath('charge', 2400)->assertJsonPath('unit_price', 1200)->assertJsonPath('unit_price_unit', 'hour');
        $this->authJson('GET', '/api/v1/student-classes?branch_id=1&per_page=10', $token)
            ->assertOk()->assertJsonPath('data.0.total_hours', 2)->assertJsonPath('data.0.charge', 2400);
    }

    /** #5379 real data shape, with synthetic identity: the schedule duration was already saved. */
    public function test_resaving_hourly_form_reconciles_stale_unbilled_quantity(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'TotalHours' => 1, 'SessionDuration' => 120, 'Charge' => 1200,
        ]);
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'rate_per_30min' => 1200, 'rate_unit' => 'hour', 'sessions_purchased' => 1,
        ])->assertOk()->assertJsonPath('TotalHours', 2)->assertJsonPath('Charge', 2400);
    }

    public function test_hourly_count_edit_prices_the_new_number_of_two_hour_lessons(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'TotalHours' => 2, 'SessionDuration' => 120, 'Charge' => 2400,
        ]);
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'sessions_purchased' => 2,
        ])->assertOk()->assertJsonPath('TotalHours', 4)->assertJsonPath('Charge', 4800);
    }

    public function test_hourly_quantity_uses_the_actual_mixed_duration_plan(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'StartDate' => '2030-01-07',
            'SessionCount' => 3, 'RemainingSessions' => 3, 'TotalHours' => 3, 'SessionDuration' => 60, 'Charge' => 3600,
        ]);
        // Monday 1h, Wednesday 2h, Monday 1h: 4h, not 3 × the first duration.
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, [
            'day_time_slots' => [
                ['day' => 1, 'start_time' => '20:00', 'duration_minutes' => 60],
                ['day' => 3, 'start_time' => '20:00', 'duration_minutes' => 120],
            ],
        ])->assertOk()->assertJsonPath('TotalHours', 4)->assertJsonPath('Charge', 4800);
    }

    public static function protectedQuantities(): array
    {
        return array_map(fn ($state) => [$state], [
            'legacy_paid', 'stored_usage', 'attended', 'open_invoice', 'void_invoice',
            'line_item_invoice', 'pending_report', 'confirmed_report', 'manual', 'monthly', 'package', 'ledger_only',
        ]);
    }

    #[DataProvider('protectedQuantities')]
    public function test_duration_edit_does_not_reinterpret_an_existing_financial_or_usage_snapshot(string $state): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'TotalHours' => 1, 'SessionDuration' => 60, 'Charge' => 1200,
            'Paid' => $state === 'legacy_paid' ? 1 : 0,
            'UsedSessions' => $state === 'stored_usage' ? 1 : 0,
            'scheduling_policy' => $state === 'manual' ? 'manual_occurrence' : 'auto_recurrence',
            'ScheduleMode' => $state === 'monthly' ? 'date' : 'count',
        ]);
        if ($state === 'package') {
            $package = CoursePackage::create(['student_id' => $course->StudentID, 'campus_id' => 1,
                'name' => 'Synthetic shared package', 'billing_mode' => 'count', 'total_sessions' => 1,
                'remaining_sessions' => 1, 'used_sessions' => 0, 'rate' => 1200, 'rate_unit' => 'session',
                'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true]);
            $course->update(['PackageID' => $package->id]);
        }
        if ($state === 'ledger_only') {
            $sessionId = DB::table('ClassSession')->insertGetId(['StudentClassID' => $course->ID,
                'SessionDate' => '2030-01-07', 'StartTime' => '20:00', 'EndTime' => '21:00', 'Status' => 'scheduled']);
            SessionDeductionLedger::create(['student_class_id' => $course->ID, 'class_session_id' => $sessionId,
                'event_type' => 'deduct', 'source' => 'manual', 'minutes' => 60]);
        }
        if ($state === 'attended') {
            DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => '2026-01-01',
                'StartTime' => '20:00', 'EndTime' => '21:00', 'Status' => 'attended']);
        }
        if (in_array($state, ['open_invoice', 'void_invoice', 'line_item_invoice'], true)) {
            $bill = Invoice::create(['StudentID' => $course->StudentID,
                'StudentClassID' => $state === 'line_item_invoice' ? null : $course->ID,
                'IssueDate' => '2026-01-01', 'TotalAmount' => 1200, 'PaidAmount' => 0,
                'Status' => $state === 'void_invoice' ? 'void' : 'unpaid']);
            if ($state === 'line_item_invoice') {
                InvoiceItem::create(['InvoiceID' => $bill->id, 'StudentClassID' => $course->ID,
                    'Description' => 'Synthetic shared document', 'Amount' => 1200]);
            }
        }
        if (in_array($state, ['pending_report', 'confirmed_report'], true)) {
            PaymentReport::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
                'reported_by_name' => 'Synthetic payer', 'payment_date' => '2026-01-01',
                'payment_method' => 'cash', 'reported_amount' => 1200,
                'status' => $state === 'pending_report' ? 'pending' : 'confirmed',
                'report_token_hash' => hash('sha256', $state), 'token_expires_at' => now()->addDay()]);
        }
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, ['duration_hours' => 2])
            ->assertOk()->assertJsonPath('TotalHours', 1)->assertJsonPath('Charge', 1200);
        $this->assertSame(1, (int) $course->refresh()->TotalHours);
        if (isset($bill)) {
            $this->assertSame(1200, (int) $bill->refresh()->TotalAmount);
        }
    }

    public function test_hourly_quantity_edit_keeps_a_real_per_lesson_adjustment(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1, 'StartDate' => '2030-01-07',
            'RemainingSessions' => 1, 'TotalHours' => 1, 'SessionDuration' => 60, 'Charge' => 1000,
        ]);
        DB::table('ClassSession')->insert(['StudentClassID' => $course->ID, 'SessionDate' => '2030-01-07',
            'StartTime' => '20:00', 'EndTime' => '21:00', 'Status' => 'scheduled', 'session_charge' => 1000]);
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, ['duration_hours' => 2])
            ->assertOk()->assertJsonPath('TotalHours', 2)->assertJsonPath('Charge', 2200);
    }

    public function test_other_campus_cannot_change_the_hour_quantity(): void
    {
        [$token, $course] = $this->makeDirectorAndCourse([
            'Rate' => 1200, 'rate_unit' => 'hour', 'SessionCount' => 1,
            'RemainingSessions' => 1, 'TotalHours' => 1, 'SessionDuration' => 60, 'Charge' => 1200,
        ]);
        $course->student->update(['CampusID' => 2]);
        $this->authJson('PUT', "/api/v1/student-classes/{$course->ID}", $token, ['duration_hours' => 2])->assertForbidden();
        $this->assertSame(1, (int) $course->refresh()->TotalHours);
        $this->assertSame(1200, (int) $course->Charge);
    }

    private function authJson(string $method, string $url, string $token, array $payload = [])
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url, $payload);
    }

    private function makeDirectorAndCourse(array $overrides = []): array
    {
        $director = User::create([
            'LoginName' => 'rate-unit-director-' . uniqid() . '@test.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '09' . random_int(100000000, 999999999),
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $director->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $director->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        $student = Student::create([
            'name' => '計價測試學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = StudentClass::create(array_merge([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-01',
            'TotalHours' => 16,
            'Rate' => 750,
            'rate_unit' => 'session',
            'Charge' => 6000,
            'Pay' => 0,
            'Paid' => 0,
            'SessionCount' => 8,
            'RemainingSessions' => 8,
            'SessionDuration' => 120,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ], $overrides));

        return [$token, $course];
    }
}

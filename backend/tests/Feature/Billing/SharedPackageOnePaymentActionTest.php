<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #3753 (PRD v2 D18): a shared CoursePackage is one payment obligation, so it takes one pending report, not one per member. */
class SharedPackageOnePaymentActionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    /** @return array{0: \App\Models\StudentClass, 1: \App\Models\StudentClass, 2: CoursePackage} */
    private function sharedPackage(): array
    {
        $a = $this->course([], ['Charge' => 0, 'Rate' => 1500]);
        $student = Student::findOrFail($a->StudentID);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => 'Shared', 'billing_mode' => 'session',
            'total_sessions' => 16, 'remaining_sessions' => 16, 'used_sessions' => 0, 'rate' => 1500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $a->update(['PackageID' => $pkg->id]);
        $b = $this->course([], ['Charge' => 0, 'Rate' => 1500, 'SubjectID' => 2, 'PackageID' => $pkg->id], [], $student);

        return [$a->fresh(), $b->fresh(), $pkg];
    }

    private function record(array $headers, int $courseId)
    {
        return $this->withHeaders($headers)->postJson('/api/v1/payment-reports/director-record', [
            'student_class_id' => $courseId, 'payment_date' => '2026-08-20', 'payment_method' => 'cash', 'amount' => 24000,
        ]);
    }

    public function test_second_member_of_a_shared_package_cannot_open_a_second_pending_report(): void
    {
        [$a, $b] = $this->sharedPackage();
        $h = $this->directorHeaders();

        $first = $this->record($h, (int) $a->ID)->assertOk();
        $this->record($h, (int) $b->ID)->assertStatus(422)
            ->assertJsonPath('code', 'pending_report_exists')
            ->assertJsonPath('report_id', $first->json('report_id'));
        $this->assertSame(1, PaymentReport::whereIn('StudentClassID', [$a->ID, $b->ID])->count());
    }

    public function test_ledger_tells_the_panel_which_contracts_share_one_package(): void
    {
        [$a, $b, $pkg] = $this->sharedPackage();
        $courses = collect($this->withHeaders($this->directorHeaders())->getJson('/api/v1/accounting/ledger?student_class_id=' . $a->ID)
            ->assertOk()->json('courses'))->keyBy('id');

        $this->assertSame((int) $pkg->id, $courses[(int) $a->ID]['package_id']);
        $this->assertSame((int) $pkg->id, $courses[(int) $b->ID]['package_id']);
    }

    /** @return array<string, string> */
    private function directorHeaders(): array
    {
        $user = User::where('LoginName', 'pkg-one-action@example.com')->first();
        if (!$user) {
            $user = User::create(['LoginName' => 'pkg-one-action@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345679]);
            UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer $token", 'Accept' => 'application/json'];
    }
}

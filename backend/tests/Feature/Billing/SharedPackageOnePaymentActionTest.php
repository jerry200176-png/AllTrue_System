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

    /** #3892 review: a course attached to a package while this request waits for the row lock still sees the sibling's pending report. */
    public function test_package_membership_is_read_from_the_locked_course(): void
    {
        [$a, , $pkg] = $this->sharedPackage();
        $h = $this->directorHeaders();
        $this->record($h, (int) $a->ID)->assertOk();
        $c = $this->course([], ['Charge' => 0, 'Rate' => 1500, 'SubjectID' => 3], [], Student::findOrFail($a->StudentID));

        // Simulate convertToPackage committing between the first read and lockForUpdate: the DB row moves into the package
        // right after the request hydrates its (now stale) copy of the course.
        $moved = false;
        \App\Models\StudentClass::retrieved(function ($m) use (&$moved, $c, $pkg) {
            if (!$moved && (int) $m->ID === (int) $c->ID) {
                $moved = true;
                \Illuminate\Support\Facades\DB::table('StudentClass')->where('ID', $c->ID)->update(['PackageID' => $pkg->id]);
            }
        });

        $this->record($h, (int) $c->ID)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');
        $this->assertTrue($moved);
    }

    /**
     * #3892 review (lock order): confirm() locks report -> course -> package; a package record must not then lock the
     * sibling's report (package -> report), or the two deadlock. The package lock already serializes member records.
     */
    public function test_package_record_does_not_lock_sibling_reports_after_the_package(): void
    {
        [$a, $b] = $this->sharedPackage();
        $h = $this->directorHeaders();
        $this->record($h, (int) $a->ID)->assertOk();

        $sql = [];
        \Illuminate\Support\Facades\DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        $this->record($h, (int) $b->ID)->assertStatus(422)->assertJsonPath('code', 'pending_report_exists');

        $reportReads = array_values(array_filter($sql, fn ($s) => str_contains($s, 'from `payment_reports`')));
        $this->assertNotEmpty($reportReads);
        foreach ($reportReads as $s) {
            $this->assertStringNotContainsString('for update', $s);
        }
    }

    public function test_ledger_tells_the_panel_which_contracts_share_one_package(): void
    {
        [$a, $b, $pkg] = $this->sharedPackage();
        $courses = collect($this->withHeaders($this->directorHeaders())->getJson('/api/v1/accounting/ledger?student_class_id=' . $a->ID)
            ->assertOk()->json('courses'))->keyBy('id');

        $this->assertSame((int) $pkg->id, $courses[(int) $a->ID]['package_id']);
        $this->assertSame((int) $pkg->id, $courses[(int) $b->ID]['package_id']);
    }

    /**
     * #3892 review P1 (r4254303211): monthly ('date') package members are billed and recorded per member against their own
     * period subtotal, so they are NOT grouped behind one card and one member's pending report does not block another's.
     */
    public function test_monthly_package_members_stay_independently_actionable(): void
    {
        [$a, $b, $pkg] = $this->sharedPackage();
        $pkg->update(['billing_mode' => 'monthly']);
        $a->update(['ScheduleMode' => 'date']);
        $b->update(['ScheduleMode' => 'date']);
        $h = $this->directorHeaders();

        $courses = collect($this->withHeaders($h)->getJson('/api/v1/accounting/ledger?student_class_id=' . $a->ID)
            ->assertOk()->json('courses'))->keyBy('id');
        $this->assertNull($courses[(int) $a->ID]['package_id']);
        $this->assertNull($courses[(int) $b->ID]['package_id']);

        $first = $this->record($h, (int) $a->ID);
        $second = $this->record($h, (int) $b->ID);
        if ($first->status() === 200) {
            $this->assertNotSame('pending_report_exists', $second->json('code'), 'a sibling\'s pending report must not block a monthly member');
        }
        $this->assertNotSame('pending_report_exists', $second->json('code'));
    }

    /**
     * #3892 review P1 (r4254384774): confirming one monthly member settles that member only. It must not cascade Paid=1 to
     * the package and its siblings, or the sibling's pending report can no longer be confirmed (Founder Q3=A).
     */
    public function test_confirming_one_monthly_member_does_not_mark_the_package_or_sibling_paid(): void
    {
        [$a, $b, $pkg, $reports] = $this->monthlyPackageWithPendingReports();
        $h = $this->directorHeaders();

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reports[0]->id}/confirm")->assertOk();
        $this->assertSame(1, (int) $a->fresh()->Paid);
        $this->assertSame(0, (int) $b->fresh()->Paid, 'a monthly sibling stays unpaid');
        $this->assertFalse((bool) $pkg->fresh()->paid, 'a monthly package is not settled by one member');

        // #3892 review P1 (r4254422195): the package alert row reads the member ledgers, so after A only B's amount is owed.
        $row = $this->packageAlertRow($h, $pkg);
        $this->assertFalse($row['paid']);
        $this->assertSame(8800, (int) $row['outstanding'], 'only B is still owed');

        $this->withHeaders($h)->putJson("/api/v1/payment-reports/{$reports[1]->id}/confirm")->assertOk();
        $this->assertSame('confirmed', $reports[1]->fresh()->status);
        $this->assertSame(1, (int) $b->fresh()->Paid);

        $row = $this->packageAlertRow($h, $pkg);
        $this->assertTrue($row['paid'], 'every member confirmed: the package row is paid');
        $this->assertSame(0, (int) $row['outstanding']);
        $this->assertSame('monthly_due_soon', $row['payment_status']);
        $this->assertFalse((bool) $pkg->fresh()->paid, 'the package flag itself is still never set by member confirmations');
    }

    /** #3892 review P1: the manual package paid toggle (FR-006) still settles the package row even with open member invoices. */
    public function test_manual_paid_toggle_still_settles_a_monthly_package_row(): void
    {
        [, , $pkg] = $this->monthlyPackageWithPendingReports();
        $h = $this->directorHeaders();
        $this->assertFalse($this->packageAlertRow($h, $pkg)['paid']);

        $this->withHeaders($h)->putJson("/api/v1/course-packages/{$pkg->id}", ['paid' => true, 'paid_at' => '2026-09-01'])->assertOk();

        $row = $this->packageAlertRow($h, $pkg);
        $this->assertTrue($row['paid']);
        $this->assertSame(0, (int) $row['outstanding']);
    }

    /**
     * Monthly ('date') package with two members, each with an unpaid 2026-08 invoice of 8800 and a pending report.
     * Today is 2026-09-02, three days before the package's settlement day, so its alert row is always listed.
     *
     * @return array{0: \App\Models\StudentClass, 1: \App\Models\StudentClass, 2: CoursePackage, 3: list<PaymentReport>}
     */
    private function monthlyPackageWithPendingReports(): array
    {
        \Illuminate\Support\Carbon::setTestNow('2026-09-02 10:00:00');
        [$a, $b, $pkg] = $this->sharedPackage();
        $pkg->update(['billing_mode' => CoursePackage::BILLING_MODE_MONTHLY, 'settlement_day' => 5]);
        $student = Student::findOrFail($a->StudentID);
        $reports = [];
        foreach ([$a, $b] as $c) {
            $c->update(['ScheduleMode' => 'date', 'Charge' => 8800, 'StartDate' => '2026-07-01', 'EndDate' => '2026-08-31', 'SessionCount' => 0, 'settlement_day' => 5]);
            $inv = $this->invoice($c, $student, '2026-08', 8800, 'unpaid', 0, []);
            $reports[] = PaymentReport::create(['StudentID' => $student->id, 'StudentClassID' => $c->ID, 'InvoiceID' => $inv->id,
                'reported_by_name' => 'x', 'payment_date' => '2026-08-20', 'payment_method' => 'cash', 'reported_amount' => 8800,
                'status' => 'pending', 'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => now()->addDay()]);
        }

        return [$a->fresh(), $b->fresh(), $pkg->fresh(), $reports];
    }

    /**
     * @param array<string, string> $h
     * @return array<string, mixed>
     */
    private function packageAlertRow(array $h, CoursePackage $pkg): array
    {
        $row = collect($this->withHeaders($h)->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk()->json())
            ->firstWhere('package_id', (int) $pkg->id);
        $this->assertIsArray($row, 'the monthly package has its alert row');

        return $row;
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

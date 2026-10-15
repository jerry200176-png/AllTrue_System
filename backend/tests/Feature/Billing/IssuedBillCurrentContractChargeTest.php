<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * In-app #408 (#4115) / #3980 root cause B. Founder Q2 = A: an issued bill does not silently follow a rate change.
 * The ledger adds the contract's current Charge (read-only) per count-mode course and whether a bill holds only that
 * course's lines; the bill amount is unchanged.
 */
class IssuedBillCurrentContractChargeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bill_without_issue_time_evidence_keeps_its_amount_and_is_not_compared(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student(), ['Rate' => 900, 'SessionCount' => 8, 'Charge' => 7200]);
        $invoiceId = $this->unpaidInvoice($course, 8800);

        $ledger = $this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)->assertOk();

        $row = collect($ledger->json('courses'))->firstWhere('id', (int) $course->ID);
        $this->assertSame(7200, $row['current_contract_charge']);
        $bill = collect($ledger->json('invoices'))->firstWhere('id', $invoiceId);
        $this->assertSame(8800, $bill['total_amount']);
        $this->assertTrue($bill['single_course_items']);
        $this->assertFalse($bill['priced_from_contract']); // a row without issue-time evidence is never compared
        $this->assertSame(8800, (int) DB::table('Invoice')->where('id', $invoiceId)->value('TotalAmount'));
    }

    public function test_the_value_is_the_stored_charge_not_rate_times_count(): void
    {
        $token = $this->directorToken();
        // A discounted renewal keeps Rate but stores the discounted final amount as Charge.
        $course = $this->course($this->student(), ['Rate' => 1000, 'SessionCount' => 8, 'Charge' => 7000]);

        $row = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)
            ->assertOk()->json('courses'))->firstWhere('id', (int) $course->ID);

        $this->assertSame(7000, $row['current_contract_charge']);
    }

    public function test_monthly_and_package_member_contracts_have_no_value(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $monthly = $this->course($student, ['ScheduleMode' => 'date']);
        $package = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => 'pkg', 'total_sessions' => 8,
            'remaining_sessions' => 8, 'used_sessions' => 0, 'rate' => 900, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $member = $this->course($student, ['PackageID' => $package->id]);

        $rows = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_id=' . $student->id)
            ->assertOk()->json('courses'))->keyBy('id');

        $this->assertNull($rows[(int) $monthly->ID]['current_contract_charge']);
        $this->assertNull($rows[(int) $member->ID]['current_contract_charge']);
    }

    public function test_a_bill_with_another_courses_line_is_not_single_course(): void
    {
        $token = $this->directorToken();
        $student = $this->student();
        $course = $this->course($student);
        $other = $this->course($student);
        $invoiceId = $this->unpaidInvoice($course, 8800);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $invoiceId, 'StudentClassID' => $other->ID, 'Description' => 'other', 'Amount' => 1600]);

        $bill = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)
            ->assertOk()->json('invoices'))->firstWhere('id', $invoiceId);

        $this->assertFalse($bill['single_course_items']);
    }

    /** D3: a bill issued through the billing API at the contract Charge, then the Charge changes: the only compared case. */
    public function test_api_bill_priced_at_the_contract_charge_is_compared_after_the_charge_changes(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student(), ['Charge' => 7200]);
        $id = $this->issueViaApi($token, $course, 7200, true);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['Rate' => 800, 'Charge' => 6400]);

        $ledger = $this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)->assertOk();

        $this->assertTrue(collect($ledger->json('invoices'))->firstWhere('id', $id)['priced_from_contract']);
        $this->assertSame(6400, collect($ledger->json('courses'))->firstWhere('id', (int) $course->ID)['current_contract_charge']);
        $this->assertSame(7200, (int) DB::table('Invoice')->where('id', $id)->value('TotalAmount'));
        $this->assertSame(7200, (int) DB::table('InvoiceItem')->where('InvoiceID', $id)->value('Amount'));
    }

    /**
     * Review regression (#4128): a manual single-course bill through POST /invoices (Charge 7200, typed 6800, no price
     * edit), with the item's course given and with it omitted (the issuer fills it). Never compared, amounts unchanged.
     */
    public function test_manual_api_bill_below_the_charge_is_not_compared_with_or_without_the_item_course(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student(), ['Charge' => 7200]);
        $withCourse = $this->issueViaApi($token, $course, 6800, true);
        $withoutCourse = $this->issueViaApi($token, $course, 6800, false);

        $bills = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)
            ->assertOk()->json('invoices'))->keyBy('id');

        foreach ([$withCourse, $withoutCourse] as $id) {
            $this->assertTrue($bills[$id]['single_course_items'], "invoice {$id}");
            $this->assertFalse($bills[$id]['priced_from_contract'], "invoice {$id}");
            $this->assertSame(6800, (int) DB::table('Invoice')->where('id', $id)->value('TotalAmount'));
            $this->assertSame(6800, (int) DB::table('InvoiceItem')->where('InvoiceID', $id)->value('Amount'));
            $this->assertSame((int) $course->ID, (int) DB::table('InvoiceItem')->where('InvoiceID', $id)->value('StudentClassID'));
        }
    }

    /** D3: an instalment, a multi-line bill or a typed total without its line is never compared either. */
    public function test_instalment_and_composed_api_bills_are_not_compared(): void
    {
        $token = $this->directorToken();
        $course = $this->course($this->student(), ['Charge' => 7200]);
        $instalment = $this->issueViaApi($token, $course, 3600, true);
        $twoLines = (int) $this->withToken($token)->postJson('/api/v1/invoices', ['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 7200, 'Items' => [['Description' => 'tuition', 'Amount' => 6000], ['Description' => 'materials', 'Amount' => 1200]]])
            ->assertCreated()->json('id');
        $noLine = (int) $this->withToken($token)->postJson('/api/v1/invoices', ['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 7200])->assertCreated()->json('id');

        $bills = collect($this->withToken($token)->getJson('/api/v1/accounting/ledger?student_class_id=' . $course->ID)
            ->assertOk()->json('invoices'))->keyBy('id');

        foreach ([$instalment, $twoLines, $noLine] as $id) {
            $this->assertFalse($bills[$id]['priced_from_contract'], "invoice {$id}");
        }
        $this->assertSame(3600, (int) DB::table('Invoice')->where('id', $instalment)->value('TotalAmount'));
    }

    private function issueViaApi(string $token, StudentClass $course, int $amount, bool $itemCourse): int
    {
        $item = ['Description' => 'tuition', 'Amount' => $amount] + ($itemCourse ? ['StudentClassID' => $course->ID] : []);

        return (int) $this->withToken($token)->postJson('/api/v1/invoices', [
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => '2026-09-01', 'TotalAmount' => $amount, 'Items' => [$item],
        ])->assertCreated()->json('id');
    }

    private function directorToken(): string
    {
        $user = User::create(['LoginName' => 'director_' . uniqid() . '@test.com', 'Name' => 'TestDirector', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => Carbon::now()->addDay()]);

        return $raw;
    }

    private function student(): Student
    {
        return Student::create(['name' => 'Student ' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0900000000', 'MDT' => now(), 'Notify_Token' => '']);
    }

    /** @param array<string, mixed> $over */
    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'EndDate' => '2026-12-31', 'TotalHours' => 20, 'Charge' => 7200, 'Paid' => 0,
            'Rate' => 900, 'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 8, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ], $over));
    }

    private function unpaidInvoice(StudentClass $course, int $amount): int
    {
        $id = (int) DB::table('Invoice')->insertGetId([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'billing_period' => '2026-09',
            'IssueDate' => '2026-09-01', 'DueDate' => '2026-09-10', 'TotalAmount' => $amount, 'PaidAmount' => 0, 'Status' => 'unpaid',
        ]);
        DB::table('InvoiceItem')->insert(['InvoiceID' => $id, 'StudentClassID' => $course->ID, 'Description' => 'tuition', 'Amount' => $amount]);

        return $id;
    }
}

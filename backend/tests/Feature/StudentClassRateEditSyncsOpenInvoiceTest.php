<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * In-app #408 (GitHub #4115): a count-mode course re-priced from 1100 to 900 per lesson
 * kept its unpaid bill at 8 x 1100 = 8800, so the 繳費通知 asked for the old total.
 * A bill nobody has acted on must follow the new Charge; any bill with money, a pending
 * parent report or a different amount keeps its stored total.
 */
class StudentClassRateEditSyncsOpenInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_untouched_unpaid_bill_follows_the_new_rate(): void
    {
        [$token, $sc, $invoice] = $this->billedCourse();

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(7200, (int) $sc->fresh()->Charge);
        $this->assertSame(7200, (int) $invoice->fresh()->TotalAmount);
        $this->assertSame(7200, (int) InvoiceItem::where('InvoiceID', $invoice->id)->value('Amount'));
    }

    public function test_bill_whose_payment_was_voided_follows_the_new_rate(): void
    {
        [$token, $sc, $invoice] = $this->billedCourse();
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 8800, 'PaidAt' => now(), 'Method' => 'cash']);
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => -8800, 'PaidAt' => now(), 'Method' => 'void']);

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(7200, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_bill_with_an_applied_payment_keeps_its_amount(): void
    {
        [$token, $sc, $invoice] = $this->billedCourse();
        Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 3000, 'PaidAt' => now(), 'Method' => 'cash']);
        $invoice->update(['PaidAmount' => 3000, 'Status' => 'partial']);

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(7200, (int) $sc->fresh()->Charge);
        $this->assertSame(8800, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_bill_with_a_pending_parent_report_keeps_its_amount(): void
    {
        [$token, $sc, $invoice, $student] = $this->billedCourse();
        PaymentReport::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'InvoiceID' => $invoice->id,
            'reported_by_name' => $student->name,
            'payment_date' => now()->toDateString(),
            'reported_amount' => 8800,
            'status' => 'pending',
            'report_token_hash' => hash('sha256', 'inapp-408-' . uniqid()),
            'token_expires_at' => now()->addDay(),
        ]);

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(8800, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_course_marked_paid_by_hand_keeps_its_bill(): void
    {
        [$token, $sc, $invoice] = $this->billedCourse();
        $sc->update(['Paid' => 1]);

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(8800, (int) $invoice->fresh()->TotalAmount);
    }

    public function test_bill_that_never_matched_the_old_charge_keeps_its_amount(): void
    {
        [$token, $sc, $invoice] = $this->billedCourse(4400);

        $this->rerate($token, $sc, 900)->assertOk();

        $this->assertSame(4400, (int) $invoice->fresh()->TotalAmount);
    }

    /** @return array{0:string,1:StudentClass,2:Invoice,3:Student} */
    private function billedCourse(int $invoiceTotal = 8800): array
    {
        $token = $this->createDirectorToken([1]);
        $student = $this->createStudent();
        $sc = $this->createStudentClass($student->id, ['Rate' => 1100, 'rate_unit' => 'session', 'SessionCount' => 8, 'Charge' => 8800]);
        $invoice = Invoice::create([
            'StudentID' => $student->id,
            'StudentClassID' => $sc->ID,
            'IssueDate' => now()->toDateString(),
            'TotalAmount' => $invoiceTotal,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
        ]);
        InvoiceItem::create(['InvoiceID' => $invoice->id, 'StudentClassID' => $sc->ID, 'Description' => '數學', 'Amount' => $invoiceTotal]);

        return [$token, $sc, $invoice, $student];
    }

    private function rerate(string $token, StudentClass $sc, int $rate)
    {
        return $this->putJson(
            "/api/v1/student-classes/{$sc->ID}",
            ['rate_per_30min' => $rate, 'sessions_purchased' => 8],
            ['Authorization' => "Bearer {$token}"]
        );
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'inapp-408-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function createStudent(): Student
    {
        return Student::create(['name' => '費用測試生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
    }

    private function createStudentClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 16,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 120,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}

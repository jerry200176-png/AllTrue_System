<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F7 S3d: finance counts / outstanding list / bank suggest read the resolver, not Paid / Pay. */
class FinanceResolverCountsTest extends TestCase
{
    use RefreshDatabase;

    private function course(Student $student, array $over = []): StudentClass
    {
        return StudentClass::create($over + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0,
            'Charge' => 5000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 100, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    private function invoice(StudentClass $course, int $total, int $paid): void
    {
        $invoice = Invoice::create([
            'StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => now()->toDateString(),
            'TotalAmount' => $total, 'PaidAmount' => $paid, 'Status' => $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
        if ($paid > 0) {
            Payment::create(['InvoiceID' => $invoice->id, 'Amount' => $paid, 'PaidAt' => now(), 'Method' => 'cash']);
        }
    }

    public function test_summary_and_outstanding_follow_resolver_status(): void
    {
        [$token, $campus] = $this->seedDirector();
        $s = Student::create(['name' => 'S', 'CampusID' => $campus->id, 'ClassID' => 0, 'SchoolName' => 'T']);
        $this->invoice($this->course($s), 5000, 5000);                       // paid by rows, flag 0
        $this->invoice($this->course($s, ['Paid' => 1]), 5000, 2000);        // partial, flag 1 (stale)
        $this->course($s, ['ClassType' => 'tutoring', 'Charge' => 0]);       // free
        $this->course($s, ['Charge' => 0, 'Rate' => 0]);                     // free (zero fee)
        $this->invoice($this->course($s), 5000, 0);                          // unpaid

        $h = $this->bearer($token);
        $sum = $this->getJson('/api/v1/finance/summary?branch_id=' . $campus->id, $h)->assertOk();
        $this->assertSame(5, $sum->json('total_courses'));
        $this->assertSame(1, $sum->json('paid_courses'));
        $this->assertSame(2, $sum->json('unpaid_courses'));

        $rows = collect($this->getJson('/api/v1/finance/outstanding?branch_id=' . $campus->id, $h)->assertOk()->json());
        $this->assertCount(2, $rows); // partial + unpaid; tutoring / zero-fee / paid are not listed
        $this->assertSame([false, false], $rows->pluck('paid')->all());
    }

    public function test_bank_suggest_matches_open_invoice_outstanding(): void
    {
        [$token, $campus] = $this->seedDirector();
        $s = Student::create(['name' => 'Match', 'CampusID' => $campus->id, 'ClassID' => 0, 'SchoolName' => 'T']);
        $partial = $this->course($s);
        $this->invoice($partial, 5000, 2000);                                // owes 3000
        $this->course($s, ['Pay' => 3000, 'Paid' => 1, 'PayDate' => now()->toDateString()]); // legacy-only, must not match
        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => now()->toDateString(),
            'amount' => 3000, 'reference' => 'r1', 'status' => 'unmatched',
        ]);

        $r = $this->getJson("/api/v1/bank-reconciliation/{$txn->id}/suggest", $this->bearer($token))->assertOk();
        $this->assertCount(1, $r->json('suggestions'));
        $this->assertSame($partial->ID, $r->json('suggestions.0.student_class_id'));
        $this->assertSame(3000, $r->json('suggestions.0.amount'));
        $this->assertSame('high', $r->json('suggestions.0.confidence'));
    }

    public function test_bank_suggest_is_campus_scoped(): void
    {
        [$token, $campus] = $this->seedDirector();
        $other = CampusFactory::new()->create();
        $foreign = Student::create(['name' => 'Other', 'CampusID' => $other->id, 'ClassID' => 0, 'SchoolName' => 'T']);
        $this->invoice($this->course($foreign), 3000, 0);                    // same amount, other campus
        $mine = BankTransaction::create(['campus_id' => $campus->id, 'transaction_date' => now()->toDateString(),
            'amount' => 3000, 'reference' => 'r2', 'status' => 'unmatched']);
        $theirs = BankTransaction::create(['campus_id' => $other->id, 'transaction_date' => now()->toDateString(),
            'amount' => 3000, 'reference' => 'r3', 'status' => 'unmatched']);

        $this->getJson("/api/v1/bank-reconciliation/{$mine->id}/suggest", $this->bearer($token))
            ->assertOk()->assertJsonCount(0, 'suggestions');
        $this->getJson("/api/v1/bank-reconciliation/{$theirs->id}/suggest", $this->bearer($token))->assertForbidden();
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_fin_' . uniqid() . '@example.com', 'Name' => 'Director',
            'PSW' => password_hash('s', PASSWORD_DEFAULT), 'type' => 'A', 'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create(['UserID' => $user->id, 'CampusID' => $campus->id, 'Approved' => 1]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);

        return [$tok, $campus];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}

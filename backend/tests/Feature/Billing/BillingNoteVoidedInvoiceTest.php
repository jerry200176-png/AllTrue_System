<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\BillingNote;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #4041 — deleting a course voids its invoice; the invoice's billing notes stay readable (read-only), campus-scoped. */
class BillingNoteVoidedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_of_an_invoice_voided_by_course_delete_stay_readable_and_campus_scoped(): void
    {
        $campus = Campus::factory()->create();
        $student = Student::create(['name' => '刪課生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $deleted = $this->course((int) $student->id);
        $kept = $this->course((int) $student->id);
        $invoice = Invoice::create([
            'StudentID' => $student->id, 'StudentClassID' => $deleted->ID, 'IssueDate' => now()->toDateString(),
            'DueDate' => now()->addDays(7)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        $director = $this->staff($campus->id, 'Director');
        $this->postJson('/api/v1/accounting/notes', ['subject_type' => 'invoice', 'subject_id' => $invoice->id, 'body' => '家長要求先暫停'], $director)
            ->assertCreated();

        $this->deleteJson("/api/v1/student-classes/{$deleted->ID}", [], $director)->assertOk();
        $this->assertNull(StudentClass::query()->find($deleted->ID));
        $this->assertSame('void', (string) $invoice->fresh()->Status);

        // Notes endpoint: still returned, flagged read-only, and no new note can be appended.
        $url = "/api/v1/accounting/notes?subject_type=invoice&subject_id={$invoice->id}";
        $res = $this->getJson($url, $director)->assertOk()
            ->assertJsonPath('read_only', true)
            ->assertJsonPath('read_only_label', BillingNote::VOIDED_INVOICE_LABEL);
        $this->assertContains('家長要求先暫停', array_column($res->json('notes'), 'body'));
        $this->postJson('/api/v1/accounting/notes', ['subject_type' => 'invoice', 'subject_id' => $invoice->id, 'body' => '再加'], $director)
            ->assertNotFound();
        $this->assertSame(1, BillingNote::query()->count());

        // Student ledger: shown in its own read-only section.
        $ledger = $this->getJson("/api/v1/accounting/ledger?student_class_id={$kept->ID}", $director)->assertOk();
        $ledger->assertJsonPath('voided_invoice_notes.0.invoice_id', (int) $invoice->id)
            ->assertJsonPath('voided_invoice_notes.0.read_only', true)
            ->assertJsonPath('voided_invoice_notes.0.label', BillingNote::VOIDED_INVOICE_LABEL)
            // The invoice's own Note (destroy appends its void marker) comes first, read-only, then the appended notes.
            ->assertJsonPath('voided_invoice_notes.0.notes.0.legacy', true)
            ->assertJsonPath('voided_invoice_notes.0.notes.1.body', '家長要求先暫停');
        $this->assertStringContainsString("合約 #{$deleted->ID} 刪除", (string) $ledger->json('voided_invoice_notes.0.notes.0.body'));

        // Another campus's director gets nothing.
        $other = $this->staff(Campus::factory()->create()->id, 'Other');
        $this->getJson($url, $other)->assertForbidden()->assertJsonMissing(['body' => '家長要求先暫停']);
        $this->getJson("/api/v1/accounting/ledger?student_class_id={$kept->ID}", $other)->assertForbidden()
            ->assertJsonMissing(['body' => '家長要求先暫停']);

        // A transferred student: the new campus's director still reads the note; the old campus's director does not.
        $newCampus = Campus::factory()->create();
        $student->forceFill(['CampusID' => $newCampus->id])->save();
        $newDirector = $this->staff($newCampus->id, 'New');
        $this->assertContains('家長要求先暫停', array_column($this->getJson($url, $newDirector)->assertOk()->json('notes'), 'body'));
        $this->getJson("/api/v1/accounting/ledger?student_class_id={$kept->ID}", $newDirector)->assertOk()
            ->assertJsonPath('voided_invoice_notes.0.notes.1.body', '家長要求先暫停');
        $this->getJson($url, $director)->assertForbidden();
    }

    private function course(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0, 'MDate' => now()->subDays(30),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
    }

    /** @return array<string, string> */
    private function staff(int $campusId, string $name): array
    {
        $user = User::create([
            'LoginName' => Str::random(8) . '@notes4041.example.com', 'Name' => $name, 'PSW' => 'secret',
            'type' => 'A', 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}

<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\BillingNote;
use App\Models\Campus;
use App\Models\Invoice;
use App\Models\ParentSession;
use App\Models\Payment;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #3836 — billing-center notes are append-only, director/super-admin only and campus-scoped. */
class BillingNoteTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private Payment $payment;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::factory()->create();
        $this->student = Student::create(['name' => '備註生', 'CampusID' => $this->campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $this->student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->subDays(30), 'TotalHours' => 20, 'Charge' => 5000, 'Paid' => 0, 'MDate' => now()->subDays(30),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10, 'SessionDuration' => 120, 'RemainingSessions' => 10,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        $invoice = Invoice::create([
            'StudentID' => $this->student->id, 'StudentClassID' => $course->ID, 'IssueDate' => now()->toDateString(),
            'DueDate' => now()->addDays(7)->toDateString(), 'TotalAmount' => 5000, 'PaidAmount' => 0, 'Status' => 'unpaid', 'Note' => '',
        ]);
        $this->payment = Payment::create(['InvoiceID' => $invoice->id, 'Amount' => 5000, 'PaidAt' => now(), 'Method' => 'cash', 'Note' => '現金，收據已交給家長']);
    }

    public function test_director_adds_a_note_and_the_legacy_note_comes_first(): void
    {
        $h = $this->staff('A', $this->campus->id, 'Director');
        $this->postJson('/api/v1/accounting/notes', $this->body('家長說下週補差額'), $h)
            ->assertCreated()->assertJsonPath('author_name', 'Director')->assertJsonPath('legacy', false);

        $notes = $this->getJson($this->listUrl(), $h)->assertOk()->json('notes');
        $this->assertCount(2, $notes);
        $this->assertSame(['現金，收據已交給家長', true], [$notes[0]['body'], $notes[0]['legacy']], 'legacy note first, read-only');
        $this->assertSame(['家長說下週補差額', 'Director', false], [$notes[1]['body'], $notes[1]['author_name'], $notes[1]['legacy']]);
        $this->assertSame('現金，收據已交給家長', (string) $this->payment->fresh()->getAttribute('Note'), 'legacy column untouched');
    }

    public function test_input_is_validated_against_the_model_limits(): void
    {
        $h = $this->staff('A', $this->campus->id, 'Director');
        $this->postJson('/api/v1/accounting/notes', $this->body(str_repeat('字', BillingNote::MAX_BODY + 1)), $h)->assertStatus(422);
        $this->postJson('/api/v1/accounting/notes', $this->body('   '), $h)->assertStatus(422);
        $this->postJson('/api/v1/accounting/notes', ['subject_type' => 'teacher', 'subject_id' => 1, 'body' => 'x'], $h)->assertStatus(422);
        $this->postJson('/api/v1/accounting/notes', $this->body(str_repeat('字', BillingNote::MAX_BODY)), $h)->assertCreated();
    }

    public function test_there_is_no_update_or_delete_path(): void
    {
        $h = $this->staff('A', $this->campus->id, 'Director');
        $id = (int) $this->postJson('/api/v1/accounting/notes', $this->body('原文'), $h)->assertCreated()->json('id');
        foreach (['put', 'patch', 'delete'] as $method) {
            foreach (['/api/v1/accounting/notes', "/api/v1/accounting/notes/{$id}"] as $url) {
                $status = $this->json($method, $url, $this->body('改寫'), $h)->status();
                $this->assertContains($status, [404, 405], "{$method} {$url} must not exist");
            }
        }

        $note = BillingNote::query()->findOrFail($id);
        try {
            $note->update(['body' => '改寫']);
            $this->fail('update must throw');
        } catch (\LogicException) {
        }
        try {
            $note->delete();
            $this->fail('delete must throw');
        } catch (\LogicException) {
        }
        $this->assertSame('原文', BillingNote::query()->findOrFail($id)->body);
    }

    public function test_a_director_of_another_campus_is_refused(): void
    {
        $other = Campus::factory()->create();
        $h = $this->staff('A', $other->id, 'Other');
        $this->getJson($this->listUrl(), $h)->assertForbidden();
        $this->postJson('/api/v1/accounting/notes', $this->body('越權'), $h)->assertForbidden();
        $this->assertSame(0, BillingNote::query()->count());
    }

    public function test_teacher_and_parent_are_refused(): void
    {
        $teacher = $this->staff('T', $this->campus->id, 'Teacher');
        $this->getJson($this->listUrl(), $teacher)->assertForbidden();
        $this->postJson('/api/v1/accounting/notes', $this->body('老師'), $teacher)->assertForbidden();

        // A parent session is not a staff session: the role gate refuses it before the controller runs.
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $this->student->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        $parent = ['Authorization' => "Bearer {$raw}", 'Accept' => 'application/json'];
        $this->assertContains($this->getJson($this->listUrl(), $parent)->status(), [401, 403]);
        $this->assertContains($this->postJson('/api/v1/accounting/notes', $this->body('家長'), $parent)->status(), [401, 403]);
        $this->assertSame(0, BillingNote::query()->count());
    }

    /** @return array<string, mixed> */
    private function body(string $text): array
    {
        return ['subject_type' => 'payment', 'subject_id' => $this->payment->id, 'body' => $text];
    }

    private function listUrl(): string
    {
        return "/api/v1/accounting/notes?subject_type=payment&subject_id={$this->payment->id}";
    }

    /** @return array<string, string> */
    private function staff(string $type, int $campusId, string $name): array
    {
        $user = User::create([
            'LoginName' => Str::random(8) . '@notes3836.example.com', 'Name' => $name, 'PSW' => 'secret',
            'type' => $type, 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}

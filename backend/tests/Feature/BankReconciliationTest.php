<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\BankTransaction;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_csv_creates_transactions(): void
    {
        [$token, $campus] = $this->seedDirector();

        $r = $this->postJson('/api/v1/bank-reconciliation/import', [
            'campus_id' => $campus->id,
            'transactions' => [
                ['date' => '2026-05-01', 'amount' => 5000, 'reference' => '12345', 'bank_name' => '台新'],
                ['date' => '2026-05-02', 'amount' => 3000, 'reference' => '67890', 'bank_name' => '台新'],
            ],
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertEquals(2, $r->json('imported'));
        $this->assertEquals(0, $r->json('skipped'));
        $this->assertDatabaseCount('bank_transactions', 2);
    }

    public function test_import_csv_deduplicates(): void
    {
        [$token, $campus] = $this->seedDirector();

        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => '12345', 'status' => 'unmatched',
        ]);

        $r = $this->postJson('/api/v1/bank-reconciliation/import', [
            'campus_id' => $campus->id,
            'transactions' => [
                ['date' => '2026-05-01', 'amount' => 5000, 'reference' => '12345'],
            ],
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertEquals(0, $r->json('imported'));
        $this->assertEquals(1, $r->json('skipped'));
    }

    public function test_index_returns_transactions_with_summary(): void
    {
        [$token, $campus] = $this->seedDirector();

        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'A', 'status' => 'unmatched',
        ]);
        BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-02',
            'amount' => 3000, 'reference' => 'B', 'status' => 'reconciled',
            'reconciled_at' => now(), 'reconciled_by' => 1,
        ]);

        $r = $this->getJson('/api/v1/bank-reconciliation?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertCount(2, $r->json('transactions'));
        $this->assertEquals(1, $r->json('summary.unmatched'));
        $this->assertEquals(1, $r->json('summary.reconciled'));
    }

    public function test_reconcile_marks_transaction(): void
    {
        [$token, $campus] = $this->seedDirector();
        $course = $this->seedCourse((int) $campus->id);

        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'X', 'status' => 'unmatched',
        ]);

        $r = $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", [
            'student_class_id' => $course->ID,
        ], $this->bearer($token));

        $r->assertOk();
        $this->assertDatabaseHas('bank_transactions', [
            'id' => $txn->id,
            'status' => 'reconciled',
            'matched_payment_id' => $course->ID,
        ]);
    }

    public function test_reconcile_refuses_a_course_that_does_not_exist(): void
    {
        [$token, $campus] = $this->seedDirector();
        $txn = $this->seedTransaction((int) $campus->id, 'NX1');
        $missingId = (int) StudentClass::query()->max('ID') + 1000;

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => $missingId], $this->bearer($token))
            ->assertStatus(422)->assertJsonPath('code', 'course_not_found');
        $this->assertNothingWritten($txn);
    }

    public function test_reconcile_refuses_a_course_at_another_campus(): void
    {
        [$token, $campus] = $this->seedDirector();
        $other = CampusFactory::new()->create();
        $foreignCourse = $this->seedCourse((int) $other->id);
        $txn = $this->seedTransaction((int) $campus->id, 'XC1');

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => $foreignCourse->ID], $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'course_campus_mismatch');
        $this->assertNothingWritten($txn);
    }

    public function test_a_director_with_no_campus_cannot_reconcile(): void
    {
        $campus = CampusFactory::new()->create();
        $course = $this->seedCourse((int) $campus->id);
        $txn = $this->seedTransaction((int) $campus->id, 'NC1');
        $token = $this->seedUser('A', null);

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => $course->ID], $this->bearer($token))
            ->assertForbidden();
        $this->assertNothingWritten($txn);
    }

    public function test_super_admin_reconciles_only_a_course_at_the_transaction_campus(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $course = $this->seedCourse((int) $campus->id);
        $foreignCourse = $this->seedCourse((int) $other->id);
        $txn = $this->seedTransaction((int) $campus->id, 'SA1');
        $token = $this->seedUser('S', null);

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => $foreignCourse->ID], $this->bearer($token))
            ->assertForbidden()->assertJsonPath('code', 'course_campus_mismatch');
        $this->assertNothingWritten($txn);

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => $course->ID], $this->bearer($token))
            ->assertOk();
        $this->assertDatabaseHas('bank_transactions', [
            'id' => $txn->id, 'status' => 'reconciled', 'matched_payment_id' => $course->ID,
        ]);
    }

    public function test_cannot_reconcile_twice(): void
    {
        [$token, $campus] = $this->seedDirector();

        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'Y', 'status' => 'reconciled',
            'reconciled_at' => now(), 'reconciled_by' => 1, 'matched_payment_id' => 1,
        ]);

        $r = $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", [
            'student_class_id' => 2,
        ], $this->bearer($token));

        $r->assertStatus(422);
    }

    public function test_a_director_of_another_campus_cannot_import_list_or_reconcile(): void
    {
        [, $campus] = $this->seedDirector();
        [$foreignToken] = $this->seedDirector();
        $txn = BankTransaction::create([
            'campus_id' => $campus->id, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => 'X1', 'status' => 'unmatched',
        ]);

        $this->postJson("/api/v1/bank-reconciliation/{$txn->id}/reconcile", ['student_class_id' => 1], $this->bearer($foreignToken))->assertForbidden();
        $this->assertSame('unmatched', (string) $txn->fresh()->status);
        $this->postJson('/api/v1/bank-reconciliation/import', [
            'campus_id' => $campus->id, 'transactions' => [['date' => '2026-05-02', 'amount' => 100]],
        ], $this->bearer($foreignToken))->assertForbidden();
        $this->assertSame(1, BankTransaction::where('campus_id', $campus->id)->count());
        $this->getJson('/api/v1/bank-reconciliation?branch_id=' . $campus->id, $this->bearer($foreignToken))->assertForbidden();
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();

        return [$this->seedUser('A', (int) $campus->id), $campus];
    }

    private function seedUser(string $type, ?int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'bank_' . $type . '_' . uniqid() . '@test.com',
            'Name' => 'U', 'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => $type, 'phone' => '0900000000',
        ]);
        if ($campusId !== null) {
            \App\Models\UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Approved' => 1]);
        }
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return $tok;
    }

    private function seedCourse(int $campusId): StudentClass
    {
        $student = StudentFactory::new()->create(['CampusID' => $campusId]);

        return StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0,
            'Charge' => 5000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 100, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
    }

    private function seedTransaction(int $campusId, string $reference): BankTransaction
    {
        return BankTransaction::create([
            'campus_id' => $campusId, 'transaction_date' => '2026-05-01',
            'amount' => 5000, 'reference' => $reference, 'status' => 'unmatched',
        ]);
    }

    private function assertNothingWritten(BankTransaction $txn): void
    {
        $fresh = $txn->fresh();
        $this->assertSame('unmatched', (string) $fresh->status);
        $this->assertNull($fresh->matched_payment_id);
        $this->assertNull($fresh->reconciled_at);
        $this->assertNull($fresh->reconciled_by);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}

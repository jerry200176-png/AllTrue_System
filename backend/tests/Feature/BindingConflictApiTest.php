<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2-2 cross-campus binding conflict API (GET list + resolve).
 */
class BindingConflictApiTest extends TestCase
{
    use RefreshDatabase;

    private function campus(string $name): Campus
    {
        return Campus::factory()->create(['name' => $name]);
    }

    private function student(Campus $campus): Student
    {
        return Student::create([
            'name'     => '衝突測試生',
            'CampusID' => $campus->id,
            'ClassID'  => 1,
            'enable'   => 1,
            'MDT'      => now(),
            'Notify_Token' => '',
            'Phone'    => '',
            'parent_phone' => '0912345678',
        ]);
    }

    private function bind(Student $student, Campus $campus, string $lineUserId): StudentLineBinding
    {
        return StudentLineBinding::create([
            'student_id'  => $student->id,
            'line_user_id' => $lineUserId,
            'campus_id'   => $campus->id,
            'bound_at'    => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
    }

    private function director(Campus $campus): \App\Models\User
    {
        $user = UserFactory::new()->director()->create();
        UserCampusFactory::new()->create([
            'UserID'   => $user->id,
            'CampusID' => $campus->id,
            'Approved' => true,
        ]);

        return $user;
    }

    public function test_director_lists_conflicts_for_own_campus_only(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $student = $this->student($campusA);
        $this->bind($student, $campusA, 'U' . str_repeat('a', 32));
        $this->bind($student, $campusB, 'U' . str_repeat('b', 32));

        $director = $this->director($campusA);
        $res = $this->getJson('/api/v1/bindings/conflicts', ['X-User-Id' => (string) $director->id]);

        $res->assertOk();
        $this->assertCount(1, $res->json('conflicts'));
        $this->assertSame($student->id, $res->json('conflicts.0.student_id'));
        $this->assertCount(2, $res->json('conflicts.0.bindings'));
    }

    public function test_director_does_not_see_conflicts_touching_only_another_campus(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $campusC = $this->campus('C 校');
        $student = $this->student($campusB);
        $this->bind($student, $campusB, 'U' . str_repeat('b', 32));
        $this->bind($student, $campusC, 'U' . str_repeat('c', 32));

        $directorA = $this->director($campusA);
        $res = $this->getJson('/api/v1/bindings/conflicts', ['X-User-Id' => (string) $directorA->id]);

        $res->assertOk();
        $this->assertEmpty($res->json('conflicts'));
    }

    public function test_director_can_delete_own_campus_binding_to_resolve(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $student = $this->student($campusA);
        $bA = $this->bind($student, $campusA, 'U' . str_repeat('a', 32));
        $this->bind($student, $campusB, 'U' . str_repeat('b', 32));

        $director = $this->director($campusA);
        $res = $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action'     => 'delete',
            'binding_id' => $bA->id,
        ], ['X-User-Id' => (string) $director->id]);

        $res->assertOk();
        $this->assertDatabaseMissing('student_line_bindings', ['id' => $bA->id]);
        $this->assertSame('success', DB::table('security_audit_events')
            ->where('event_type', 'line.binding.conflict.resolved')
            ->where('subject_ref', SecurityAuditEvent::ref('student', $student->id))
            ->latest('id')->value('outcome'));
    }

    public function test_director_cannot_delete_other_campus_binding(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $student = $this->student($campusA);
        $this->bind($student, $campusA, 'U' . str_repeat('a', 32));
        $bB = $this->bind($student, $campusB, 'U' . str_repeat('b', 32));

        $director = $this->director($campusA);
        $res = $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action'     => 'delete',
            'binding_id' => $bB->id,
        ], ['X-User-Id' => (string) $director->id]);

        $res->assertStatus(403);
        $this->assertDatabaseHas('student_line_bindings', ['id' => $bB->id]);
    }

    public function test_super_admin_can_keep_one_binding_and_purge_rest(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $student = $this->student($campusA);
        $bA = $this->bind($student, $campusA, 'U' . str_repeat('a', 32));
        $bB = $this->bind($student, $campusB, 'U' . str_repeat('b', 32));

        $super = UserFactory::new()->superAdmin()->create();
        $res = $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action'     => 'keep',
            'binding_id' => $bA->id,
        ], ['X-User-Id' => (string) $super->id]);

        $res->assertOk();
        $this->assertDatabaseHas('student_line_bindings', ['id' => $bA->id]);
        $this->assertDatabaseMissing('student_line_bindings', ['id' => $bB->id]);
    }

    public function test_director_keep_is_rejected(): void
    {
        $campusA = $this->campus('A 校');
        $campusB = $this->campus('B 校');
        $student = $this->student($campusA);
        $bA = $this->bind($student, $campusA, 'U' . str_repeat('a', 32));

        $director = $this->director($campusA);
        $res = $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action'     => 'keep',
            'binding_id' => $bA->id,
        ], ['X-User-Id' => (string) $director->id]);

        $res->assertStatus(422);
    }
}

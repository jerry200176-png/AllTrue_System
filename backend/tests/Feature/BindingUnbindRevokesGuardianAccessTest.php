<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use App\Models\User;
use App\Services\ParentBinding\BindingService;
use App\Services\ParentBinding\ParentGuardianAccessService;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Database\Factories\UserCampusFactory;
use Database\Factories\UserFactory;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #3933: unbinding a LINE account now also revokes guardian access and signs
 * that LINE account out of the parent portal for that student.
 */
class BindingUnbindRevokesGuardianAccessTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = CampusFactory::new()->create();
        $this->director = $this->directorFor($this->campus);
    }

    /** @return array<int, array{0: bool}> */
    public static function flagModes(): array
    {
        return ['multi-guardian on' => [true], 'multi-guardian off' => [false]];
    }

    /** @dataProvider flagModes */
    public function test_unbind_revokes_guardian_link_and_signs_out_that_line_account(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $student = $this->student();
        $line = $this->lineId('a');
        $binding = $this->bindLine($student, $line);
        $token = $this->parentToken($student, $line);

        $this->assertTrue($this->access()->lineMayAccessStudent($line, (int) $student->id));
        $this->dashboard($token)->assertOk();

        $this->unbind($binding)->assertOk()->assertJsonPath('status', 'deleted');

        $this->assertDatabaseMissing('student_line_bindings', ['id' => $binding->id]);
        $this->assertSame(StudentGuardian::STATUS_REVOKED, $this->linkFor($student, $line)->status);
        $this->assertFalse($this->access()->lineMayAccessStudent($line, (int) $student->id));
        $this->dashboard($token)->assertUnauthorized();
    }

    /** @dataProvider flagModes */
    public function test_other_guardian_of_same_student_keeps_access(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $student = $this->student();
        $replaced = $this->lineId('b');
        $other = $this->lineId('c');
        $binding = $this->bindLine($student, $replaced);
        $this->bindLine($student, $other);
        $otherToken = $this->parentToken($student, $other);

        $this->unbind($binding)->assertOk();

        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $other)->status);
        $this->assertTrue($this->access()->lineMayAccessStudent($other, (int) $student->id));
        $this->dashboard($otherToken)->assertOk();
    }

    /** @dataProvider flagModes */
    public function test_same_line_account_keeps_access_to_its_other_student(bool $flag): void
    {
        config(['perfflags.multi_guardian_enabled' => $flag]);
        $line = $this->lineId('d');
        $child = $this->student();
        $sibling = $this->student();
        $binding = $this->bindLine($child, $line);
        $this->bindLine($sibling, $line);
        $siblingToken = $this->parentToken($sibling, $line);

        $this->unbind($binding)->assertOk();

        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($sibling, $line)->status);
        $this->assertTrue($this->access()->lineMayAccessStudent($line, (int) $sibling->id));
        $this->assertFalse($this->access()->lineMayAccessStudent($line, (int) $child->id));
        $this->dashboard($siblingToken)->assertOk();
    }

    public function test_double_unbind_is_a_no_op(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $line = $this->lineId('e');
        $other = $this->lineId('f');
        $binding = $this->bindLine($student, $line);
        $this->bindLine($student, $other);
        $otherToken = $this->parentToken($student, $other);

        $this->unbind($binding)->assertOk();
        $revokedAt = $this->linkFor($student, $line)->revoked_at;

        $this->unbind($binding)->assertNotFound();

        $this->assertEquals($revokedAt, $this->linkFor($student, $line)->revoked_at);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $other)->status);
        $this->dashboard($otherToken)->assertOk();
    }

    /** #3954: a stale binding id whose pair was deleted and re-bound revokes nothing of the new binding. */
    public function test_a_stale_binding_id_does_not_revoke_the_re_bound_pair(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $line = $this->lineId('g');
        $stale = $this->bindLine($student, $line);
        // Another request deleted the binding and the same pair was bound again.
        DB::table('student_line_bindings')->where('id', $stale->id)->delete();
        $rebound = $this->bindLine($student, $line);
        $token = $this->parentToken($student, $line);

        $this->assertFalse(app(BindingService::class)->deleteBinding((int) $stale->id));

        $this->assertDatabaseHas('student_line_bindings', ['id' => $rebound->id]);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $line)->status);
        $this->dashboard($token)->assertOk();
    }

    /** #3954: the binding is read inside the revoking transaction, so no window is left between the read and the revoke. */
    public function test_unbind_reads_the_binding_inside_the_revoking_transaction(): void
    {
        $binding = $this->bindLine($this->student(), $this->lineId('h'));
        $outer = DB::transactionLevel(); // RefreshDatabase wraps the test in a transaction
        $levels = [];
        DB::listen(function (QueryExecuted $q) use (&$levels): void {
            if (preg_match('/^select .*student_line_bindings.* where [`"]?id[`"]? = \?/i', $q->sql)) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->assertTrue(app(BindingService::class)->deleteBinding((int) $binding->id));

        $this->assertNotEmpty($levels, 'deleteBinding reads its binding row');
        $this->assertGreaterThan($outer, min($levels), 'the binding read happens inside the revoking transaction');
    }

    public function test_director_of_another_campus_still_cannot_unbind_and_nothing_is_revoked(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $outsider = $this->directorFor(CampusFactory::new()->create());
        $student = $this->student();
        $line = $this->lineId('g');
        $binding = $this->bindLine($student, $line);
        $token = $this->parentToken($student, $line);

        $this->deleteJson('/api/v1/bindings/' . $binding->id, [], ['X-User-Id' => (string) $outsider->id])
            ->assertForbidden();

        $this->assertDatabaseHas('student_line_bindings', ['id' => $binding->id]);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $line)->status);
        $this->dashboard($token)->assertOk();
    }

    public function test_student_page_unbind_also_revokes_guardian_access(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $line = $this->lineId('h');
        $binding = $this->bindLine($student, $line);
        $token = $this->parentToken($student, $line);

        $this->deleteJson(
            '/api/v1/students/' . $student->id . '/line-bindings/' . $binding->id,
            [],
            ['X-User-Id' => (string) $this->director->id]
        )->assertOk();

        $this->assertSame(StudentGuardian::STATUS_REVOKED, $this->linkFor($student, $line)->status);
        $this->assertFalse($this->access()->lineMayAccessStudent($line, (int) $student->id));
        $this->dashboard($token)->assertUnauthorized();
    }

    public function test_unbind_log_counts_the_sessions_it_signed_out(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $line = $this->lineId('i');
        $binding = $this->bindLine($student, $line);
        $this->parentToken($student, $line);
        Log::spy();

        $this->unbind($binding)->assertOk();

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
            return $message === 'parent_binding.deleted' && is_array($context)
                && $context['guardian_links_revoked'] === 1
                && $context['parent_sessions_invalidated'] === 1;
        })->once();
    }

    public function test_conflict_delete_revokes_only_the_removed_line_account(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $removed = $this->lineId('k');
        $kept = $this->lineId('l');
        $binding = $this->bindLine($student, $removed);
        $this->bindLine($student, $kept, CampusFactory::new()->create());
        $removedToken = $this->parentToken($student, $removed);
        $keptToken = $this->parentToken($student, $kept);

        $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action' => 'delete',
            'binding_id' => $binding->id,
        ], ['X-User-Id' => (string) $this->director->id])->assertOk();

        $this->assertSame(StudentGuardian::STATUS_REVOKED, $this->linkFor($student, $removed)->status);
        $this->dashboard($removedToken)->assertUnauthorized();
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $kept)->status);
        $this->dashboard($keptToken)->assertOk();
    }

    public function test_conflict_keep_revokes_the_purged_line_accounts(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student();
        $kept = $this->lineId('m');
        $purged = $this->lineId('n');
        $keep = $this->bindLine($student, $kept);
        $this->bindLine($student, $purged, CampusFactory::new()->create());
        $purgedToken = $this->parentToken($student, $purged);
        $super = UserFactory::new()->superAdmin()->create();

        $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action' => 'keep',
            'binding_id' => $keep->id,
        ], ['X-User-Id' => (string) $super->id])->assertOk();

        $this->assertSame(StudentGuardian::STATUS_REVOKED, $this->linkFor($student, $purged)->status);
        $this->dashboard($purgedToken)->assertUnauthorized();
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $kept)->status);
    }

    public function test_binding_list_unbind_requires_the_students_current_campus(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $newCampus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $newCampus->id, 'enable' => 1]);
        $line = $this->lineId('o');
        $staleBinding = $this->bindLine($student, $line);
        $token = $this->parentToken($student, $line);

        $this->unbind($staleBinding)->assertForbidden();

        $this->assertDatabaseHas('student_line_bindings', ['id' => $staleBinding->id]);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $line)->status);
        $this->dashboard($token)->assertOk();
    }

    public function test_conflict_delete_requires_the_students_current_campus(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $newCampus = CampusFactory::new()->create();
        $student = StudentFactory::new()->create(['CampusID' => $newCampus->id, 'enable' => 1]);
        $line = $this->lineId('p');
        $staleBinding = $this->bindLine($student, $line);
        $this->bindLine($student, $this->lineId('q'), $newCampus);
        $token = $this->parentToken($student, $line);

        $this->postJson("/api/v1/bindings/conflicts/{$student->id}/resolve", [
            'action' => 'delete',
            'binding_id' => $staleBinding->id,
        ], ['X-User-Id' => (string) $this->director->id])->assertForbidden();

        $this->assertDatabaseHas('student_line_bindings', ['id' => $staleBinding->id]);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $this->linkFor($student, $line)->status);
        $this->dashboard($token)->assertOk();
    }

    private function access(): ParentGuardianAccessService
    {
        return app(ParentGuardianAccessService::class);
    }

    private function student(): Student
    {
        return StudentFactory::new()->create(['CampusID' => $this->campus->id, 'enable' => 1]);
    }

    private function lineId(string $char): string
    {
        return 'U' . str_repeat($char, 32);
    }

    /** A verified LINE binding plus the guardian link it produces under dual-write. */
    private function bindLine(Student $student, string $line, ?Campus $campus = null): StudentLineBinding
    {
        $binding = StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $line,
            'campus_id' => ($campus ?? $this->campus)->id,
            'bound_at' => now(),
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
        $guardian = Guardian::query()->firstOrCreate(['line_user_id' => $line], ['display_name' => 'G']);
        StudentGuardian::query()->firstOrCreate(['student_id' => $student->id, 'guardian_id' => $guardian->id], [
            'campus_id' => $this->campus->id,
            'role' => StudentGuardian::ROLE_GUARDIAN,
            'is_primary' => !StudentGuardian::query()->where('student_id', $student->id)->exists(),
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_LINE_BINDING,
            'student_line_binding_id' => $binding->id,
        ]);

        return $binding;
    }

    private function linkFor(Student $student, string $line): StudentGuardian
    {
        $guardian = Guardian::query()->where('line_user_id', $line)->firstOrFail();

        return StudentGuardian::query()
            ->where('student_id', $student->id)
            ->where('guardian_id', $guardian->id)
            ->firstOrFail();
    }

    private function parentToken(Student $student, string $line): string
    {
        $raw = Str::random(48);
        ParentSession::create([
            'StudentID' => $student->id,
            'line_user_id' => $line,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addDays(30),
        ]);

        return $raw;
    }

    private function dashboard(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/parent/dashboard', ['Authorization' => 'Bearer ' . $token]);
    }

    private function unbind(StudentLineBinding $binding): \Illuminate\Testing\TestResponse
    {
        return $this->deleteJson('/api/v1/bindings/' . $binding->id, [], ['X-User-Id' => (string) $this->director->id]);
    }

    private function directorFor(Campus $campus): User
    {
        $director = UserFactory::new()->director()->create();
        UserCampusFactory::new()->create([
            'UserID' => $director->id,
            'CampusID' => $campus->id,
            'Approved' => true,
        ]);

        return $director;
    }
}

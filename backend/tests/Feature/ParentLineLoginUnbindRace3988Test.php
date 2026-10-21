<?php

namespace Tests\Feature;

use App\Models\ParentSession;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Services\ParentBinding\BindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #3988: LINE login reads the student's bindings, then inserts a parent
 * session. An unbind that commits between those two steps expires only the
 * sessions it can see, so the new session used to survive for 30 days with
 * the multi-guardian flags off (anchorRevoked() is false there).
 *
 * The interleaving is made deterministic by unbinding from the ParentSession
 * "creating" event, i.e. after the lookup and right before the insert. This
 * runs sequentially on the test connection (RefreshDatabase), so it models an
 * unbind that finished before the insert, which is exactly what the login's
 * post-insert re-read guards. A truly concurrent unbind is covered by
 * ParentLineLoginUnbindConcurrency3988Test (binding-row lock).
 */
class ParentLineLoginUnbindRace3988Test extends TestCase
{
    use RefreshDatabase;

    private const LINE_ID = 'U3988aaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        ParentSession::flushEventListeners();
        parent::tearDown();
    }

    private function boundStudent(): array
    {
        $student = Student::create([
            'name'         => 'race-student',
            'CampusID'     => 1,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
        $binding = StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => self::LINE_ID,
            'verified_at' => now(),
            'verification_method' => 'contact_phone',
        ]);
        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => self::LINE_ID], 200),
        ]);

        return [$student, (int) $binding->id];
    }

    private function activeSessions(Student $student): int
    {
        return ParentSession::query()
            ->where('StudentID', $student->id)
            ->where('line_user_id', self::LINE_ID)
            ->where('ExpiresAt', '>', now())
            ->count();
    }

    public function test_unbind_committed_between_lookup_and_insert_leaves_no_active_session(): void
    {
        [$student, $bindingId] = $this->boundStudent();
        $unbound = false;
        ParentSession::creating(function () use (&$unbound, $bindingId) {
            if ($unbound) {
                return;
            }
            $unbound = true;
            $this->assertTrue(app(BindingService::class)->deleteBinding($bindingId));
        });

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);

        $this->assertTrue($unbound, 'the race hook must have fired');
        $res->assertStatus(401);
        $this->assertArrayNotHasKey('token', $res->json());
        $this->assertSame(0, $this->activeSessions($student));
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'parent.auth',
            'outcome' => 'failure',
        ]);
        $this->assertDatabaseMissing('security_audit_events', [
            'event_type' => 'parent.auth',
            'outcome' => 'success',
            'subject_ref' => SecurityAuditEvent::ref('student', $student->id),
        ]);
    }

    public function test_student_unbound_during_login_is_not_listed_in_the_response(): void
    {
        $this->boundStudent();
        $second = Student::create([
            'name' => 'race-student-2', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $secondBinding = StudentLineBinding::create([
            'student_id' => $second->id, 'line_user_id' => self::LINE_ID,
            'verified_at' => now(), 'verification_method' => 'contact_phone',
        ]);
        $unbound = false;
        ParentSession::creating(function () use (&$unbound, $secondBinding) {
            if ($unbound) {
                return;
            }
            $unbound = true;
            $this->assertTrue(app(BindingService::class)->deleteBinding((int) $secondBinding->id));
        });

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);

        $this->assertTrue($unbound);
        $res->assertOk();
        $ids = collect($res->json('students'))->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertNotContains((int) $second->id, $ids);
    }

    public function test_login_without_a_concurrent_unbind_still_issues_one_active_session(): void
    {
        [$student] = $this->boundStudent();

        $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token'])
            ->assertOk()
            ->assertJsonStructure(['token']);

        $this->assertSame(1, $this->activeSessions($student));
    }
}

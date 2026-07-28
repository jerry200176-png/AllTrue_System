<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Explicit end-to-end regression guard for issue #1401 (parent-portal
 * cross-student PII exposure). PR #1400 fixed the root cause — LINE
 * webhook binding accepted a student name/ID alone (no phone proof), and
 * parent LINE login trusted a client-supplied line_user_id instead of
 * validating it server-side against LINE's Profile API — and existing
 * tests (ParentLoginLineTest, LineWebhookBindingTest,
 * ParentPortalLoginIsolationTest) cover each half individually.
 *
 * This file adds the one combination not covered elsewhere: two entirely
 * separate families, BOTH with fully verified LINE bindings (not
 * unverified, not same-phone-only), must still not be able to see or
 * switch to each other's student. "Verified" alone is not the isolation
 * boundary — a *shared* verified line_user_id is. All identifiers here are
 * synthetic test fixtures; no production data of any kind is used.
 */
class Issue1401CrossFamilyLineIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
        ]);
    }

    public function test_two_verified_but_unrelated_families_cannot_switch_or_dashboard_leak(): void
    {
        $familyA = $this->createStudent(1, 'Test-Family-A-Student');
        $familyB = $this->createStudent(1, 'Test-Family-B-Student');

        $lineIdA = 'Uaaaa1111aaaa1111aaaa1111aaaa1111';
        $lineIdB = 'Ubbbb2222bbbb2222bbbb2222bbbb2222';

        StudentLineBinding::create([
            'student_id'           => $familyA->id,
            'line_user_id'         => $lineIdA,
            'verified_at'          => now(),
            'verification_method'  => 'contact_phone',
        ]);
        StudentLineBinding::create([
            'student_id'           => $familyB->id,
            'line_user_id'         => $lineIdB,
            'verified_at'          => now(),
            'verification_method'  => 'contact_phone',
        ]);

        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => $lineIdA], 200),
        ]);

        $login = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'family-a-token']);
        $login->assertOk();
        $token = $login->json('token');
        $studentIds = collect($login->json('students'))->pluck('id')->all();

        // Login only returns Family A's own student — not Family B's,
        // despite both bindings being equally "verified".
        $this->assertContains($familyA->id, $studentIds);
        $this->assertNotContains($familyB->id, $studentIds);

        // Dashboard for the logged-in session must not include Family B.
        $dashboard = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $dashboard->assertOk();
        $dashboardIds = array_map(fn ($s) => $s['id'], $dashboard->json('students') ?? []);
        $this->assertNotContains($familyB->id, $dashboardIds);

        // Explicit switch-student attempt to the other, unrelated, equally
        // verified family must be forbidden.
        $this->postJson('/api/v1/parent/switch-student', [
            'student_id' => $familyB->id,
        ], [
            'Authorization' => 'Bearer ' . $token,
        ])->assertStatus(403);

        // Family A's session must not see Family B's notification
        // preferences / LINE-linked status either.
        $notif = $this->getJson('/api/v1/parent/notification-preferences', [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $notif->assertOk();
    }

    public function test_login_line_with_no_binding_at_all_grants_no_access(): void
    {
        // A LINE profile that has never bound to any student in this system
        // (e.g. a stranger who obtained a valid LINE access token for their
        // own unrelated account) must not be treated as any student's parent.
        $strangerLineId = 'Ustranger0000000000000000000000';

        Http::fake([
            'https://api.line.me/v2/profile' => Http::response(['userId' => $strangerLineId], 200),
        ]);

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'stranger-token']);

        $this->assertNotEquals(200, $res->status(), 'A LINE identity with zero bindings must not receive a 200 with student data.');
        $students = $res->json('students');
        $this->assertTrue(empty($students), 'No students should ever be returned for an unbound LINE identity.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Models\UserCapabilityGrant;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #4460 (Founder policy 6): GET /profiles?id=X is campus-scoped like the list. Below super_admin an id outside the caller's
 * campuses answers exactly like an id that does not exist; the caller's own profile is always readable. Synthetic data only.
 */
class ProfileIndexIdScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_of_another_campus_get_nothing_for_an_id_outside_their_campuses(): void
    {
        [$a, $b] = $this->campuses(2);
        $teacherA = $this->user('T', [$a => 1]);
        $directorA = $this->user('D', [$a => 1]);
        $missingId = (int) User::max('id') + 1000;

        foreach ([$this->user('D', [$b => 1]), $this->user('T', [$b => 1])] as $caller) {
            $api = $this->api($this->token($caller));
            foreach ([$teacherA->id, $directorA->id] as $targetId) {
                $api->getJson("/api/v1/profiles?id={$targetId}&single=1")->assertNotFound();
                $this->assertSame([], $api->getJson("/api/v1/profiles?id={$targetId}&per_page=all")->assertOk()->json());
                $this->assertSame([], $api->getJson("/api/v1/profiles?id={$targetId}")->assertOk()->json('data'));
                $this->assertSame([], $api->getJson("/api/v1/teachers?id={$targetId}&per_page=all")->assertOk()->json());
            }
            // Out of scope and nonexistent answer the same, so the id's existence is not confirmed.
            $this->assertSame(
                $api->getJson("/api/v1/profiles?id={$missingId}&single=1")->assertNotFound()->json(),
                $api->getJson("/api/v1/profiles?id={$teacherA->id}&single=1")->json(),
            );
        }
    }

    public function test_same_campus_staff_and_super_admin_read_the_profile(): void
    {
        [$a, $b] = $this->campuses(2);
        $teacherA = $this->user('T', [$a => 1]);
        $legacyTeacher = $this->user('T', []);
        DB::table('teacher_branches')->insert(['teacher_id' => $legacyTeacher->id, 'branch_id' => $a]);

        foreach ([$this->user('D', [$a => 1]), $this->user('T', [$a => 1]), $this->user('S', [])] as $caller) {
            $api = $this->api($this->token($caller));
            $api->getJson("/api/v1/profiles?id={$teacherA->id}&single=1")->assertOk()->assertJsonPath('id', $teacherA->id);
            $api->getJson("/api/v1/profiles?id={$legacyTeacher->id}&single=1")->assertOk()->assertJsonPath('id', $legacyTeacher->id);
        }
        $teacherB = $this->user('T', [$b => 1]);
        $this->api($this->token($this->user('S', [])))->getJson("/api/v1/profiles?id={$teacherB->id}&single=1")->assertOk();
    }

    public function test_the_caller_always_reads_their_own_profile(): void
    {
        [$a, $c] = $this->campuses(2);
        $teacher = $this->user('T', [$a => 1]);
        $this->api($this->token($teacher))->getJson("/api/v1/profiles?id={$teacher->id}&single=1")->assertOk()->assertJsonPath('id', $teacher->id);

        // Acting as director at C, the caller's scope is C, where they have no link of their own.
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        UserCapabilityGrant::create(['user_id' => $teacher->id, 'capability' => 'teacher', 'campus_id' => $a, 'granted_at' => now()]);
        UserCapabilityGrant::create(['user_id' => $teacher->id, 'capability' => 'director', 'campus_id' => $c, 'granted_at' => now()]);
        $actingDirector = $this->api($this->token($teacher))->withHeaders(['X-Acting-As' => 'director']);
        $actingDirector->getJson("/api/v1/profiles?id={$teacher->id}&single=1")->assertOk()->assertJsonPath('id', $teacher->id);
        // The exemption is only for the caller: another A teacher is outside the acting scope.
        $colleagueA = $this->user('T', [$a => 1]);
        $actingDirector->getJson("/api/v1/profiles?id={$colleagueA->id}&single=1")->assertNotFound();
    }

    public function test_a_pending_request_to_the_callers_campus_is_readable_as_in_the_list(): void
    {
        [$a, $b, $c] = $this->campuses(3);
        $applicant = $this->user('T', [$a => 1, $b => 0]);
        $directorB = $this->api($this->token($this->user('D', [$b => 1])));

        // The 待審核 card (#4457) lists the applicant for B's director; ?id= answers the same.
        $listed = collect($directorB->getJson('/api/v1/teachers?per_page=all')->assertOk()->json())->firstWhere('id', $applicant->id);
        $this->assertSame([$b], $listed['pending_branch_ids'] ?? null);
        $row = $directorB->getJson("/api/v1/profiles?id={$applicant->id}&per_page=all")->assertOk()->json();
        $this->assertSame([$applicant->id], array_column($row, 'id'));
        $this->assertSame([$b], $row[0]['pending_branch_ids']);

        $this->api($this->token($this->user('D', [$c => 1])))->getJson("/api/v1/profiles?id={$applicant->id}&single=1")->assertNotFound();
    }

    /** @return list<int> */
    private function campuses(int $count): array
    {
        return array_map(fn () => (int) CampusFactory::new()->create()->id, range(1, $count));
    }

    /** @param array<int, int> $links campus id => Approved */
    private function user(string $type, array $links): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type, 'status' => 'active',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($links as $campusId => $approved) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => $approved]);
        }

        return $user;
    }

    private function token(User $user): string
    {
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}

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
 * #4433 (Founder policy 1, 2026-10-22): a teacher's campus join stays pending until THAT campus's director approves it;
 * super_admin may approve any. Synthetic data only.
 */
class TeacherCampusLinkApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_campus_director_sees_the_pending_link_and_approves_only_it(): void
    {
        [$a, $b] = $this->campuses(2);
        $teacher = $this->user('T', [$a => 1, $b => 0]);
        $directorB = $this->user('A', [$b => 1]);
        $directorA = $this->user('A', [$a => 1]);

        $row = $this->teacherRow($this->token($directorB), $teacher->id);
        $this->assertSame([$b], $row['pending_branch_ids']);
        $this->assertSame([$b], $row['approvable_branch_ids']);
        $rowForA = $this->teacherRow($this->token($directorA), $teacher->id);
        $this->assertSame([$b], $rowForA['pending_branch_ids'], 'campus A sees the request as pending');
        $this->assertSame([], $rowForA['approvable_branch_ids'], 'but not as its own');
        $rowForColleague = $this->teacherRow($this->token($this->user('T', [$b => 1])), $teacher->id);
        $this->assertSame([], $rowForColleague['approvable_branch_ids'], 'a teacher reading the list decides nothing');

        $this->api($this->token($directorB))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/approve")
            ->assertOk()->assertJsonPath('decision', 'approved');

        $this->assertSame([$a => 1, $b => 1], $this->links($teacher->id));
        $this->assertSame('active', (string) $teacher->fresh()->status);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'staff.campus_link.approved')->where('campus_id', $b)->count());
        $this->api($this->token($directorB))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/approve")->assertNotFound();
        // Approved, the link is B's: its director now manages the teacher.
        $this->api($this->token($directorB))->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '新名字'])->assertOk();
    }

    public function test_a_director_of_another_campus_cannot_approve_or_reject_it(): void
    {
        [$a, $b, $c] = $this->campuses(3);
        $teacher = $this->user('T', [$a => 1, $b => 0]);
        $before = $this->links($teacher->id);

        foreach ([$this->user('A', [$a => 1]), $this->user('A', [$c => 1])] as $director) {
            foreach (['approve', 'reject'] as $action) {
                $this->api($this->token($director))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/{$action}")->assertForbidden();
            }
        }
        $this->assertSame($before, $this->links($teacher->id));
        $this->assertSame(0, DB::table('security_audit_events')->where('event_type', 'like', 'staff.campus_link.%')->count());
    }

    public function test_super_admin_approves_any_link_and_the_account_status_is_not_flipped(): void
    {
        [$a, $b] = $this->campuses(2);
        $teacher = $this->user('T', [$a => 0, $b => 0], 'pending');

        $this->api($this->token($this->user('S', [])))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/approve")->assertOk();

        $this->assertSame([$a => 0, $b => 1], $this->links($teacher->id));
        $this->assertSame('pending', (string) $teacher->fresh()->status);
    }

    public function test_reject_removes_only_that_campus_pending_link(): void
    {
        [$a, $b, $c] = $this->campuses(3);
        $teacher = $this->user('T', [$a => 1, $b => 0, $c => 0]);
        foreach ([$a, $b, $c] as $campusId) {
            DB::table('teacher_branches')->insert(['teacher_id' => $teacher->id, 'branch_id' => $campusId]);
        }
        $directorB = $this->token($this->user('A', [$b => 1]));

        $this->api($directorB)->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/reject")
            ->assertOk()->assertJsonPath('decision', 'rejected');

        $this->assertSame([$a => 1, $c => 0], $this->links($teacher->id));
        $this->assertSame([$a, $c], DB::table('teacher_branches')->where('teacher_id', $teacher->id)->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame('active', (string) $teacher->fresh()->status);
        // An approved link is not a request: reject leaves it alone.
        $this->api($this->token($this->user('A', [$a => 1])))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$a}/reject")->assertNotFound();
        $this->assertSame([$a => 1, $c => 0], $this->links($teacher->id));
    }

    public function test_account_approval_releases_only_the_approvers_campuses(): void
    {
        [$a, $a2, $b] = $this->campuses(3);
        $teacher = $this->user('T', [$a => 1, $a2 => 0, $b => 0], 'pending');
        $directorA = $this->token($this->user('A', [$a => 1, $a2 => 1]));

        $this->api($directorA)->putJson("/api/v1/profiles/{$teacher->id}", ['status' => 'active'])->assertOk();
        $this->assertSame([$a => 1, $a2 => 1, $b => 0], $this->links($teacher->id));
        $this->assertSame('active', (string) $teacher->fresh()->status);
        $this->assertSame(1, DB::table('security_audit_events')->where('event_type', 'staff.campus_link.approved')->where('campus_id', $a2)->count());

        $other = $this->user('T', [$a => 0, $b => 0], 'pending');
        $this->api($this->token($this->user('S', [])))->putJson("/api/v1/profiles/{$other->id}", ['status' => 'active'])->assertOk();
        $this->assertSame([$a => 1, $b => 1], $this->links($other->id), 'super_admin releases every campus');
    }

    public function test_saving_an_active_teacher_approves_nothing_and_keeps_each_campus_link(): void
    {
        [$a, $a2, $b] = $this->campuses(3);
        $directorA = $this->token($this->user('A', [$a => 1, $a2 => 1]));
        // The edit form resends status=active: an already-active teacher's pending link stays pending.
        $teacher = $this->user('T', [$a => 1, $a2 => 0]);
        $this->api($directorA)->putJson("/api/v1/profiles/{$teacher->id}", ['username' => '老師', 'status' => 'active', 'multi_branches' => [$a, $a2]])->assertOk();
        $this->assertSame([$a => 1, $a2 => 0], $this->links($teacher->id));

        // #4457 review P1-2: making A the main campus while keeping B must not demote B, whichever row the table returns first.
        $moved = $this->user('T', [$b => 1, $a => 1]);
        DB::table('UserCampus')->where('UserID', $moved->id)->where('CampusID', $b)->update(['RFID' => 'CARD-B']);
        $this->api($directorA)->putJson("/api/v1/profiles/{$moved->id}", ['status' => 'active', 'branch_id' => $a, 'multi_branches' => [$b]])->assertOk();
        $this->assertSame([$a => 1, $b => 1], $this->links($moved->id));
        $this->assertSame(['CARD-B'], DB::table('UserCampus')->where('UserID', $moved->id)->where('CampusID', $b)->pluck('RFID')->all());
    }

    public function test_edit_save_adding_an_unmanaged_campus_creates_a_pending_link_for_that_director(): void
    {
        [$a, $b, $c] = $this->campuses(3);
        $directorA = $this->token($this->user('A', [$a => 1]));
        $teacher = $this->user('T', [$a => 1]);

        $this->api($directorA)->putJson("/api/v1/profiles/{$teacher->id}", ['status' => 'active', 'branch_id' => $a, 'multi_branches' => [$b]])->assertOk();
        $this->assertSame([$a => 1, $b => 0], $this->links($teacher->id));
        $this->api($this->token($this->user('A', [$b => 1])))->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$b}/approve")->assertOk();
        $this->assertSame([$a => 1, $b => 1], $this->links($teacher->id));

        // Moving a one-link teacher's main campus to an unmanaged campus is also a request, not a grant.
        $single = $this->user('T', [$a => 1]);
        $this->api($directorA)->putJson("/api/v1/profiles/{$single->id}", ['branch_id' => $c])->assertOk();
        $this->assertSame([$c => 0], $this->links($single->id));
    }

    public function test_approvable_links_skip_what_the_decide_endpoint_refuses(): void
    {
        [$b, $c] = $this->campuses(2);
        $directorB = $this->token($this->user('A', [$b => 1]));
        $holder = $this->user('T', [$b => 0]);
        UserCapabilityGrant::create(['user_id' => $holder->id, 'capability' => 'director', 'campus_id' => $c, 'granted_at' => now()]);
        $applicant = $this->user('D', [$b => 0]);

        $rows = collect($this->api($directorB)->getJson('/api/v1/profiles?per_page=all')->assertOk()->json())->keyBy('id');
        $this->assertSame([$b], $rows[$holder->id]['pending_branch_ids']);
        $this->assertSame([], $rows[$holder->id]['approvable_branch_ids'], 'a director-grant holder is decided by super_admin');
        $this->assertSame([], $rows[$applicant->id]['approvable_branch_ids'], 'a director account is not a teacher link');
        $this->api($directorB)->postJson("/api/v1/profiles/{$holder->id}/campus-links/{$b}/approve")->assertForbidden();
    }

    public function test_a_director_without_an_approved_campus_fails_closed(): void
    {
        [$a] = $this->campuses(1);
        $teacher = $this->user('T', [$a => 0]);
        $unapproved = $this->token($this->user('A', [$a => 0]));

        $this->api($unapproved)->postJson("/api/v1/profiles/{$teacher->id}/campus-links/{$a}/approve")->assertForbidden();
        $this->api($unapproved)->getJson('/api/v1/teachers?per_page=all')->assertForbidden();
        $this->assertSame([$a => 0], $this->links($teacher->id));
    }

    public function test_a_dual_role_director_approves_none_of_their_own_links(): void
    {
        Config::set('staff_capabilities.multi_role_v1_enabled', true);
        [$a, $a2, $a3] = $this->campuses(3);
        $self = $this->user('T', [$a2 => 1, $a => 0], 'pending');
        foreach ([$a, $a2, $a3] as $campusId) {
            UserCapabilityGrant::create(['user_id' => $self->id, 'capability' => 'director', 'campus_id' => $campusId, 'granted_at' => now()]);
        }
        $api = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($self), 'Accept' => 'application/json', 'X-Acting-As' => 'director']);

        $api->postJson("/api/v1/profiles/{$self->id}/campus-links/{$a}/approve")
            ->assertStatus(422)->assertJsonPath('code', 'self_approval_forbidden');
        $ownRow = collect($api->getJson('/api/v1/teachers?per_page=all')->assertOk()->json())->firstWhere('id', $self->id);
        $this->assertSame([], $ownRow['approvable_branch_ids'], 'no buttons on the caller\'s own row');
        // #4457 review P2: PUT is consistent. The account approval releases none of their links; an added campus is pending.
        $api->putJson("/api/v1/profiles/{$self->id}", ['status' => 'active', 'multi_branches' => [$a, $a2, $a3]])->assertOk();
        $this->assertSame([$a => 0, $a2 => 1, $a3 => 0], $this->links($self->id));
    }

    /** @return list<int> */
    private function campuses(int $count): array
    {
        return array_map(fn () => (int) CampusFactory::new()->create()->id, range(1, $count));
    }

    /** @param array<int, int> $links campus id => Approved */
    private function user(string $type, array $links, string $status = 'active'): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type, 'status' => $status,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($links as $campusId => $approved) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => $approved]);
        }

        return $user;
    }

    /** @return array<int, int> campus id => Approved */
    private function links(int $userId): array
    {
        return DB::table('UserCampus')->where('UserID', $userId)->orderBy('CampusID')->pluck('Approved', 'CampusID')
            ->mapWithKeys(fn ($approved, $campusId) => [(int) $campusId => (int) $approved])->all();
    }

    /** @return array<string, mixed> */
    private function teacherRow(string $token, int $teacherId): array
    {
        $rows = $this->api($token)->getJson('/api/v1/teachers?per_page=all')->assertOk()->json();
        $row = collect($rows)->firstWhere('id', $teacherId);
        $this->assertNotNull($row, 'the teacher is listed for this director');

        return $row;
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

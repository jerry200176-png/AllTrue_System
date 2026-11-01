<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** PUT /profiles/{id} applies the same target rule as reset-password and delete. Synthetic data only. */
class ProfileUpdateTargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_can_update_only_teachers_of_their_campuses(): void
    {
        $campus = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $director = $this->user('A', [$campus->id]);
        $token = $this->token($director);

        $targets = [
            'super admin' => $this->user('S', []),
            'another director' => $this->user('A', [$campus->id]),
            'the director themself' => $director,
            'teacher of another campus' => $this->user('T', [$other->id]),
        ];
        $snapshot = fn (int $id) => [
            DB::table('User')->where('id', $id)->first(['PSW', 'LoginName', 'Name', 'status']),
            DB::table('UserCampus')->where('UserID', $id)->orderBy('CampusID')->get(['CampusID', 'Approved'])->toArray(),
        ];
        foreach ($targets as $label => $target) {
            $before = $snapshot((int) $target->id);
            // Every writable field at once, including campus links and status: none of it may land.
            $this->api($token)->putJson("/api/v1/profiles/{$target->id}", [
                'password' => 'NewPassword123', 'account' => 'changed-' . $target->id . '@example.com', 'name' => 'changed',
                'status' => 'suspended', 'branch_id' => $other->id, 'multi_branches' => [$campus->id, $other->id],
            ])->assertForbidden();
            $this->assertEquals($before, $snapshot((int) $target->id), "{$label}: account and campus links unchanged");
        }
        $this->assertSame(0, DB::table('UserCampus')->where('UserID', $director->id)->where('CampusID', $other->id)->count());

        $teacher = $this->user('T', [$campus->id]);
        $this->api($token)->putJson("/api/v1/profiles/{$teacher->id}", ['name' => '新名字'])->assertOk();
        $this->assertSame('新名字', (string) $teacher->fresh()->Name);
    }

    public function test_super_admin_can_still_update_a_director(): void
    {
        $campus = CampusFactory::new()->create();
        $target = $this->user('A', [$campus->id]);
        $this->api($this->token($this->user('S', [])))->putJson("/api/v1/profiles/{$target->id}", ['name' => '主任新名'])->assertOk();
        $this->assertSame('主任新名', (string) $target->fresh()->Name);
    }

    /** #4433 (Founder 3A): a director's save never touches links at campuses they don't manage. */
    public function test_director_edit_keeps_links_at_unmanaged_campuses(): void
    {
        $mine = CampusFactory::new()->create();
        $mine2 = CampusFactory::new()->create();
        $approvedElsewhere = CampusFactory::new()->create();
        $pendingElsewhere = CampusFactory::new()->create();
        $director = $this->user('A', [$mine->id, $mine2->id]);
        $teacher = $this->user('T', [$mine->id, $mine2->id, $approvedElsewhere->id]);
        UserCampus::create(['UserID' => $teacher->id, 'CampusID' => $pendingElsewhere->id, 'Admin' => 0, 'Approved' => 0]);
        foreach ([$mine, $mine2, $approvedElsewhere] as $c) {
            DB::table('teacher_branches')->insert(['teacher_id' => $teacher->id, 'branch_id' => $c->id]);
        }

        // The form omits both other campuses and one of the director's own.
        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", [
            'branch_id' => $mine->id, 'multi_branches' => [$mine->id],
        ])->assertOk();

        $links = DB::table('UserCampus')->where('UserID', $teacher->id)->pluck('Approved', 'CampusID')->map(fn ($v) => (int) $v)->all();
        $this->assertEqualsCanonicalizing([$mine->id => 1, $approvedElsewhere->id => 1, $pendingElsewhere->id => 0], $links);
        $this->assertEqualsCanonicalizing([$mine->id, $approvedElsewhere->id],
            DB::table('teacher_branches')->where('teacher_id', $teacher->id)->pluck('branch_id')->all());
    }

    public function test_director_changing_main_campus_does_not_move_an_unmanaged_link(): void
    {
        $mine = CampusFactory::new()->create();
        $mine2 = CampusFactory::new()->create();
        $elsewhere = CampusFactory::new()->create();
        $director = $this->user('A', [$mine->id, $mine2->id]);
        $teacher = $this->user('T', [$elsewhere->id, $mine->id]); // the other-campus link is the first row

        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", ['branch_id' => $mine2->id])->assertOk();

        $this->assertEqualsCanonicalizing([$elsewhere->id, $mine2->id],
            DB::table('UserCampus')->where('UserID', $teacher->id)->pluck('CampusID')->all());
    }

    public function test_an_unchanged_save_with_an_unmanaged_main_campus_changes_no_link(): void
    {
        $mine = CampusFactory::new()->create();
        $elsewhere = CampusFactory::new()->create();
        $director = $this->user('A', [$mine->id]);
        $teacher = $this->user('T', [$elsewhere->id, $mine->id]); // main campus first, outside the director's scope

        // Codex P2 on #4760: TeachersList.vue resends branch_id=<main> and multi_branches=[<others>] on an unchanged save.
        foreach ([1, 2] as $save) {
            $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", [
                'branch_id' => $elsewhere->id, 'multi_branches' => [$mine->id],
            ])->assertOk();
        }

        $this->assertEqualsCanonicalizing([$elsewhere->id, $mine->id],
            DB::table('UserCampus')->where('UserID', $teacher->id)->pluck('CampusID')->all());
    }

    /** #4768: choosing an already-linked campus as main makes it the first row, without duplicates. */
    public function test_choosing_an_already_linked_campus_makes_it_main_without_duplicates(): void
    {
        $a = CampusFactory::new()->create();
        $b = CampusFactory::new()->create();
        $director = $this->user('A', [$a->id, $b->id]);
        $teacher = $this->user('T', [$a->id, $b->id]);
        DB::table('UserCampus')->where('UserID', $teacher->id)->where('CampusID', $b->id)->update(['Approved' => 0]);

        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", ['branch_id' => $b->id])
            ->assertOk()->assertJsonPath('branch_id', $b->id);

        $rows = DB::table('UserCampus')->where('UserID', $teacher->id)->get();
        $this->assertSame([$b->id, $a->id], $rows->pluck('CampusID')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([0, 1], $rows->pluck('Approved')->map(fn ($v) => (int) $v)->all());
    }

    public function test_director_cannot_switch_main_campus_onto_an_unmanaged_linked_campus(): void
    {
        $mine = CampusFactory::new()->create();
        $elsewhere = CampusFactory::new()->create();
        $director = $this->user('A', [$mine->id]);
        $teacher = $this->user('T', [$mine->id, $elsewhere->id]);

        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", ['branch_id' => $elsewhere->id])->assertOk();

        $this->assertSame([$mine->id, $elsewhere->id],
            DB::table('UserCampus')->where('UserID', $teacher->id)->pluck('CampusID')->map(fn ($v) => (int) $v)->all());
    }

    public function test_director_with_no_campus_scope_cannot_edit_links(): void
    {
        $campus = CampusFactory::new()->create();
        $director = $this->user('A', []);
        $teacher = $this->user('T', [$campus->id]);

        $this->api($this->token($director))->putJson("/api/v1/profiles/{$teacher->id}", ['multi_branches' => []])->assertForbidden();
        $this->assertSame(1, DB::table('UserCampus')->where('UserID', $teacher->id)->count());
    }

    public function test_super_admin_edit_still_replaces_every_link(): void
    {
        $a = CampusFactory::new()->create();
        $b = CampusFactory::new()->create();
        $teacher = $this->user('T', [$a->id, $b->id]);

        $this->api($this->token($this->user('S', [])))->putJson("/api/v1/profiles/{$teacher->id}", [
            'branch_id' => $a->id, 'multi_branches' => [$a->id],
        ])->assertOk();

        $this->assertSame([$a->id], DB::table('UserCampus')->where('UserID', $teacher->id)->pluck('CampusID')->map(fn ($v) => (int) $v)->all());
    }

    /** @param list<int> $campusIds */
    private function user(string $type, array $campusIds): User
    {
        $user = User::create([
            'LoginName' => strtolower($type) . '-' . uniqid() . '@example.com', 'Name' => 'u', 'type' => $type,
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999),
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => $type === 'T' ? 0 : 1, 'Approved' => 1]);
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

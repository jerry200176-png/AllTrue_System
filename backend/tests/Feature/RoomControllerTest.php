<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Room;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomControllerTest extends TestCase
{
    use RefreshDatabase;

    private function directorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'room-dir-' . uniqid() . '@test.com',
            'Name'      => 'Room Director',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => 912000001,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);
        return $raw;
    }

    public function test_index_returns_rooms_filtered_by_branch(): void
    {
        $campus = Campus::factory()->create();
        $other  = Campus::factory()->create();
        Room::create(['name' => '教室A', 'campus_id' => $campus->id, 'capacity' => 4, 'is_active' => true]);
        Room::create(['name' => '教室B', 'campus_id' => $other->id,  'capacity' => 4, 'is_active' => true]);

        $token = $this->directorToken($campus->id);
        $res = $this->withToken($token)->getJson("/api/v1/rooms?branch_id={$campus->id}");
        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertContains('教室A', $names);
        $this->assertNotContains('教室B', $names);
    }

    public function test_store_creates_room(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);

        $res = $this->withToken($token)->postJson('/api/v1/rooms', [
            'name'      => '新教室',
            'capacity'  => 6,
            'campus_id' => $campus->id,
        ]);
        $res->assertStatus(201);
        $this->assertDatabaseHas('rooms', ['name' => '新教室', 'campus_id' => $campus->id]);
    }

    public function test_store_defaults_is_active_to_true(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);

        $res = $this->withToken($token)->postJson('/api/v1/rooms', [
            'name'      => '預設啟用教室',
            'capacity'  => 3,
            'campus_id' => $campus->id,
        ]);
        $res->assertStatus(201);
        $this->assertTrue((bool) $res->json('is_active'));
    }

    public function test_store_validates_required_fields(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);

        $res = $this->withToken($token)->postJson('/api/v1/rooms', []);
        $res->assertStatus(422);
    }

    public function test_update_room(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);
        $room   = Room::create(['name' => '舊名稱', 'campus_id' => $campus->id, 'capacity' => 2, 'is_active' => true]);

        $res = $this->withToken($token)->putJson("/api/v1/rooms/{$room->id}", ['name' => '新名稱', 'capacity' => 8]);
        $res->assertOk();
        $this->assertEquals('新名稱', $res->json('name'));
        $this->assertEquals(8, $res->json('capacity'));
    }

    public function test_destroy_room(): void
    {
        $campus = Campus::factory()->create();
        $token  = $this->directorToken($campus->id);
        $room   = Room::create(['name' => '待刪教室', 'campus_id' => $campus->id, 'capacity' => 1, 'is_active' => true]);

        $res = $this->withToken($token)->deleteJson("/api/v1/rooms/{$room->id}");
        $res->assertOk();
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    // ── SEC R-10 (#979): campus authorization on mutations + listing ──────────

    public function test_store_rejects_room_in_foreign_campus(): void
    {
        $own     = Campus::factory()->create();
        $foreign = Campus::factory()->create();
        $token   = $this->directorToken($own->id);

        $res = $this->withToken($token)->postJson('/api/v1/rooms', [
            'name'      => '越權教室',
            'capacity'  => 5,
            'campus_id' => $foreign->id,
        ]);

        $res->assertStatus(403);
        $this->assertDatabaseMissing('rooms', ['name' => '越權教室']);
    }

    public function test_update_rejects_foreign_campus_room(): void
    {
        $own     = Campus::factory()->create();
        $foreign = Campus::factory()->create();
        $token   = $this->directorToken($own->id);
        $room    = Room::create(['name' => '他校教室', 'campus_id' => $foreign->id, 'capacity' => 4, 'is_active' => true]);

        $res = $this->withToken($token)->putJson("/api/v1/rooms/{$room->id}", ['name' => '被改名']);

        $res->assertStatus(403);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'name' => '他校教室']);
    }

    public function test_destroy_rejects_foreign_campus_room(): void
    {
        $own     = Campus::factory()->create();
        $foreign = Campus::factory()->create();
        $token   = $this->directorToken($own->id);
        $room    = Room::create(['name' => '他校待刪', 'campus_id' => $foreign->id, 'capacity' => 4, 'is_active' => true]);

        $res = $this->withToken($token)->deleteJson("/api/v1/rooms/{$room->id}");

        $res->assertStatus(403);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_index_without_branch_filter_does_not_leak_other_campus(): void
    {
        $own     = Campus::factory()->create();
        $foreign = Campus::factory()->create();
        Room::create(['name' => '本校教室', 'campus_id' => $own->id,     'capacity' => 4, 'is_active' => true]);
        Room::create(['name' => '他校教室', 'campus_id' => $foreign->id, 'capacity' => 4, 'is_active' => true]);

        $token = $this->directorToken($own->id);
        // No branch_id param — previously returned ALL campuses' rooms.
        $res = $this->withToken($token)->getJson('/api/v1/rooms');

        $res->assertOk();
        $names = collect($res->json())->pluck('name')->all();
        $this->assertContains('本校教室', $names);
        $this->assertNotContains('他校教室', $names);
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserEngagement;
use App\Models\UserEngagementXpEvent;
use App\Services\EngagementXpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EngagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_rank_thresholds_returns_both_tracks(): void
    {
        $u = $this->makeUser('T');
        $r = $this->getJson('/api/v1/engagement/rank-thresholds', $this->bearer($u['tok']));
        $r->assertOk();
        $data = $r->json();
        $this->assertArrayHasKey('teacher', $data);
        $this->assertArrayHasKey('staff', $data);
        $this->assertGreaterThanOrEqual(19, count($data['teacher']));
        $this->assertGreaterThanOrEqual(19, count($data['staff']));
        $this->assertEquals(0, $data['teacher'][0]['min_xp']);
        $this->assertEquals('private_second', $data['teacher'][0]['key']);
        $this->assertArrayHasKey('label', $data['teacher'][0]);
    }

    public function test_my_progress_returns_engagement_data(): void
    {
        $u = $this->makeUser('T');
        UserEngagement::factory()->create([
            'user_id' => $u['id'],
            'role_track' => 'teacher',
            'rank_key' => 'sergeant',
            'xp_total' => 500, // 長期養成曲線：sergeant = 450–699（2026-06-14 重設）
        ]);

        $r = $this->getJson('/api/v1/engagement/my-progress', $this->bearer($u['tok']));
        $r->assertOk();
        $eng = $r->json('engagement');
        $this->assertEquals(500, $eng['xp_total']);
        $this->assertEquals('sergeant', $eng['rank_key']);
        $this->assertArrayHasKey('next_rank_xp', $eng);
        $this->assertFalse($eng['is_max']);
    }

    public function test_award_xp_creates_event_and_updates_total(): void
    {
        $u = $this->makeUser('T');
        $r = $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'attendance_marked',
            'entity_id' => 'session_99',
        ], $this->bearer($u['tok']));
        $r->assertOk();
        $this->assertTrue($r->json('awarded'));
        $this->assertEquals(5, $r->json('xp_gained'));
        $this->assertEquals(5, $r->json('total_xp'));

        $this->assertDatabaseHas('user_engagement', [
            'user_id' => $u['id'],
            'xp_total' => 5,
        ]);
    }

    public function test_award_xp_duplicate_entity_blocked(): void
    {
        $u = $this->makeUser('T');
        $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'attendance_marked',
            'entity_id' => 'session_100',
        ], $this->bearer($u['tok']))->assertOk();

        $r = $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'attendance_marked',
            'entity_id' => 'session_100',
        ], $this->bearer($u['tok']));
        $r->assertOk();
        $this->assertFalse($r->json('awarded'));
    }

    public function test_award_xp_daily_max_enforced(): void
    {
        $u = $this->makeUser('T');
        $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'rfid_checkin_ontime',
            'entity_id' => 'checkin_1',
        ], $this->bearer($u['tok']))->assertOk()->assertJsonPath('awarded', true);

        $r = $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'rfid_checkin_ontime',
            'entity_id' => 'checkin_2',
        ], $this->bearer($u['tok']));
        $r->assertOk()->assertJsonPath('awarded', false);
    }

    public function test_award_xp_daily_cap_200(): void
    {
        $u = $this->makeUser('T');
        UserEngagementXpEvent::query()->insert(
            array_map(fn ($i) => [
                'idempotency_key' => "cap_test_{$i}",
                'user_id' => $u['id'],
                'event_type' => 'learning_record_submit',
                'xp_delta' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ], range(1, 20))
        );

        $r = $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'attendance_marked',
            'entity_id' => 'cap_over',
        ], $this->bearer($u['tok']));
        $r->assertOk()->assertJsonPath('awarded', false);
    }

    public function test_xp_history_returns_recent_events(): void
    {
        $u = $this->makeUser('T');
        UserEngagementXpEvent::create([
            'idempotency_key' => 'hist_1',
            'user_id' => $u['id'],
            'event_type' => 'attendance_marked',
            'xp_delta' => 5,
        ]);

        $r = $this->getJson('/api/v1/engagement/xp-history', $this->bearer($u['tok']));
        $r->assertOk();
        $this->assertCount(1, $r->json('events'));
        $this->assertEquals('attendance_marked', $r->json('events.0.event_type'));
    }

    public function test_event_types_returns_all_configured_events(): void
    {
        $u = $this->makeUser('T');
        $r = $this->getJson('/api/v1/engagement/event-types', $this->bearer($u['tok']));
        $r->assertOk();
        $types = $r->json('event_types');
        $this->assertGreaterThanOrEqual(14, count($types));
        $keys = array_column($types, 'event');
        $this->assertContains('rfid_checkin_ontime', $keys);
        $this->assertContains('substitute_processed', $keys);
    }

    public function test_director_award_xp_uses_staff_track(): void
    {
        $u = $this->makeUser('A');
        $r = $this->postJson('/api/v1/engagement/award-xp', [
            'event' => 'lr_review_action',
            'entity_id' => 'review_1',
        ], $this->bearer($u['tok']));
        $r->assertOk()->assertJsonPath('awarded', true);
        $this->assertDatabaseHas('user_engagement', [
            'user_id' => $u['id'],
            'role_track' => 'staff',
        ]);
    }

    /** @return array{id:int,tok:string} */
    private function makeUser(string $type): array
    {
        $row = User::create([
            'LoginName' => 'engtest_' . uniqid() . '@example.com',
            'Name' => 'EngTest',
            'PSW' => password_hash('secret', PASSWORD_DEFAULT),
            'type' => $type,
            'phone' => '0900000000',
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $row->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return ['id' => (int) $row->id, 'tok' => $tok];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}

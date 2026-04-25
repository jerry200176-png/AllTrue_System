<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Notification;
use App\Models\NotificationRead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationCenterV2Test extends TestCase
{
    use RefreshDatabase;

    private function makeDirector(int $campusId): array
    {
        $userId = DB::table('User')->insertGetId([
            'LoginName' => 'dir-nc2-' . uniqid() . '@test.com',
            'Name'      => 'Director',
            'PSW'       => bcrypt('password'),
            'type'      => 'A',
            'status'    => 'active',
        ]);
        DB::table('Teacher')->insert(['id' => $userId, 'CampusID' => $campusId, 'T_Name' => 'Director']);
        DB::table('UserCampus')->insert(['UserID' => $userId, 'CampusID' => $campusId, 'Approved' => 1]);

        $token = DB::table('personal_access_tokens')->insertGetId([
            'tokenable_type' => 'App\\Models\\User',
            'tokenable_id'   => $userId,
            'name'           => 'test',
            'token'          => hash('sha256', $t = 'tok-' . uniqid()),
            'abilities'      => '["*"]',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        return ['id' => $userId, 'token' => $t, 'campus_id' => $campusId];
    }

    private function makeNotification(int $campusId, string $type = 'tuition', string $severity = 'high', ?string $createdAt = null): Notification
    {
        $n = Notification::create([
            'CampusID'  => $campusId,
            'Type'      => $type,
            'Severity'  => $severity,
            'Title'     => "Test $type notification",
            'SourceKey' => "$type-" . uniqid(),
            'SourceType' => 'StudentClass',
            'SourceID'   => '1',
        ]);
        if ($createdAt) {
            DB::table('Notifications')->where('id', $n->id)->update(['created_at' => $createdAt]);
        }
        return $n->fresh();
    }

    // ── FR1: 分類篩選 ────────────────────────────────────────────────────────

    public function test_type_filter_returns_only_matching_notifications(): void
    {
        $campus = Campus::factory()->create();
        $dir = $this->makeDirector($campus->id);

        $this->makeNotification($campus->id, 'tuition');
        $this->makeNotification($campus->id, 'learning_review');
        $this->makeNotification($campus->id, 'pending_swipe');

        $res = $this->withToken($dir['token'])
            ->getJson("/api/v1/notifications?branch_id={$campus->id}&type=tuition");

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('Type')->unique()->values()->all();
        $this->assertEquals(['tuition'], $types);
    }

    public function test_low_sessions_type_filter_works(): void
    {
        $campus = Campus::factory()->create();
        $dir = $this->makeDirector($campus->id);

        $this->makeNotification($campus->id, 'low_sessions', 'medium');
        $this->makeNotification($campus->id, 'tuition', 'high');

        $res = $this->withToken($dir['token'])
            ->getJson("/api/v1/notifications?branch_id={$campus->id}&type=low_sessions");

        $res->assertOk();
        $types = collect($res->json('data'))->pluck('Type')->unique()->values()->all();
        $this->assertEquals(['low_sessions'], $types);
    }

    // ── FR7: 30 天自動封存（API 預設不回傳舊已讀通知）────────────────────────

    public function test_read_notifications_older_than_30_days_are_excluded_by_default(): void
    {
        $campus = Campus::factory()->create();
        $dir = $this->makeDirector($campus->id);

        $old = $this->makeNotification($campus->id, 'tuition', 'medium', now()->subDays(35)->toDateTimeString());
        NotificationRead::create(['NotificationID' => $old->id, 'UserID' => $dir['id'], 'ReadAt' => now()->subDays(35)]);

        $recent = $this->makeNotification($campus->id, 'tuition');

        $res = $this->withToken($dir['token'])
            ->getJson("/api/v1/notifications?branch_id={$campus->id}&read=all");

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertNotContains($old->id, $ids, '30 天前的已讀通知不應出現在預設列表');
        $this->assertContains($recent->id, $ids);
    }

    public function test_unread_old_notifications_are_still_shown(): void
    {
        $campus = Campus::factory()->create();
        $dir = $this->makeDirector($campus->id);

        $old = $this->makeNotification($campus->id, 'tuition', 'high', now()->subDays(40)->toDateTimeString());
        // 未讀，即使很舊也應顯示

        $res = $this->withToken($dir['token'])
            ->getJson("/api/v1/notifications?branch_id={$campus->id}&read=unread");

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($old->id, $ids, '未讀的舊通知應該還是要顯示');
    }
}

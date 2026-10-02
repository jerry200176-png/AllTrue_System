<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Notification;
use App\Models\UserNotificationPreference;
use App\Services\NotificationLineDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationLineDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeStaffWithLine(int $campusId, string $lineId, bool $lineEnabled = true): int
    {
        $userId = DB::table('User')->insertGetId([
            'LoginName' => 'staff-' . uniqid() . '@test.com',
            'Name'      => 'Test Staff',
            'PSW'       => bcrypt('password'),
            'type'      => 'A',
            'status'    => 'active',
            'LineID'    => $lineId,
        ]);

        DB::table('UserCampus')->insert([
            'UserID'   => $userId,
            'CampusID' => $campusId,
            'Approved' => 1,
        ]);

        UserNotificationPreference::create([
            'user_id'      => $userId,
            'line_enabled' => $lineEnabled,
        ]);

        return $userId;
    }

    private function makeHighNotification(int $campusId): Notification
    {
        return Notification::create([
            'CampusID'  => $campusId,
            'Type'      => 'tuition',
            'Severity'  => 'high',
            'Title'     => '欠費提醒',
            'SourceKey' => 'test-' . uniqid(),
        ]);
    }

    // ── tests ─────────────────────────────────────────────────────────────────

    public function test_sends_line_push_when_staff_has_line_id_and_line_enabled(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $notification = $this->makeHighNotification($campus->id);

        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertSent(fn($req) =>
            str_contains($req->url(), 'api.line.me/v2/bot/message/push') &&
            $req['to'] === 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'
        );
    }

    public function test_does_not_send_when_staff_has_no_line_id(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $userId = DB::table('User')->insertGetId([
            'LoginName' => 'nolineid-' . uniqid() . '@test.com',
            'Name'      => 'No Line Staff',
            'PSW'       => bcrypt('password'),
            'type'      => 'A',
            'status'    => 'active',
            'LineID'    => null,
        ]);
        DB::table('UserCampus')->insert(['UserID' => $userId, 'CampusID' => $campus->id, 'Approved' => 1]);
        UserNotificationPreference::create(['user_id' => $userId, 'line_enabled' => true]);

        $notification = $this->makeHighNotification($campus->id);
        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertNothingSent();
    }

    public function test_does_not_send_when_line_enabled_is_false(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', false);

        $notification = $this->makeHighNotification($campus->id);
        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertNothingSent();
    }

    public function test_does_not_send_for_medium_severity(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $notification = Notification::create([
            'CampusID'  => $campus->id,
            'Type'      => 'tuition',
            'Severity'  => 'medium',
            'Title'     => '即將到期',
            'SourceKey' => 'test-med-' . uniqid(),
        ]);

        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertNothingSent();
    }

    public function test_does_not_send_when_campus_has_no_token(): void
    {
        $campus = Campus::factory()->create(['messaging_channel_token' => null]);
        $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

        $notification = $this->makeHighNotification($campus->id);
        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertNothingSent();
    }

    public function test_sends_to_multiple_staff_in_same_campus(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
        $this->makeStaffWithLine($campus->id, 'Ub2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5');

        $notification = $this->makeHighNotification($campus->id);
        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertSentCount(2);
    }

    public function test_respects_quiet_hours_all_day(): void
    {
        $campus = Campus::factory()->create([
            'messaging_channel_token' => 'fake-token-abc',
        ]);
        $userId = $this->makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
        UserNotificationPreference::where('user_id', $userId)->update([
            'quiet_hours_start' => '00:00',
            'quiet_hours_end'   => '23:59',
        ]);

        $notification = $this->makeHighNotification($campus->id);
        app(NotificationLineDispatcher::class)->dispatch($notification);

        Http::assertNothingSent();
    }
}

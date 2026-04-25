<?php

use App\Models\Campus;
use App\Models\Notification;
use App\Models\UserNotificationPreference;
use App\Services\NotificationLineDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
});

// ── helpers ──────────────────────────────────────────────────────────────────

function makeStaffWithLine(int $campusId, string $lineId, bool $lineEnabled = true): int
{
    $userId = DB::table('User')->insertGetId([
        'LoginName' => fake()->unique()->email(),
        'Name'      => fake()->name(),
        'PSW'       => bcrypt('password'),
        'type'      => 'A',
        'status'    => 'active',
    ]);

    DB::table('Teacher')->insert([
        'id'     => $userId,
        'LineID' => $lineId,
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

function makeHighNotification(int $campusId): Notification
{
    return Notification::create([
        'CampusID'  => $campusId,
        'Type'      => 'tuition',
        'Severity'  => 'high',
        'Title'     => '欠費提醒',
        'SourceKey' => 'test-' . uniqid(),
    ]);
}

// ── tests ─────────────────────────────────────────────────────────────────────

it('sends LINE push when staff has LineID and line_enabled', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);
    makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

    $notification = makeHighNotification($campus->id);

    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertSent(fn($req) =>
        str_contains($req->url(), 'api.line.me/v2/bot/message/push') &&
        $req['to'] === 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'
    );
});

it('does not send LINE push when staff has no LineID', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);
    // 用戶沒有 LineID
    $userId = DB::table('User')->insertGetId([
        'LoginName' => fake()->unique()->email(),
        'Name'      => fake()->name(),
        'PSW'       => bcrypt('password'),
        'type'      => 'A',
        'status'    => 'active',
    ]);
    DB::table('Teacher')->insert(['id' => $userId, 'LineID' => null]);
    DB::table('UserCampus')->insert(['UserID' => $userId, 'CampusID' => $campus->id, 'Approved' => 1]);
    UserNotificationPreference::create(['user_id' => $userId, 'line_enabled' => true]);

    $notification = makeHighNotification($campus->id);
    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertNothingSent();
});

it('does not send LINE push when line_enabled is false', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);
    makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4', lineEnabled: false);

    $notification = makeHighNotification($campus->id);
    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertNothingSent();
});

it('does not send LINE push for medium severity', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);
    makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

    $notification = Notification::create([
        'CampusID'  => $campus->id,
        'Type'      => 'tuition',
        'Severity'  => 'medium',
        'Title'     => '即將到期',
        'SourceKey' => 'test-med-' . uniqid(),
    ]);

    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertNothingSent();
});

it('does not send when campus has no messaging_channel_token', function () {
    $campus = Campus::factory()->create(['messaging_channel_token' => null]);
    makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');

    $notification = makeHighNotification($campus->id);
    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertNothingSent();
});

it('sends to multiple staff members in same campus', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);
    makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
    makeStaffWithLine($campus->id, 'Ub2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5');

    $notification = makeHighNotification($campus->id);
    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertSentCount(2);
});

it('respects quiet hours and does not send during quiet period', function () {
    $campus = Campus::factory()->create([
        'messaging_channel_token' => 'fake-token-abc',
    ]);

    $userId = makeStaffWithLine($campus->id, 'Ua1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4');
    // quiet hours 全天 00:00-23:59
    UserNotificationPreference::where('user_id', $userId)->update([
        'quiet_hours_start' => '00:00',
        'quiet_hours_end'   => '23:59',
    ]);

    $notification = makeHighNotification($campus->id);
    app(NotificationLineDispatcher::class)->dispatch($notification);

    Http::assertNothingSent();
});

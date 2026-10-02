<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use App\Support\LineNotifySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** GET/PUT /api/v1/line/notify-settings — 主任只改自己分校的 LINE 通知開關，並看到本月額度。 */
class LineNotifySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $mine;
    private Campus $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mine = Campus::factory()->create();
        $this->other = Campus::factory()->create();
        DB::table('Campus')->where('id', $this->mine->id)->update(['messaging_channel_token' => 'tok']);
        Http::fake([
            'api.line.me/v2/bot/message/quota/consumption' => Http::response(['totalUsage' => 37]),
            'api.line.me/v2/bot/message/quota' => Http::response(['type' => 'limited', 'value' => 200]),
        ]);
    }

    private function token(string $type, int $campusId): string
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'LoginName' => "line-notify-{$n}@example.com", 'Name' => "U{$n}", 'PSW' => 'secret',
            'type' => $type, 'phone' => '0912345678',
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => $type === 'A' ? 1 : 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    public function test_director_sees_defaults_and_quota_then_saves_own_campus(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->token('A', $this->mine->id)];

        $this->getJson('/api/v1/line/notify-settings', $h)->assertOk()->assertJson([
            'settings' => LineNotifySettings::DEFAULTS,
            'quota' => ['limit' => 200, 'used' => 37],
        ]);

        $this->putJson('/api/v1/line/notify-settings', ['settings' => ['swipe_in' => true, 'tuition_reminder' => false]], $h)
            ->assertOk()
            ->assertJsonPath('settings.swipe_in', true)
            ->assertJsonPath('settings.swipe_out', false)
            ->assertJsonPath('settings.tuition_reminder', false);

        $this->assertTrue(LineNotifySettings::enabled($this->mine->id, 'swipe_in'));
        $this->assertFalse(LineNotifySettings::enabled($this->mine->id, 'tuition_reminder'));
        $this->assertTrue(LineNotifySettings::enabled($this->mine->id, 'feedback_reply'));
        // 別的分校不受影響
        $this->assertFalse(LineNotifySettings::enabled($this->other->id, 'swipe_in'));
    }

    public function test_rejects_other_campus_unknown_type_and_teacher(): void
    {
        $director = ['Authorization' => 'Bearer ' . $this->token('A', $this->mine->id)];

        $this->putJson('/api/v1/line/notify-settings', ['branch_id' => $this->other->id, 'settings' => ['swipe_in' => true]], $director)
            ->assertForbidden();
        $this->putJson('/api/v1/line/notify-settings', ['settings' => ['marketing_blast' => true]], $director)
            ->assertStatus(422);

        $teacher = ['Authorization' => 'Bearer ' . $this->token('T', $this->mine->id)];
        $this->putJson('/api/v1/line/notify-settings', ['settings' => ['swipe_in' => true]], $teacher)
            ->assertForbidden();

        $this->assertFalse(LineNotifySettings::enabled($this->other->id, 'swipe_in'));
        $this->assertFalse(LineNotifySettings::enabled($this->mine->id, 'swipe_in'));
    }
}

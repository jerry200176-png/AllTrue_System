<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use App\Support\LineNotifySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** GET/PUT /api/v1/line/notify-settings — 主任只改自己分校的 LINE 通知開關。 */
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

    public function test_director_sees_defaults_then_turns_off_own_campus_only(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->token('A', $this->mine->id)];

        $this->getJson('/api/v1/line/notify-settings', $h)->assertOk()
            ->assertJson(['campus_id' => $this->mine->id, 'settings' => LineNotifySettings::DEFAULTS]);

        $off = array_map(fn (bool $v) => !$v, LineNotifySettings::DEFAULTS);
        $this->putJson('/api/v1/line/notify-settings', ['settings' => $off], $h)
            ->assertOk()->assertJson(['settings' => $off]);

        $this->assertSame($off, LineNotifySettings::get($this->mine->id));
        $this->assertSame(LineNotifySettings::DEFAULTS, LineNotifySettings::get($this->other->id));
    }

    public function test_rejects_other_campus_unknown_type_and_teacher(): void
    {
        $director = ['Authorization' => 'Bearer ' . $this->token('A', $this->mine->id)];

        $this->putJson('/api/v1/line/notify-settings', ['branch_id' => $this->other->id, 'settings' => ['swipe' => false]], $director)
            ->assertForbidden();
        $this->getJson("/api/v1/line/notify-settings?branch_id={$this->other->id}", $director)->assertForbidden();
        $this->putJson('/api/v1/line/notify-settings', ['settings' => ['marketing_blast' => true]], $director)
            ->assertStatus(422);

        $teacher = ['Authorization' => 'Bearer ' . $this->token('T', $this->mine->id)];
        $this->putJson('/api/v1/line/notify-settings', ['settings' => ['swipe' => false]], $teacher)
            ->assertForbidden();

        $this->assertSame(LineNotifySettings::DEFAULTS, LineNotifySettings::get($this->mine->id));
        $this->assertSame(LineNotifySettings::DEFAULTS, LineNotifySettings::get($this->other->id));
    }
}

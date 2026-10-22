<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** LINE settings and the payment message act on one campus: a director needs that campus. Synthetic data only. */
class LineSettingsCampusRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_director_without_an_approved_campus_reaches_nothing(): void
    {
        $campus = CampusFactory::new()->create();
        $student = Student::create(['name' => 'Line scope', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $token = $this->director($campus->id, false);
        $before = DB::table('Campus')->where('id', $campus->id)->first(['messaging_channel_token', 'messaging_channel_secret', 'LIFFID']);

        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $campus->id, 'messaging_channel_token' => 'x', 'messaging_channel_secret' => 'y', 'liff_id' => 'z'])->assertForbidden();
        $this->api($token)->getJson('/api/v1/line/status?branch_id=' . $campus->id)->assertForbidden();
        $this->api($token)->getJson("/api/v1/parent/payment-message/{$student->id}")->assertForbidden();
        $this->assertEquals($before, DB::table('Campus')->where('id', $campus->id)->first(['messaging_channel_token', 'messaging_channel_secret', 'LIFFID']));
    }

    public function test_settings_stay_within_the_directors_campus(): void
    {
        $own = CampusFactory::new()->create();
        $other = CampusFactory::new()->create();
        $token = $this->director($own->id, true);

        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $other->id, 'liff_id' => 'z'])->assertForbidden();
        $this->api($token)->postJson('/api/v1/line/settings', ['branch_id' => $own->id, 'liff_id' => 'own-liff'])->assertOk();
        $this->assertSame('own-liff', (string) DB::table('Campus')->where('id', $own->id)->value('LIFFID'));
    }

    private function director(int $campusId, bool $approved): string
    {
        $user = User::create(['LoginName' => 'line-dir-' . uniqid() . '@example.com', 'Name' => 'dir', 'type' => 'D', 'status' => 'active',
            'PSW' => password_hash('Password123', PASSWORD_DEFAULT), 'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => $campusId, 'Admin' => 0, 'Approved' => $approved]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}

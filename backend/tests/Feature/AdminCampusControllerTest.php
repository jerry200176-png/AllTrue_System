<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-AUDIT (#975): super-admin campus API must not echo swipe/Telegram secrets.
 */
class AdminCampusControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        $user = User::create([
            'LoginName' => 'super-' . uniqid() . '@test.com',
            'Name'      => 'Super',
            'PSW'       => 'secret',
            'type'      => 'S',
            'phone'     => 912000001,
        ]);
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    public function test_index_masks_campus_secrets(): void
    {
        $campus = Campus::factory()->create([
            'Token'                   => 'swipe-secret-token',
            'TelegramToken'           => 'telegram-bot-secret',
            'TelegramWebhookSecret'   => 'webhook-secret-value',
        ]);

        $res = $this->withToken($this->superAdminToken())->getJson('/api/v1/admin/campuses');
        $res->assertOk();

        $row = collect($res->json())->firstWhere('id', $campus->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['has_swipe_token']);
        $this->assertTrue($row['has_telegram_token']);
        $this->assertTrue($row['has_telegram_webhook_secret']);
        $this->assertArrayNotHasKey('Token', $row);
        $this->assertArrayNotHasKey('TelegramToken', $row);
        $this->assertArrayNotHasKey('TelegramWebhookSecret', $row);
        $this->assertStringNotContainsString('swipe-secret-token', $res->getContent());
        $this->assertStringNotContainsString('telegram-bot-secret', $res->getContent());
    }
}

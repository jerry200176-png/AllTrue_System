<?php

namespace Tests\Feature;

use App\Models\Campus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEC-AUDIT-004 (#973): the LINE webhook must fail CLOSED — a campus with no
 * configured channel secret must be rejected, not processed unsigned, and the
 * signature compare must be constant-time.
 */
class LineWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    private function postWebhook(int $campusId, string $body, ?string $sig)
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($sig !== null) {
            $server['HTTP_X_LINE_SIGNATURE'] = $sig;
        }
        return $this->call('POST', "/api/v1/line/webhook/{$campusId}", [], [], [], $server, $body);
    }

    public function test_webhook_without_configured_secret_is_rejected(): void
    {
        $campus = Campus::factory()->create();
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_secret' => '']);

        $res = $this->postWebhook($campus->id, json_encode(['events' => []]), 'anything');
        $res->assertStatus(503);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $campus = Campus::factory()->create();
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_secret' => 'channel-secret']);

        $res = $this->postWebhook($campus->id, json_encode(['events' => []]), 'wrong-signature');
        $res->assertStatus(400);
    }

    public function test_webhook_with_valid_signature_is_processed(): void
    {
        $secret = 'channel-secret-valid';
        $campus = Campus::factory()->create();
        DB::table('Campus')->where('id', $campus->id)->update(['messaging_channel_secret' => $secret]);

        $body = json_encode(['events' => []]);
        $sig  = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $res = $this->postWebhook($campus->id, $body, $sig);
        $res->assertOk();
    }
}

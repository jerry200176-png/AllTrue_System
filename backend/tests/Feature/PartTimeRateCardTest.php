<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\PartTimeRateCard;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartTimeRateCardTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campus = Campus::create([
            'name'           => 'RateTestCampus',
            'Token'          => 'rate-test-token',
            'code'           => 'rt',
            'Current'        => 0,
            'LineNotifyID'   => '',
            'Client_ID'      => '',
            'Client_Secret'  => '',
            'LIFFID'         => '',
            'LIFF_URL'       => '',
            'URL'            => '',
            'TelegramToken'  => '',
            'TelegramChatID' => '',
            'TelegramURL'    => '',
            'TeachLIFFID'    => '',
            'TeachLIFF_URL'  => '',
        ]);

        $director = User::create([
            'LoginName'          => 'rate-dir@example.com',
            'Name'               => 'RateDir',
            'PSW'                => password_hash('password', PASSWORD_DEFAULT),
            'type'               => 'A',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $director->id,
            'Admin'    => 1,
            'Approved' => 1,
        ]);

        $tok = bin2hex(random_bytes(32));
        AuthToken::create([
            'user_id'    => $director->id,
            'token'      => $tok,
            'expires_at' => now()->addDay(),
        ]);
        $this->token = $tok;
    }

    private function makeTeacher(): User
    {
        $t = User::create([
            'LoginName'          => 'rate-teacher-' . uniqid() . '@x.com',
            'Name'               => 'RateTeacher',
            'PSW'                => password_hash('password', PASSWORD_DEFAULT),
            'type'               => 'T',
            'MustChangePassword' => false,
        ]);
        UserCampus::create([
            'CampusID' => $this->campus->id,
            'UserID'   => $t->id,
            'Admin'    => 0,
            'Approved' => 1,
        ]);
        return $t;
    }

    /** @test */
    public function director_can_create_rate_card(): void
    {
        $teacher = $this->makeTeacher();

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/v1/part-time-rate-cards', [
                'user_id'        => $teacher->id,
                'branch_id'      => $this->campus->id,
                'class_size'     => '1v1',
                'session_type'   => 'subject',
                'rate_per_30min' => '250.00',
                'effective_from' => '2026-01-01',
            ]);

        $res->assertStatus(201);
        $this->assertSame('1v1', $res->json('class_size'));
        $this->assertSame('250.00', $res->json('rate_per_30min'));
    }

    /** @test */
    public function director_can_list_rate_cards_for_their_branch(): void
    {
        $teacher = $this->makeTeacher();
        PartTimeRateCard::create([
            'user_id'        => $teacher->id,
            'branch_id'      => $this->campus->id,
            'class_size'     => '1v2',
            'session_type'   => 'subject',
            'rate_per_30min' => '300.00',
            'effective_from' => '2026-01-01',
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->getJson('/api/v1/part-time-rate-cards?branch_id=' . $this->campus->id);

        $res->assertOk();
        $this->assertNotEmpty($res->json());
        $this->assertSame('1v2', $res->json('0.class_size'));
    }

    /** @test */
    public function director_can_update_rate_card(): void
    {
        $teacher = $this->makeTeacher();
        $card = PartTimeRateCard::create([
            'user_id'        => $teacher->id,
            'branch_id'      => $this->campus->id,
            'class_size'     => '1v1',
            'session_type'   => 'subject',
            'rate_per_30min' => '250.00',
            'effective_from' => '2026-01-01',
        ]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->putJson("/api/v1/part-time-rate-cards/{$card->id}", [
                'rate_per_30min' => '280.00',
            ]);

        $res->assertOk();
        $this->assertSame('280.00', $res->json('rate_per_30min'));
    }

    /** @test */
    public function director_can_delete_rate_card(): void
    {
        $teacher = $this->makeTeacher();
        $card = PartTimeRateCard::create([
            'user_id'        => $teacher->id,
            'branch_id'      => $this->campus->id,
            'class_size'     => '1v3_plus',
            'session_type'   => 'subject',
            'rate_per_30min' => '350.00',
            'effective_from' => '2026-01-01',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->deleteJson("/api/v1/part-time-rate-cards/{$card->id}")
            ->assertOk();

        $this->assertNull(PartTimeRateCard::find($card->id));
    }

    /** @test */
    public function rate_for_static_method_returns_correct_rate(): void
    {
        $teacher = $this->makeTeacher();
        PartTimeRateCard::create([
            'user_id'        => $teacher->id,
            'branch_id'      => $this->campus->id,
            'class_size'     => '1v1',
            'session_type'   => 'subject',
            'rate_per_30min' => '250.00',
            'effective_from' => '2026-01-01',
            'effective_until' => null,
        ]);

        $card = PartTimeRateCard::rateFor($teacher->id, $this->campus->id, '1v1', 'subject', '2026-06-07');
        $this->assertNotNull($card);
        $this->assertSame('250.00', (string) $card->rate_per_30min);
    }

    /** @test */
    public function unauthenticated_returns_403(): void
    {
        $this->getJson('/api/v1/part-time-rate-cards')->assertStatus(403);
    }
}

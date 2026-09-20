<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\SchoolDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_resolves_alias_to_canonical_with_location(): void
    {
        $directory = new SchoolDirectory();
        $hits = $directory->search('大安國中');

        $this->assertNotEmpty($hits);
        $this->assertSame('臺北市立大安國民中學', $hits[0]['canonical_name']);
        $this->assertSame('臺北市', $hits[0]['municipality']);
        $this->assertStringContainsString('臺北市', $hits[0]['label']);
        $this->assertSame('大安國中', $hits[0]['matched_alias']);
    }

    public function test_same_short_name_keeps_distinct_municipal_identities(): void
    {
        $directory = new SchoolDirectory();
        // "中正國中" should not collapse unrelated municipalities; curated set has Taipei 中正.
        $hits = $directory->search('中正國中');
        $this->assertNotEmpty($hits);
        foreach ($hits as $hit) {
            $this->assertNotSame('', $hit['municipality']);
            $this->assertNotSame('', $hit['id']);
        }
        $ids = array_column($hits, 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_api_requires_auth_and_returns_suggestions(): void
    {
        $guest = $this->getJson('/api/v1/schools?q='.urlencode('建中'));
        $guest->assertStatus(401);

        $token = $this->createDirectorToken([1]);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schools?q='.urlencode('建中'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('臺北市立建國高級中學', $data[0]['canonical_name']);
    }

    public function test_empty_query_returns_empty_list(): void
    {
        $token = $this->createDirectorToken([1]);
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/schools?q=');

        $response->assertOk()->assertJsonPath('data', []);
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-schools-'.uniqid('', true).'@example.com',
            'Name' => 'School Director',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '09'.random_int(10000000, 99999999),
            'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'UserID' => $user->id,
                'CampusID' => $campusId,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}

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
        $this->assertSame('tpe-daan-jh', $hits[0]['id']);
        $this->assertSame('臺北市立大安國民中學（臺北市 大安區）', $hits[0]['label']);
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
        $this->assertSame(['data'], array_keys($response->json()));
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('臺北市立建國高級中學', $data[0]['canonical_name']);
        $this->assertDirectorySchema($data);
    }

    public function test_teacher_with_campus_is_forbidden(): void
    {
        $token = $this->createToken('T', [1]);

        $this->requestWithToken($token)->getJson('/api/v1/schools?q='.urlencode('大安國中'))
            ->assertForbidden();
    }

    public function test_director_without_campus_is_forbidden(): void
    {
        $token = $this->createToken('D', []);

        $this->requestWithToken($token)->getJson('/api/v1/schools?q='.urlencode('大安國中'))
            ->assertForbidden();
    }

    public function test_director_required_to_change_password_is_blocked(): void
    {
        $token = $this->createToken('A', [1], true);

        $this->requestWithToken($token)->getJson('/api/v1/schools?q='.urlencode('大安國中'))
            ->assertStatus(428)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_super_admin_bypasses_role_and_campus_gates(): void
    {
        $token = $this->createToken('S', []);

        $response = $this->requestWithToken($token)
            ->getJson('/api/v1/schools?q='.urlencode('大安國中'))
            ->assertOk();

        $this->assertNotEmpty($response->json('data'));
    }

    public function test_company_global_directory_is_identical_for_directors_in_distinct_campuses(): void
    {
        $first = $this->requestWithToken($this->createToken('D', [1]))
            ->getJson('/api/v1/schools?q='.urlencode('大安國中').'&limit=12')
            ->assertOk()->json('data');
        $second = $this->requestWithToken($this->createToken('D', [2]))
            ->getJson('/api/v1/schools?q='.urlencode('大安國中').'&limit=12')
            ->assertOk()->json('data');

        $this->assertSame($first, $second);
        $this->assertNotEmpty($first);
        $daan = collect($first)->firstWhere('canonical_name', '臺北市立大安國民中學');
        $this->assertNotNull($daan);
        $this->assertSame('臺北市立大安國民中學', $daan['canonical_name']);
        $this->assertSame('tpe-daan-jh', $daan['id']);
        $this->assertSame('臺北市立大安國民中學（臺北市 大安區）', $daan['label']);
        $this->assertSame('臺北市', $daan['municipality']);
        $this->assertSame('大安區', $daan['district']);
        $this->assertSame('313501', $daan['school_code']);
        $this->assertSame('大安國中', $daan['matched_alias']);
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

    public function test_query_normalizes_tai_variant_and_unknown_query_is_empty(): void
    {
        $token = $this->createDirectorToken([1]);

        $tai = $this->requestWithToken($token)->getJson('/api/v1/schools?q='.urlencode('台北市立大安國中'))
            ->assertOk()->json('data');
        $this->assertNotEmpty($tai);
        $this->assertSame('臺北市立大安國民中學', $tai[0]['canonical_name']);

        $this->requestWithToken($token)->getJson('/api/v1/schools?q='.urlencode('不存在的學校'))
            ->assertOk()->assertJsonPath('data', []);
    }

    private function createDirectorToken(array $campusIds): string
    {
        return $this->createToken('A', $campusIds);
    }

    private function createToken(string $type, array $campusIds, bool $mustChangePassword = false): string
    {
        $user = User::create([
            'LoginName' => 'director-schools-'.uniqid('', true).'@example.com',
            'Name' => 'School Director',
            'PSW' => 'secret',
            'type' => $type,
            'phone' => '09'.random_int(10000000, 99999999),
            'MustChangePassword' => $mustChangePassword,
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

    private function requestWithToken(string $token): self
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);
    }

    private function assertDirectorySchema(array $data): void
    {
        $keys = ['canonical_name', 'district', 'id', 'label', 'matched_alias', 'municipality', 'school_code'];
        sort($keys);
        foreach ($data as $item) {
            $actualKeys = array_keys($item);
            sort($actualKeys);
            $this->assertSame($keys, $actualKeys);
            foreach (['id', 'canonical_name', 'municipality', 'label'] as $key) {
                $this->assertIsString($item[$key]);
                $this->assertNotSame('', trim($item[$key]));
            }
            foreach (['district', 'matched_alias', 'school_code'] as $key) {
                $this->assertTrue($item[$key] === null || is_string($item[$key]));
            }
        }
    }
}

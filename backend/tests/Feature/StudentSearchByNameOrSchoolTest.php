<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** In-app #313 / GitHub #3065: combined student name-or-school search. */
class StudentSearchByNameOrSchoolTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_name_or_partial_school_and_keeps_null_school_safe(): void
    {
        $token = $this->directorToken(11);
        $this->student('林小明', '台北市立大安高中', 11);
        $this->student('王小華', '新竹縣光華國中', 11);
        $this->student('無學校資料', null, 11);

        $this->assertNames($token, 'search=' . urlencode('光華'), ['王小華']);
        $this->assertNames($token, 'search=' . urlencode('小明'), ['林小明']);
        $this->assertNames($token, 'search=' . urlencode('不存在'), []);
    }

    public function test_search_is_campus_scoped_and_foreign_campus_request_is_forbidden(): void
    {
        $token = $this->directorToken(11);
        $this->student('本校學生', '光華國中', 11);
        $this->student('他校學生', '光華國中', 22);

        $this->assertNames($token, 'search=' . urlencode('光華'), ['本校學生']);
        $this->withHeaders($this->auth($token))->getJson('/api/v1/students?branch_id=22&search=' . urlencode('光華'))->assertForbidden();
    }

    public function test_search_composes_with_class_status_and_sanitizes_emoji(): void
    {
        $token = $this->directorToken(11);
        $this->student('符合條件', '學校甲', 11, 3, 'active');
        $this->student('錯年級', '學校甲', 11, 4, 'active');
        $this->student('錯狀態', '學校甲', 11, 3, 'paused');

        $this->assertNames($token, 'search=' . urlencode('學校') . '&class_id=3&status=active', ['符合條件']);
        $this->assertNames($token, 'search=' . urlencode('🏫'), []);
        $this->assertNames($token, 'search=' . urlencode('學校🏫'), ['符合條件', '錯年級', '錯狀態']);
    }

    public function test_blank_search_and_legacy_name_filters_keep_existing_contracts(): void
    {
        $token = $this->directorToken(11);
        $this->student('姓名命中', '學校命中', 11);
        $this->student('另一位', '其他學校', 11);

        $this->assertNames($token, 'search=%20%20', ['另一位', '姓名命中']);
        $this->assertNames($token, 'name=' . urlencode('學校命中'), []);
        $this->assertNames($token, 'name__ilike=' . urlencode('學校命中'), []);
    }

    private function student(string $name, ?string $school, int $campus, int $class = 1, string $status = 'active'): void
    {
        Student::create(compact('name', 'school') + [
            'SchoolName' => $school,
            'CampusID' => $campus,
            'ClassID' => $class,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'status' => $status,
        ]);
    }

    private function assertNames(string $token, string $query, array $expected): void
    {
        $response = $this->withHeaders($this->auth($token))->getJson('/api/v1/students?campus_id=11&' . $query);
        $response->assertOk();
        $this->assertEqualsCanonicalizing($expected, collect($response->json('data') ?? $response->json())->pluck('name')->values()->all());
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function directorToken(int $campus): string
    {
        $user = User::create(['LoginName' => "director-313-{$campus}@example.com", 'Name' => '主任測試', 'PSW' => 'secret', 'type' => 'A', 'phone' => '0912345678', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}

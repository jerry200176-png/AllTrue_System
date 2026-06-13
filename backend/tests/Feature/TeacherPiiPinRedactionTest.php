<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TD-066：老師管理頁 PII 後端遮罩。
 *
 * `GET /teachers`（ProfileController::index）為多頁共用端點，無法整路掛 require_pin。
 * 改在控制器層：未通過 PIN（已設 PIN 且未驗證、非 super_admin）→ 遮罩老師 phone/line_id/rfid。
 * soft：未設 PIN → 原樣（零回歸）。
 */
class TeacherPiiPinRedactionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDirectorWithCampus(int $campusId): User
    {
        $director = User::create([
            'LoginName' => 'pii-director@example.com',
            'Name' => 'PiiDir',
            'PSW' => password_hash('account-pass', PASSWORD_DEFAULT),
            'type' => 'A',
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        return $director;
    }

    private function makeTeacherWithPhone(int $campusId, string $phone): User
    {
        $teacher = User::create([
            'LoginName' => 'pii-teacher@example.com',
            'Name' => 'PiiTeacher',
            'PSW' => password_hash('x', PASSWORD_DEFAULT),
            'type' => 'T',
            'phone' => $phone,
            'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        return $teacher;
    }

    private function token(int $userId): string
    {
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $t, 'expires_at' => now()->addDay()]);
        return $t;
    }

    /** @test soft：director 未設 PIN → 老師 phone 照常回傳（零回歸） */
    public function teacher_phone_visible_when_director_has_no_pin(): void
    {
        $campusId = 1;
        $director = $this->makeDirectorWithCampus($campusId);
        $this->makeTeacherWithPhone($campusId, '0911222333');
        $token = $this->token($director->id);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/teachers?per_page=all&role=teacher');
        $res->assertOk();
        $this->assertStringContainsString('0911222333', json_encode($res->json()), '未設 PIN 應可見 phone');
    }

    /** @test 已設 PIN 未驗證 → 老師 phone 被遮罩 */
    public function teacher_phone_redacted_when_pin_set_but_unverified(): void
    {
        $campusId = 1;
        $director = $this->makeDirectorWithCampus($campusId);
        $director->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->makeTeacherWithPhone($campusId, '0911222333');
        $token = $this->token($director->id);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/teachers?per_page=all&role=teacher');
        $res->assertOk();
        $this->assertStringNotContainsString('0911222333', json_encode($res->json()), '未驗證 PIN 應遮罩 phone');
    }

    /** @test 已設 PIN 且已驗證 → 老師 phone 恢復可見 */
    public function teacher_phone_visible_after_pin_verified(): void
    {
        $campusId = 1;
        $director = $this->makeDirectorWithCampus($campusId);
        $director->update(['pin_hash' => password_hash('2468', PASSWORD_DEFAULT)]);
        $this->makeTeacherWithPhone($campusId, '0911222333');
        $token = $this->token($director->id);
        AuthToken::where('token', $token)->update(['pin_verified_until' => now()->addMinutes(10)]);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/teachers?per_page=all&role=teacher');
        $res->assertOk();
        $this->assertStringContainsString('0911222333', json_encode($res->json()), '已驗證 PIN 應可見 phone');
    }
}

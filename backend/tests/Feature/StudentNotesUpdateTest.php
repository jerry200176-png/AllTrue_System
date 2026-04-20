<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the "Fix Student Notes Clear Bug" PRD.
 *
 * The bug: PUT /api/v1/students/{id} used isset($input['notes']) which returns
 * false when the value is null, causing a {"notes": null} payload to silently
 * skip the update and retain the old value. Clearing the textarea from the UI
 * therefore appeared to do nothing.
 *
 * The fix: array_key_exists('notes', $input) + "$input['notes'] ?? ''".
 */
class StudentNotesUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_notes_can_be_cleared_with_empty_string(): void
    {
        $token = $this->createDirectorToken([1], 'director-notes-a@example.com');
        $student = $this->createStudent(1, '學生甲', '過敏：花生');

        $this->assertSame('過敏：花生', $student->fresh()->notes);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/students/{$student->id}", [
            'name' => $student->name,
            'grade' => 'P1',
            'phone' => '',
            'school' => '',
            'parent_name' => '',
            'parent_phone' => '',
            'notes' => '',
            'status' => 'active',
        ]);

        $res->assertOk();
        $this->assertSame('', (string) $student->fresh()->notes);
    }

    public function test_notes_can_be_cleared_with_null(): void
    {
        $token = $this->createDirectorToken([1], 'director-notes-b@example.com');
        $student = $this->createStudent(1, '學生乙', '家長偏好線上溝通');

        $this->assertSame('家長偏好線上溝通', $student->fresh()->notes);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/students/{$student->id}", [
            'name' => $student->name,
            'grade' => 'P1',
            'notes' => null,
            'status' => 'active',
        ]);

        $res->assertOk();
        $this->assertSame('', (string) $student->fresh()->notes);
    }

    public function test_notes_untouched_when_key_omitted_from_payload(): void
    {
        $token = $this->createDirectorToken([1], 'director-notes-c@example.com');
        $student = $this->createStudent(1, '學生丙', '原始備註');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/students/{$student->id}", [
            'name' => '學生丙新',
            'status' => 'active',
        ]);

        $res->assertOk();
        $fresh = $student->fresh();
        $this->assertSame('學生丙新', $fresh->name);
        $this->assertSame('原始備註', (string) $fresh->notes);
    }

    public function test_notes_can_be_updated_to_new_value(): void
    {
        $token = $this->createDirectorToken([1], 'director-notes-d@example.com');
        $student = $this->createStudent(1, '學生丁', '舊備註');

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->putJson("/api/v1/students/{$student->id}", [
            'name' => $student->name,
            'notes' => '新備註：家長星期三來接',
            'status' => 'active',
        ]);

        $res->assertOk();
        $this->assertSame('新備註：家長星期三來接', (string) $student->fresh()->notes);
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
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

    private function createStudent(int $campusId, string $name, string $notes = ''): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'notes' => $notes,
        ]);
    }
}

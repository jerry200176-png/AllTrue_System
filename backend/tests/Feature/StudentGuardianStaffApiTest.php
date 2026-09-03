<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentGuardianStaffApiTest extends TestCase
{
    use RefreshDatabase;

    private function directorToken(int $campusId = 1): string
    {
        $user = User::create([
            'LoginName' => 'guardian-staff-' . uniqid() . '@example.test',
            'Name' => 'Guardian Staff',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);
        UserCampus::firstOrCreate([
            'CampusID' => $campusId,
            'UserID' => $user->id,
        ], [
            'Admin' => 1,
            'Approved' => 1,
        ]);
        $plain = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $plain,
            'expires_at' => now()->addDay(),
        ]);

        return $plain;
    }

    private function student(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'name' => '監護人 CRUD 生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_name' => '王爸',
            'parent_phone' => '0912345678',
        ], $overrides));
    }

    public function test_staff_guardian_api_is_dark_when_flag_off(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $token = $this->directorToken();
        $student = $this->student();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/students/{$student->id}/guardians")
            ->assertNotFound();
    }

    public function test_two_guardians_via_staff_api_with_primary(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $token = $this->directorToken();
        $student = $this->student();

        $dad = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '爸爸',
                'phone' => '0912000001',
                'role' => 'father',
                'is_primary' => true,
            ]);
        $dad->assertCreated();

        $mom = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '媽媽',
                'phone' => '0912000002',
                'role' => 'mother',
                'is_primary' => false,
                'notify_learning_feedback' => false,
            ]);
        $mom->assertCreated();

        $list = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson("/api/v1/students/{$student->id}/guardians");
        $list->assertOk();
        $this->assertCount(2, $list->json('guardians'));
        $this->assertSame(
            1,
            StudentGuardian::where('student_id', $student->id)->where('status', '!=', 'revoked')->where('is_primary', true)->count()
        );
        $this->assertFalse((bool) StudentGuardian::find((int) $mom->json('id'))->notify_learning_feedback);
    }

    public function test_revoke_guardian_via_staff_api(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $token = $this->directorToken();
        $student = $this->student();

        $created = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '其他',
                'phone' => '0912999000',
                'role' => 'other',
                'is_primary' => false,
            ]);
        $created->assertCreated();
        $id = (int) $created->json('id');

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->deleteJson("/api/v1/students/{$student->id}/guardians/{$id}")
            ->assertOk();

        $this->assertSame(StudentGuardian::STATUS_REVOKED, StudentGuardian::find($id)->status);
    }

    public function test_primary_guardian_mirrors_legacy_parent_fields(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $token = $this->directorToken();
        $student = $this->student([
            'parent_name' => '舊家長',
            'parent_phone' => '0912111000',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '新主要',
                'phone' => '0912333444',
                'role' => 'father',
                'is_primary' => true,
            ])
            ->assertCreated();

        $student->refresh();
        $this->assertSame('新主要', $student->parent_name);
        $this->assertSame('0912333444', $student->parent_phone);
    }

    public function test_update_guardian_edits_existing_link_in_place(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $token = $this->directorToken();
        $student = $this->student([
            'parent_name' => '舊家長',
            'parent_phone' => '0912111000',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '原主要',
                'phone' => '0912333444',
                'role' => 'father',
                'is_primary' => true,
            ])
            ->assertCreated();

        $other = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/students/{$student->id}/guardians", [
                'display_name' => '原次要',
                'phone' => '0912444555',
                'role' => 'mother',
                'is_primary' => false,
            ])
            ->assertCreated();
        $otherId = (int) $other->json('id');

        $updated = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/students/{$student->id}/guardians/{$otherId}", [
                'display_name' => '新主要',
                'phone' => '0912555666',
                'role' => 'mother',
                'is_primary' => true,
            ]);
        $updated->assertOk();
        $this->assertSame($otherId, (int) $updated->json('id'));

        $active = StudentGuardian::query()
            ->where('student_id', $student->id)
            ->where('status', '!=', StudentGuardian::STATUS_REVOKED)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $active);
        $this->assertSame(1, $active->where('is_primary', true)->count());

        $edited = StudentGuardian::with('guardian')->findOrFail($otherId);
        $this->assertSame('新主要', $edited->guardian->display_name);
        $this->assertSame('0912555666', $edited->guardian->phone);
        $this->assertTrue((bool) $edited->is_primary);

        $student->refresh();
        $this->assertSame('新主要', $student->parent_name);
        $this->assertSame('0912555666', $student->parent_phone);
    }

    public function test_student_update_without_parent_fields_preserves_legacy_columns(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $token = $this->directorToken();
        $student = $this->student([
            'parent_name' => '保留爸',
            'parent_phone' => '0912555666',
        ]);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson("/api/v1/students/{$student->id}", [
                'name' => '監護人 CRUD 生改名',
                'notes' => 'only notes',
            ])
            ->assertOk();

        $student->refresh();
        $this->assertSame('保留爸', $student->parent_name);
        $this->assertSame('0912555666', $student->parent_phone);
        $this->assertSame('監護人 CRUD 生改名', $student->name);
    }
}

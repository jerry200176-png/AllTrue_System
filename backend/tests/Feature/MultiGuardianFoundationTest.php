<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ParentBinding\GuardianSyncService;
use App\Support\StudentContactPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Multi-guardian foundation: tables, dual-write, dual-read flag, legacy compatibility.
 * Staff HTTP CRUD is covered in a follow-up PR.
 */
class MultiGuardianFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function directorToken(int $campusId = 1): string
    {
        $user = User::create([
            'LoginName' => 'guardian-dir-' . uniqid() . '@example.test',
            'Name' => 'Guardian Director',
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
            'name' => '監護人測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_name' => '王爸',
            'parent_phone' => '0912345678',
        ], $overrides));
    }

    public function test_migration_creates_guardian_tables_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('guardians'));
        $this->assertTrue(Schema::hasTable('student_guardians'));
        foreach (['display_name', 'phone', 'phone_normalized', 'line_user_id'] as $col) {
            $this->assertTrue(Schema::hasColumn('guardians', $col), "guardians.{$col}");
        }
        foreach (['student_id', 'guardian_id', 'role', 'is_primary', 'status', 'notify_learning_feedback', 'notify_tuition', 'source'] as $col) {
            $this->assertTrue(Schema::hasColumn('student_guardians', $col), "student_guardians.{$col}");
        }
    }

    public function test_dual_write_syncs_primary_from_legacy_parent_phone_without_flag(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $student = $this->student();

        $link = app(GuardianSyncService::class)->syncPrimaryFromStudent($student);

        $this->assertNotNull($link);
        $this->assertTrue($link->is_primary);
        $this->assertSame(StudentGuardian::STATUS_ACTIVE, $link->status);
        $this->assertSame('0912345678', $link->guardian->phone);
        $this->assertSame('王爸', $link->guardian->display_name);
        $student->refresh();
        $this->assertSame('0912345678', $student->parent_phone);
    }

    public function test_dual_read_uses_legacy_when_flag_off_even_if_guardian_differs(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $student = $this->student(['parent_phone' => '0911111111']);
        $link = app(GuardianSyncService::class)->syncPrimaryFromStudent($student);
        $link->guardian->phone = '0922222222';
        $link->guardian->phone_normalized = '0922222222';
        $link->guardian->save();
        $student->refresh();
        $this->assertSame('0911111111', $student->parent_phone);
        $this->assertSame('0911111111', StudentContactPhone::forStudent($student));
    }

    public function test_dual_read_prefers_primary_guardian_when_flag_on(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $student = $this->student(['parent_phone' => '0911111111', 'parent_name' => '舊聯絡']);
        $link = app(GuardianSyncService::class)->syncPrimaryFromStudent($student);
        $link->guardian->phone = '0933333333';
        $link->guardian->phone_normalized = '0933333333';
        $link->guardian->save();
        $student->refresh();
        $this->assertSame('0911111111', $student->parent_phone);
        $this->assertSame('0933333333', StudentContactPhone::forStudent($student));
    }

    public function test_two_guardians_can_attach_same_student_with_independent_prefs(): void
    {
        $student = $this->student();
        $dad = app(GuardianSyncService::class)->upsertRelationship($student, [
            'display_name' => '爸爸',
            'phone' => '0912000001',
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'notify_learning_feedback' => true,
        ]);
        $mom = app(GuardianSyncService::class)->upsertRelationship($student, [
            'display_name' => '媽媽',
            'phone' => '0912000002',
            'role' => StudentGuardian::ROLE_MOTHER,
            'is_primary' => false,
            'notify_learning_feedback' => false,
        ]);

        $this->assertTrue((bool) $dad->notify_learning_feedback);
        $this->assertFalse((bool) $mom->notify_learning_feedback);
        $this->assertSame(1, StudentGuardian::where('student_id', $student->id)->notRevoked()->where('is_primary', true)->count());
        $this->assertCount(2, app(GuardianSyncService::class)->listForStudent((int) $student->id));
    }

    public function test_student_store_dual_writes_primary_guardian(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $token = $this->directorToken();

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/v1/students', [
                'name' => '新建雙寫生',
                'branch_id' => 1,
                'grade' => 'J1',
                'parent_name' => '聯絡人',
                'parent_phone' => '0977000111',
            ]);
        $res->assertCreated();
        $id = (int) $res->json('id');

        $this->assertDatabaseHas('guardians', [
            'phone_normalized' => '0977000111',
            'display_name' => '聯絡人',
        ]);
        $this->assertTrue(
            StudentGuardian::where('student_id', $id)->where('is_primary', true)->notRevoked()->exists()
        );
    }
}

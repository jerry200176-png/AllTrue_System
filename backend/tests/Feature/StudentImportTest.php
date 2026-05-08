<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_import_finds_header_after_title_rows(): void
    {
        $token = $this->createDirectorToken([1]);
        $csv = implode("\n", [
            '內湖校學籍資料表',
            '匯出時間,2026-04-29',
            '學生姓名,年級學校,手機',
            '王小明,國一內湖國中,0912345678',
            '李小華,小六內湖國小,0922333444',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/students/import', [
            'branch_id' => 1,
            'file' => UploadedFile::fake()->createWithContent('neihu_students.csv', $csv),
        ]);

        $response->assertOk()
            ->assertJsonPath('result.created', 2)
            ->assertJsonPath('result.updated', 0)
            ->assertJsonPath('result.skipped', 0);

        $this->assertDatabaseHas('Student', [
            'name' => '王小明',
            'CampusID' => 1,
            'Phone' => '0912345678',
        ]);
        $this->assertDatabaseHas('Student', [
            'name' => '李小華',
            'CampusID' => 1,
            'Phone' => '0922333444',
        ]);
    }

    public function test_student_import_returns_422_when_required_header_missing(): void
    {
        $token = $this->createDirectorToken([1]);
        $csv = implode("\n", [
            '內湖校學籍資料表',
            '家長姓名,電話',
            '王媽媽,0912345678',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/students/import', [
            'branch_id' => 1,
            'file' => UploadedFile::fake()->createWithContent('bad_students.csv', $csv),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('job.Status', 'failed')
            ->assertJsonPath('result', null);

        $this->assertStringContainsString('找不到「學生/姓名」欄位', (string) $response->json('error'));
        $this->assertSame(0, Student::count());
    }

    public function test_student_import_finds_header_after_fifteen_title_rows(): void
    {
        $token = $this->createDirectorToken([1]);
        $lines = [];
        for ($i = 1; $i <= 15; $i++) {
            $lines[] = "說明列,meta{$i}";
        }
        $lines[] = '學生姓名,年級學校,手機';
        $lines[] = '王小明,國一內湖國中,0912345678';
        $csv = implode("\n", $lines);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/students/import', [
            'branch_id' => 1,
            'file' => UploadedFile::fake()->createWithContent('many_meta_rows.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('result.created', 1);
        $this->assertDatabaseHas('Student', [
            'name' => '王小明',
            'CampusID' => 1,
        ]);
    }

    public function test_student_import_accepts_header_with_unicode_whitespace(): void
    {
        $token = $this->createDirectorToken([1]);
        $fw = mb_chr(0x3000, 'UTF-8');
        $csv = implode("\n", [
            '報表',
            $fw . '學生姓名' . $fw . ',年級學校,手機',
            '李小明,國二測試,0933444555',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/students/import', [
            'branch_id' => 1,
            'file' => UploadedFile::fake()->createWithContent('fw_header.csv', $csv),
        ]);

        $response->assertOk()->assertJsonPath('result.created', 1);
        $this->assertDatabaseHas('Student', [
            'name' => '李小明',
            'CampusID' => 1,
            'Phone' => '0933444555',
        ]);
    }

    public function test_student_import_returns_422_when_header_only_no_data_rows(): void
    {
        $token = $this->createDirectorToken([1]);
        $csv = implode("\n", [
            '開頭說明',
            '學生姓名,年級學校,手機',
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->post('/api/v1/students/import', [
            'branch_id' => 1,
            'file' => UploadedFile::fake()->createWithContent('header_only.csv', $csv),
        ]);

        $response->assertStatus(422)->assertJsonPath('job.Status', 'failed');
        $this->assertStringContainsString('未匯入任何列', (string) $response->json('error'));
        $this->assertSame(0, Student::count());
    }

    /**
     * @param  array<int>  $campusIds
     */
    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'import-director-' . uniqid() . '@example.com',
            'Name' => '匯入測試主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0900000000',
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
}

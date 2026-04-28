<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiagnoseTeacherSignInTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostic_is_read_only_and_reports_matching_teacher(): void
    {
        [$teacherId, $campus] = $this->makeFixture();

        $exit = Artisan::call('teacher-signin:diagnose', [
            '--date' => '2026-04-28',
            '--teacher-name' => '黃芝琳',
        ]);

        $this->assertSame(0, $exit);
        $this->assertGreaterThan(0, $teacherId);
        $this->assertGreaterThan(0, $campus->id);
        $this->assertDatabaseCount('TeacherSingIn', 0);
        $this->assertDatabaseCount('StudentSingIn', 1);
    }

    public function test_diagnostic_requires_teacher_filter(): void
    {
        $exit = Artisan::call('teacher-signin:diagnose', [
            '--date' => '2026-04-28',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide --teacher-id or --teacher-name', Artisan::output());
    }

    private function makeFixture(): array
    {
        static $n = 0;
        $n++;

        $campus = Campus::create([
            'name' => "大安診斷{$n}",
            'Token' => 'diag-token',
            'code' => "diag-daan-{$n}",
            'Current' => 0,
            'LineNotifyID' => '',
            'Client_ID' => '',
            'Client_Secret' => '',
            'LIFFID' => '',
            'LIFF_URL' => '',
            'URL' => '',
            'TelegramToken' => '',
            'TelegramChatID' => '',
            'TelegramURL' => '',
            'TeachLIFFID' => '',
            'TeachLIFF_URL' => '',
        ]);

        $rfid = "DIAG-RFID-{$n}";
        $student = Student::create([
            'name' => '同卡學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'RFID' => $rfid,
            'enable' => 1,
        ]);

        $teacherId = DB::table('User')->insertGetId([
            'LoginName' => "diag-huang-{$n}@example.com",
            'Name' => '黃芝琳',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000000',
        ]);
        DB::table('Teacher')->insert([
            'id' => $teacherId,
            'CampusID' => $campus->id,
            'T_Name' => '黃芝琳',
            'RFID' => null,
            'Enable' => 1,
            'MDT' => now(),
            'TelegramID' => '',
        ]);
        DB::table('UserCampus')->insert([
            'CampusID' => $campus->id,
            'UserID' => $teacherId,
            'Admin' => 0,
            'Approved' => 1,
            'RFID' => $rfid,
        ]);

        StudentSignIn::create([
            'StudentID' => $student->id,
            'SignInDT' => '2026-04-28 19:30:00',
            'SignOutDT' => null,
            'MDT' => '2026-04-28 19:30:00',
            'Memo' => 'swipe-rfid',
            'Status' => 'present',
            'CampusID' => $campus->id,
            'PersonType' => 'student',
            'SessionDeducted' => false,
        ]);

        return [$teacherId, $campus];
    }
}

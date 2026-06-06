<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentSignIn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverTeacherRfidCollisionSignInsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dry_run_reports_collision_without_writing_teacher_signin(): void
    {
        [$teacherId] = $this->makeCollisionFixture();

        $exit = Artisan::call('teacher-signin:recover-rfid-collisions', [
            '--date' => '2026-04-28',
            '--teacher-id' => $teacherId,
        ]);

        $this->assertSame(0, $exit);
        $this->assertDatabaseCount('TeacherSingIn', 0);
    }

    public function test_apply_requires_teacher_id_to_prevent_broad_writes(): void
    {
        $this->makeCollisionFixture();

        $exit = Artisan::call('teacher-signin:recover-rfid-collisions', [
            '--date' => '2026-04-28',
            '--apply' => true,
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('--apply requires --teacher-id', Artisan::output());
        $this->assertDatabaseCount('TeacherSingIn', 0);
    }

    public function test_apply_creates_recovered_teacher_signin_once(): void
    {
        [$teacherId, $campus] = $this->makeCollisionFixture();

        $firstExit = Artisan::call('teacher-signin:recover-rfid-collisions', [
            '--date' => '2026-04-28',
            '--teacher-id' => $teacherId,
            '--apply' => true,
        ]);
        $secondExit = Artisan::call('teacher-signin:recover-rfid-collisions', [
            '--date' => '2026-04-28',
            '--teacher-id' => $teacherId,
            '--apply' => true,
        ]);

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);
        $this->assertDatabaseHas('TeacherSingIn', [
            'TeacherID' => $teacherId,
            'CampusID' => $campus->id,
            'SignInDT' => '2026-04-28 19:30:00',
            'SignOutDT' => '2026-04-28 21:00:00',
            'Source' => 'rfid',
            'Status' => 'source_only',
        ]);
        $this->assertSame(1, DB::table('TeacherSingIn')->where('TeacherID', $teacherId)->count());
        $this->assertSame(1, DB::table('StudentSingIn')->count(), 'Recovery must not delete or void the original student row.');
    }

    private function makeCollisionFixture(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-04-29 01:00:00'));
        static $n = 0;
        $n++;

        $campus = Campus::create([
            'name' => "大安測試{$n}",
            'Token' => 'daan-token',
            'code' => "recover-{$n}",
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

        $rfid = "HUANG-COLLIDE-{$n}";
        $student = Student::create([
            'name' => '誤綁學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'RFID' => $rfid,
            'enable' => 1,
        ]);

        $teacherId = DB::table('User')->insertGetId([
            'LoginName' => "huang-zhi-lin-{$n}@example.com",
            'Name' => '黃芝琳',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000000',
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
            'SignOutDT' => '2026-04-28 21:00:00',
            'MDT' => '2026-04-28 21:00:00',
            'Memo' => 'swipe-rfid',
            'Status' => 'present',
            'CampusID' => $campus->id,
            'PersonType' => 'student',
            'SessionDeducted' => false,
        ]);

        return [$teacherId, $campus, $student];
    }
}

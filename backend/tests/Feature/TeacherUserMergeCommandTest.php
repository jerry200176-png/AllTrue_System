<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Database\Factories\CampusFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeacherUserMergeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_change_database(): void
    {
        $ctx = $this->seedTwoTeachersWithRefs();
        $mergeLogin = $ctx['merge_login'];

        $status = Artisan::call('teachers:merge-users', [
            '--keep-login' => 'keep_merge_cmd@test.local',
            '--merge-login' => $mergeLogin,
        ]);
        $this->assertSame(0, $status);

        $this->assertDatabaseHas('User', [
            'id' => $ctx['merge_id'],
            'LoginName' => $mergeLogin,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('StudentClass', [
            'ID' => $ctx['sc_id'],
            'TeacherID' => $ctx['merge_id'],
        ]);
    }

    public function test_apply_merges_fk_and_deactivates_duplicate(): void
    {
        $ctx = $this->seedTwoTeachersWithRefs();
        $keepId = $ctx['keep_id'];
        $mergeId = $ctx['merge_id'];
        $mergeLogin = $ctx['merge_login'];
        $tokenForMerge = $ctx['merge_token'];

        $status = Artisan::call('teachers:merge-users', [
            '--keep-login' => 'keep_merge_cmd@test.local',
            '--merge-login' => $mergeLogin,
            '--apply' => true,
        ]);
        $this->assertSame(0, $status);

        $this->assertDatabaseHas('StudentClass', [
            'ID' => $ctx['sc_id'],
            'TeacherID' => $keepId,
        ]);
        $this->assertDatabaseHas('schedules', [
            'id' => $ctx['schedule_id'],
            'teacher_id' => $keepId,
        ]);

        $this->assertDatabaseMissing('auth_tokens', [
            'user_id' => $mergeId,
            'token' => $tokenForMerge,
        ]);

        $this->assertDatabaseHas('User', [
            'id' => $mergeId,
            'status' => 'inactive',
        ]);
        $this->assertStringStartsWith('merged', (string) DB::table('User')->where('id', $mergeId)->value('LoginName'));
        $this->assertStringContainsString((string) $keepId, (string) DB::table('User')->where('id', $mergeId)->value('LoginName'));

        $this->assertDatabaseMissing('Teacher', ['id' => $mergeId]);
        $this->assertDatabaseHas('Teacher', ['id' => $keepId]);
    }

    /**
     * @return array{keep_id:int,merge_id:int,merge_login:string,sc_id:int,schedule_id:int,merge_token:string}
     */
    private function seedTwoTeachersWithRefs(): array
    {
        $campus = CampusFactory::new()->create();

        $keep = User::create([
            'LoginName' => 'keep_merge_cmd@test.local',
            'Name' => 'Keep Teacher',
            'PSW' => Hash::make('secret'),
            'type' => 'T',
            'phone' => 911000001,
            'status' => 'active',
        ]);
        $merge = User::create([
            'LoginName' => 'merge_merge_cmd_' . uniqid() . '@test.local',
            'Name' => 'Merge Teacher',
            'PSW' => Hash::make('secret'),
            'type' => 'T',
            'phone' => 911000002,
            'status' => 'active',
        ]);

        UserCampus::create([
            'CampusID' => $campus->id,
            'UserID' => $keep->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);
        UserCampus::create([
            'CampusID' => $campus->id,
            'UserID' => $merge->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $now = now();
        DB::table('Teacher')->insert([
            [
                'id' => $keep->id,
                'CampusID' => $campus->id,
                'T_Name' => 'Keep',
                'Phone' => null,
                'RFID' => null,
                'TelegramID' => '',
                'Enable' => 1,
                'MDT' => $now,
            ],
            [
                'id' => $merge->id,
                'CampusID' => $campus->id,
                'T_Name' => 'Merge',
                'Phone' => null,
                'RFID' => 'RF123',
                'TelegramID' => '',
                'Enable' => 1,
                'MDT' => $now,
            ],
        ]);

        $student = Student::create([
            'name' => '合併測試生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'SchoolName' => '測試國小',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $merge->id,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'TotalHours' => 20,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);

        $scheduleId = DB::table('schedules')->insertGetId([
            'student_id' => $student->id,
            'teacher_id' => $merge->id,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'branch_id' => $campus->id,
            'schedule_date' => '2026-05-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $mergeToken = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $merge->id,
            'token' => $mergeToken,
            'expires_at' => now()->addDay(),
        ]);

        return [
            'keep_id' => (int) $keep->id,
            'merge_id' => (int) $merge->id,
            'merge_login' => (string) $merge->LoginName,
            'sc_id' => (int) $sc->ID,
            'schedule_id' => (int) $scheduleId,
            'merge_token' => $mergeToken,
        ];
    }
}

<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusFactory;
use Database\Factories\StudentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeacherLearningEngagementApisTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_learning_pending_summary_counts_pending_and_missing_today_sessions(): void
    {
        $campus = CampusFactory::new()->create();
        $teacher = User::create([
            'LoginName' => Str::random(8) . '@t.com',
            'Name' => '老師評測',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922000002',
            'MustChangePassword' => false,
        ]);
        UserCampus::firstOrCreate(
            ['CampusID' => $campus->id, 'UserID' => $teacher->id],
            ['Admin' => 0, 'Approved' => 1]
        );
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);

        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID'         => $student->id,
            'TeacherID'         => $teacher->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'ClassType'         => 'one_on_one',
            'ScheduleMode'      => 'count',
            'RemainingSessions' => 8,
            'UsedSessions'      => 0,
            'SessionCount'      => 8,
            'SessionDuration'   => 60,
            'TotalHours'        => 8,
            'Rate'              => 400,
            'Charge'            => 3200,
            'Pay'               => 3200,
            'Paid'              => 0,
            'Stop'              => 0,
            'StartDate'         => Carbon::today()->toDateString(),
            'Period'            => 4,
            'by1'               => $teacher->id,
            'RoomID'            => 'R1',
            'MDate'             => now(),
        ]);

        $today = Carbon::today()->toDateString();
        $pastDate = Carbon::today()->subDays(2)->toDateString();
        $csPast = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate'    => $pastDate,
            'StartTime'      => '23:00',
            'EndTime'        => '23:30',
            'Status'         => 'attended',
        ]);

        DB::table('LearningRecord')->insert([
            'StudentID'       => $student->id,
            'StudentClassID'  => $scId,
            'ClassSessionID'  => $csPast,
            'TeacherID'       => $teacher->id,
            'Subject'         => 'Math',
            'SessionDate'     => $pastDate,
            'StartTime'       => '23:00',
            'EndTime'         => '23:30',
            'Content'         => '-',
            'Status'          => 'pending',
            'Progress'        => '',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate'    => $today,
            'StartTime'      => '23:00',
            'EndTime'        => '23:30',
            'Status'         => 'scheduled',
        ]);

        $this->getJson("/api/v1/me/learning-pending-summary?branch_id={$campus->id}", [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('pending_learning_records', 1)
            ->assertJsonPath('today_sessions_without_record', 1)
            ->assertJsonPath('total', 2);
    }

    public function test_director_teacher_learning_fill_rates_returns_per_teacher_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00'));

        $campus = CampusFactory::new()->create();

        $tA = User::create([
            'LoginName' => Str::random(8) . '@ta.com',
            'Name'      => '老師甲',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => '0922111001',
            'MustChangePassword' => false,
        ]);
        $tB = User::create([
            'LoginName' => Str::random(8) . '@tb.com',
            'Name'      => '老師乙',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => '0922111002',
            'MustChangePassword' => false,
        ]);

        $director = User::create([
            'LoginName' => Str::random(8) . '@dir.com',
            'Name'      => '主任',
            'PSW'       => 'secret',
            'type'      => 'A',
            'phone'     => '0911111100',
            'MustChangePassword' => false,
        ]);
        foreach ([$campus->id] as $cid) {
            UserCampus::firstOrCreate(
                ['CampusID' => $cid, 'UserID' => $director->id],
                ['Admin' => 1, 'Approved' => 1]
            );
        }
        $dirToken = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $dirToken, 'expires_at' => now()->addDay()]);

        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);

        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID'         => $student->id,
            'TeacherID'         => $tA->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'ClassType'         => 'one_on_one',
            'ScheduleMode'      => 'count',
            'RemainingSessions' => 8,
            'UsedSessions'      => 0,
            'SessionCount'      => 8,
            'SessionDuration'   => 60,
            'TotalHours'        => 8,
            'Rate'              => 400,
            'Charge'            => 3200,
            'Pay'               => 3200,
            'Paid'              => 0,
            'Stop'              => 0,
            'StartDate'         => '2026-05-01',
            'Period'            => 4,
            'by1'               => $tA->id,
            'RoomID'            => 'R1',
            'MDate'             => now(),
        ]);

        $dAttend = '2026-05-09';
        $cs1 = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate'    => $dAttend,
            'StartTime'      => '10:00',
            'EndTime'        => '11:00',
            'Status'         => 'attended',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentID'       => $student->id,
            'StudentClassID'  => $scId,
            'ClassSessionID'  => $cs1,
            'TeacherID'       => $tA->id,
            'Subject'         => 'Math',
            'SessionDate'     => $dAttend,
            'StartTime'       => '10:00',
            'EndTime'         => '11:00',
            'Content'         => '課堂',
            'Status'          => 'pending',
            'Progress'        => '已進度文字',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $cs2 = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate'    => $dAttend,
            'StartTime'      => '14:00',
            'EndTime'        => '15:00',
            'Status'         => 'attended',
        ]);
        unset($cs2);

        // 老師乙：另一堂獨立課程資料（同學不同課）。
        $scB = DB::table('StudentClass')->insertGetId([
            'StudentID'         => $student->id,
            'TeacherID'         => $tB->id,
            'GradeID'           => 1,
            'SubjectID'         => 1,
            'ClassType'         => 'one_on_one',
            'ScheduleMode'      => 'count',
            'RemainingSessions' => 4,
            'UsedSessions'      => 1,
            'SessionCount'      => 5,
            'SessionDuration'   => 60,
            'TotalHours'        => 5,
            'Rate'              => 400,
            'Charge'            => 1600,
            'Pay'               => 1600,
            'Paid'              => 0,
            'Stop'              => 0,
            'StartDate'         => '2026-05-01',
            'Period'            => 4,
            'by1'               => $tB->id,
            'RoomID'            => 'R2',
            'MDate'             => now(),
        ]);
        $csB = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scB,
            'SessionDate'    => '2026-05-08',
            'StartTime'      => '16:00',
            'EndTime'        => '17:00',
            'Status'         => 'attended',
        ]);
        unset($csB);

        $res = $this->getJson(
            "/api/v1/reports/teacher-learning-fill-rates?branch_id={$campus->id}&days=14",
            ['Authorization' => 'Bearer ' . $dirToken, 'Accept' => 'application/json']
        );
        $res->assertOk();
        $teachers = $res->json('teachers');
        $this->assertIsArray($teachers);

        $rowA = collect($teachers)->firstWhere('teacher_id', $tA->id);
        $this->assertNotNull($rowA);
        $this->assertSame(2, (int) $rowA['sessions_attended']);
        $this->assertSame(1, (int) $rowA['learning_records_filled']);
        $this->assertSame(50, (int) $rowA['fill_rate_pct']);

        $rowB = collect($teachers)->firstWhere('teacher_id', $tB->id);
        $this->assertNotNull($rowB);
        $this->assertSame(1, (int) $rowB['sessions_attended']);
        $this->assertSame(0, (int) $rowB['learning_records_filled']);

        Carbon::setTestNow();
    }

    public function test_teacher_cannot_hit_director_fill_rates(): void
    {
        $campus = CampusFactory::new()->create();
        $teacher = User::create([
            'LoginName' => Str::random(8) . '@t2.com',
            'Name'      => 'T',
            'PSW'       => 'secret',
            'type'      => 'T',
            'phone'     => '0922222222',
            'MustChangePassword' => false,
        ]);
        UserCampus::firstOrCreate(
            ['CampusID' => $campus->id, 'UserID' => $teacher->id],
            ['Admin' => 0, 'Approved' => 1]
        );
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        $this->getJson("/api/v1/reports/teacher-learning-fill-rates?branch_id={$campus->id}", [
            'Authorization' => 'Bearer ' . $token,
            'Accept'        => 'application/json',
        ])->assertStatus(403);
    }
}

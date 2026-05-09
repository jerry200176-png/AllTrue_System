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

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    /** @return array{0: User, 1: string} */
    private function teacherWithToken(int $campusId): array
    {
        $u = User::create([
            'LoginName' => Str::random(10) . '@t.test',
            'Name' => '老師',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '09' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'MustChangePassword' => false,
        ]);
        UserCampus::firstOrCreate(
            ['CampusID' => $campusId, 'UserID' => $u->id],
            ['Admin' => 0, 'Approved' => 1]
        );
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$u, $tok];
    }

    /** @return array{0: User, 1: string} */
    private function directorWithToken(int $campusId): array
    {
        $d = User::create([
            'LoginName' => Str::random(10) . '@d.test',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0912345678',
            'MustChangePassword' => false,
        ]);
        UserCampus::firstOrCreate(
            ['CampusID' => $campusId, 'UserID' => $d->id],
            ['Admin' => 1, 'Approved' => 1]
        );
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $d->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$d, $tok];
    }

    private function insertCourse(int $studentId, int $teacherId, array $over = []): int
    {
        return DB::table('StudentClass')->insertGetId(array_merge([
            'StudentID' => $studentId,
            'TeacherID' => $teacherId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count',
            'RemainingSessions' => 8,
            'UsedSessions' => 0,
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'TotalHours' => 8,
            'Rate' => 400,
            'Charge' => 3200,
            'Pay' => 3200,
            'Paid' => 0,
            'Stop' => 0,
            'StartDate' => '2026-05-01',
            'Period' => 4,
            'by1' => $teacherId,
            'RoomID' => 'R1',
            'MDate' => now(),
        ], $over));
    }

    public function test_teacher_learning_pending_summary_counts_pending_and_missing_today_sessions(): void
    {
        $campus = CampusFactory::new()->create();
        [$teacher, $token] = $this->teacherWithToken($campus->id);

        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $scId = $this->insertCourse($student->id, $teacher->id, [
            'StartDate' => Carbon::today()->toDateString(),
        ]);

        $today = Carbon::today()->toDateString();
        $pastDate = Carbon::today()->subDays(2)->toDateString();
        $csPast = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => $pastDate,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'attended',
        ]);

        DB::table('LearningRecord')->insert([
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $csPast,
            'TeacherID' => $teacher->id,
            'Subject' => 'Math',
            'SessionDate' => $pastDate,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Content' => '-',
            'Status' => 'pending',
            'Progress' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => $today,
            'StartTime' => '23:00',
            'EndTime' => '23:30',
            'Status' => 'scheduled',
        ]);

        $this->getJson("/api/v1/me/learning-pending-summary?branch_id={$campus->id}", $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('pending_learning_records', 1)
            ->assertJsonPath('today_sessions_without_record', 1)
            ->assertJsonPath('total', 2);
    }

    public function test_director_teacher_learning_fill_rates_returns_per_teacher_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00'));

        $campus = CampusFactory::new()->create();
        [, $dirToken] = $this->directorWithToken($campus->id);

        [$tA] = $this->teacherWithToken($campus->id);
        $tA->forceFill(['Name' => '老師甲'])->save();
        [$tB] = $this->teacherWithToken($campus->id);
        $tB->forceFill(['Name' => '老師乙'])->save();

        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $scA = $this->insertCourse($student->id, $tA->id);
        $dAttend = '2026-05-09';

        $cs1 = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scA,
            'SessionDate' => $dAttend,
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Status' => 'attended',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentID' => $student->id,
            'StudentClassID' => $scA,
            'ClassSessionID' => $cs1,
            'TeacherID' => $tA->id,
            'Subject' => 'Math',
            'SessionDate' => $dAttend,
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Content' => '課堂',
            'Status' => 'pending',
            'Progress' => '已進度文字',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scA,
            'SessionDate' => $dAttend,
            'StartTime' => '14:00',
            'EndTime' => '15:00',
            'Status' => 'attended',
        ]);

        $scB = $this->insertCourse($student->id, $tB->id, [
            'RemainingSessions' => 4,
            'UsedSessions' => 1,
            'SessionCount' => 5,
            'TotalHours' => 5,
            'Charge' => 1600,
            'Pay' => 1600,
            'RoomID' => 'R2',
            'by1' => $tB->id,
        ]);
        DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scB,
            'SessionDate' => '2026-05-08',
            'StartTime' => '16:00',
            'EndTime' => '17:00',
            'Status' => 'attended',
        ]);

        $res = $this->getJson(
            "/api/v1/reports/teacher-learning-fill-rates?branch_id={$campus->id}&days=14",
            $this->bearer($dirToken)
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
        [, $token] = $this->teacherWithToken($campus->id);

        $this->getJson(
            "/api/v1/reports/teacher-learning-fill-rates?branch_id={$campus->id}",
            $this->bearer($token)
        )->assertStatus(403);
    }

    public function test_teacher_learning_progress_summary_returns_daily_and_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 09:00:00'));
        $campus = CampusFactory::new()->create();
        [$teacher, $token] = $this->teacherWithToken($campus->id);
        $student = StudentFactory::new()->create(['CampusID' => $campus->id]);
        $scId = $this->insertCourse($student->id, $teacher->id);

        DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => '2026-05-09',
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Status' => 'attended',
        ]);
        $cs2 = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $scId,
            'SessionDate' => '2026-05-10',
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Status' => 'attended',
        ]);
        DB::table('LearningRecord')->insert([
            'StudentID' => $student->id,
            'StudentClassID' => $scId,
            'ClassSessionID' => $cs2,
            'TeacherID' => $teacher->id,
            'Subject' => 'Math',
            'SessionDate' => '2026-05-10',
            'StartTime' => '10:00',
            'EndTime' => '11:00',
            'Content' => '課堂',
            'Status' => 'pending',
            'Progress' => '完成紀錄',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->getJson(
            "/api/v1/me/learning-progress-summary?branch_id={$campus->id}&days=2",
            $this->bearer($token)
        )->assertOk();

        $res->assertJsonPath('summary.expected_sessions', 2);
        $res->assertJsonPath('summary.completed_sessions', 1);
        $res->assertJsonPath('summary.completion_rate_pct', 50);
        $res->assertJsonPath('summary.today_expected_sessions', 1);
        $res->assertJsonPath('summary.today_completed_sessions', 1);
        $res->assertJsonPath('summary.today_completion_rate_pct', 100);
        $res->assertJsonPath('summary.streak_days', 1);

        $byDay = collect($res->json('by_day'));
        $this->assertSame(2, $byDay->count());
        $this->assertSame(1, (int) $byDay->firstWhere('date', '2026-05-09')['expected_sessions']);
        $this->assertSame(0, (int) $byDay->firstWhere('date', '2026-05-09')['completed_sessions']);

        Carbon::setTestNow();
    }

    public function test_teacher_learning_progress_summary_forbidden_when_branch_not_accessible(): void
    {
        $campusA = CampusFactory::new()->create();
        $campusB = CampusFactory::new()->create();
        [, $token] = $this->teacherWithToken($campusA->id);

        $this->getJson(
            "/api/v1/me/learning-progress-summary?branch_id={$campusB->id}",
            $this->bearer($token)
        )->assertStatus(403);
    }

    public function test_teacher_learning_progress_summary_handles_empty_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 09:00:00'));
        $campus = CampusFactory::new()->create();
        [, $token] = $this->teacherWithToken($campus->id);

        $res = $this->getJson(
            "/api/v1/me/learning-progress-summary?branch_id={$campus->id}&days=7",
            $this->bearer($token)
        )->assertOk();

        $res->assertJsonPath('summary.expected_sessions', 0);
        $res->assertJsonPath('summary.completed_sessions', 0);
        $res->assertJsonPath('summary.completion_rate_pct', 0);
        $res->assertJsonPath('summary.streak_days', 0);
        $this->assertCount(7, $res->json('by_day'));

        Carbon::setTestNow();
    }
}

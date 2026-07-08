<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #768 教學日誌漏交追蹤：需日誌狀態 + 24h 緩衝 + 無 LearningRecord = 漏交。
 */
class TeachingLogMissingTest extends TestCase
{
    use RefreshDatabase;

    private function director(int $campus = 1): string
    {
        $u = User::create(['LoginName' => 'dir-tl@example.com', 'Name' => 'Dir', 'PSW' => 'x', 'type' => 'A', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => 1, 'Approved' => 1]);
        $t = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $t, 'expires_at' => now()->addDay()]);
        return $t;
    }

    private function teacher(int $campus = 1): int
    {
        $u = User::create(['LoginName' => 'teach-tl-' . uniqid() . '@example.com', 'Name' => '張老師', 'PSW' => 'x', 'type' => 'T', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $u->id, 'Admin' => 0, 'Approved' => 1]);
        return (int) $u->id;
    }

    private function course(int $teacherId, int $campus = 1): array
    {
        $s = Student::create(['name' => '林同學', 'CampusID' => $campus, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $teacherId,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subMonth(), 'TotalHours' => 20,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 0, 'MDate' => now(), 'Stop' => 0,
            'ScheduleMode' => 'count', 'SessionCount' => 8, 'SessionDuration' => 60, 'RemainingSessions' => 8,
            'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        return [$s, $sc];
    }

    private function makeSession(int $studentClassId, string $date, string $status = 'attended'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $studentClassId, 'SessionDate' => $date, 'StartTime' => '16:00:00',
            'EndTime' => '17:00:00', 'Status' => $status,
        ]);
    }

    /** @test 需日誌堂次過 24h 無評量 → 列為漏交；有評量 / 今日 / 缺席 → 不列 */
    public function missing_endpoint_counts_only_unlogged_past_required_sessions(): void
    {
        $token = $this->director();
        $tid = $this->teacher();
        [$s, $sc] = $this->course($tid);

        // A) 3 天前 attended、無評量 → 漏交
        $this->makeSession($sc->ID, now()->subDays(3)->toDateString(), 'attended');
        // B) 3 天前 attended、有評量 → 不漏
        $withLog = $this->makeSession($sc->ID, now()->subDays(4)->toDateString(), 'attended');
        LearningRecord::create(['StudentClassID' => $sc->ID, 'ClassSessionID' => $withLog->id, 'TeacherID' => $tid, 'Status' => 'approved', 'Content' => '已上課', 'HomeworkStatus' => 'completed']);
        // C) 今天 attended（24h 緩衝內）→ 不漏
        $this->makeSession($sc->ID, now()->toDateString(), 'attended');
        // D) 3 天前 absent（不需日誌）→ 不漏
        $this->makeSession($sc->ID, now()->subDays(3)->toDateString(), 'absent');
        // E) 3 天前 duty（值班，不需日誌）→ 不漏
        $this->makeSession($sc->ID, now()->subDays(3)->toDateString(), 'duty');

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/teaching-logs/missing');
        $res->assertOk()
            ->assertJsonPath('total_missing', 1)
            ->assertJsonPath('teacher_count', 1)
            ->assertJsonPath('teachers.0.missing_count', 1);
    }

    /** @test 試聽有到（需日誌）也算漏交 */
    public function trial_attended_requires_log_counts_as_missing(): void
    {
        $token = $this->director();
        $tid = $this->teacher();
        [, $sc] = $this->course($tid);
        $this->makeSession($sc->ID, now()->subDays(2)->toDateString(), 'trial'); // trial session status, requires log

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/teaching-logs/missing');
        $res->assertOk()->assertJsonPath('total_missing', 1);
    }
}

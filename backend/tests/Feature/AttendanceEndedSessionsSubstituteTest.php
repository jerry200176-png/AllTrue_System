<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Schedule;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B1/B2 Regression — endedSessions (2026-04-21)
 *
 * Bug：補點名 (GET /api/v1/attendance/ended-sessions) 在 teacher role 時，
 * 原老師仍看到被代課的已結束堂；代課老師可能看到同課程其他非代課堂。
 *
 * 修正：追加堂次級 whereExists/whereNotExists 精確過濾。
 */
class AttendanceEndedSessionsSubstituteTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_teacher_cannot_see_substituted_ended_session(): void
    {
        [$t1Token, , , , $subSession] = $this->seedEndedSubstitutedSession();

        $res = $this->callEndedSessions($t1Token);
        $res->assertOk();
        $ids = $this->extractIds($res);
        $this->assertNotContains((int) $subSession->id, $ids,
            '原老師不應在補點名看到被代課的已結束堂（AC-B1）');
    }

    public function test_substitute_teacher_can_see_substituted_ended_session(): void
    {
        [, $t2Token, , $t2Id, $subSession] = $this->seedEndedSubstitutedSession();

        $res = $this->callEndedSessions($t2Token);
        $res->assertOk();
        $ids = $this->extractIds($res);
        $this->assertContains((int) $subSession->id, $ids,
            '代課老師應在補點名看到被代課的已結束堂（AC-B2）');

        $row = collect($res->json('data'))->firstWhere('class_session_id', (int) $subSession->id);
        $this->assertNotNull($row);
        $this->assertSame($t2Id, (int) ($row['teacher_id'] ?? 0),
            'ended-sessions row.teacher_id 應指向代課老師');
    }

    public function test_original_teacher_still_sees_non_substituted_ended_session(): void
    {
        [$t1Token, , $t1Id, , , $normalSession] = $this->seedEndedSubstitutedSession();

        $res = $this->callEndedSessions($t1Token);
        $res->assertOk();
        $ids = $this->extractIds($res);
        $this->assertContains((int) $normalSession->id, $ids,
            '原老師無代課的已結束堂仍應出現在補點名（AC-B3 防過度排除）');
    }

    public function test_substitute_teacher_does_not_see_other_contract_sessions(): void
    {
        [, $t2Token, , , , $normalSession] = $this->seedEndedSubstitutedSession();

        $res = $this->callEndedSessions($t2Token);
        $res->assertOk();
        $ids = $this->extractIds($res);
        $this->assertNotContains((int) $normalSession->id, $ids,
            '代課老師不應因代過某課程而看到同課程其他非代課堂（AC-B4）');
    }

    public function test_original_teacher_cannot_mark_substituted_session_attendance(): void
    {
        [$t1Token, , , , $subSession] = $this->seedEndedSubstitutedSession();

        $this->postAttendance($t1Token, $subSession)
            ->assertStatus(403)
            ->assertJson(['message' => '非該堂的授課老師，無法操作']);
    }

    public function test_substitute_teacher_can_mark_substituted_session_attendance(): void
    {
        [, $t2Token, , $t2Id, $subSession] = $this->seedEndedSubstitutedSession();

        $this->postAttendance($t2Token, $subSession)
            ->assertCreated();

        $this->assertDatabaseHas('StudentSingIn', [
            'ClassSessionID' => $subSession->id,
            'TeacherID' => $t2Id,
            'Status' => 'present',
        ]);
    }

    public function test_original_teacher_can_still_mark_same_day_non_substituted_session(): void
    {
        [$t1Token, , $t1Id, , , $normalSession] = $this->seedEndedSubstitutedSession();

        $this->postAttendance($t1Token, $normalSession)
            ->assertCreated();

        $this->assertDatabaseHas('StudentSingIn', [
            'ClassSessionID' => $normalSession->id,
            'TeacherID' => $t1Id,
            'Status' => 'present',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * @return array{0:string,1:string,2:int,3:int,4:ClassSession,5:ClassSession}
     *         [t1Token, t2Token, t1Id, t2Id, substitutedSession, normalSession]
     */
    private function seedEndedSubstitutedSession(): array
    {
        $campusId = 1;
        $pastDate = Carbon::now()->subDays(2)->toDateString();

        $t1 = User::create([
            'LoginName' => 't1-ended@example.com', 'Name' => '契約老師T1', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911003001', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t1->id, 'Admin' => 0, 'Approved' => 1]);
        $t1Token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $t1->id, 'token' => $t1Token, 'expires_at' => now()->addDay()]);

        $t2 = User::create([
            'LoginName' => 't2-ended@example.com', 'Name' => '代課老師T2', 'PSW' => 'x',
            'type' => 'T', 'phone' => '0911004002', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $t2->id, 'Admin' => 0, 'Approved' => 1]);
        $t2Token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $t2->id, 'token' => $t2Token, 'expires_at' => now()->addDay()]);

        $student = Student::create([
            'name' => '補點名代課測試生', 'CampusID' => $campusId, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => $t1->id, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => '2026-04-01', 'TotalHours' => 20,
            'SessionCount' => 10, 'SessionDuration' => 120,
            'RemainingSessions' => 10, 'UsedSessions' => 0,
            'Charge' => 1600, 'Pay' => 16000, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        // 被代課的已結束堂（pastDate 14:00–16:00, status=scheduled, 無 SignIn）
        $subSession = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $pastDate,
            'StartTime' => '14:00', 'EndTime' => '16:00', 'Status' => 'scheduled',
        ]);

        // 無代課的已結束堂（pastDate 18:00–20:00, 同課程不同時段）
        $normalSession = ClassSession::create([
            'StudentClassID' => $sc->ID, 'SessionDate' => $pastDate,
            'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled',
        ]);

        // 代課記錄：只代 14:00 那堂
        $anchor = Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t1->id,
            'day_of_week' => (int) Carbon::parse($pastDate)->dayOfWeekIso,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'rescheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => $pastDate,
            'student_course_id' => $sc->ID,
        ]);

        Schedule::create([
            'student_id' => $student->id,
            'teacher_id' => $t2->id,
            'day_of_week' => (int) Carbon::parse($pastDate)->dayOfWeekIso,
            'start_time' => '14:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
            'type' => 'normal',
            'deduction' => 1,
            'branch_id' => $campusId,
            'schedule_date' => $pastDate,
            'student_course_id' => $sc->ID,
            'original_schedule_id' => $anchor->id,
        ]);

        return [$t1Token, $t2Token, (int) $t1->id, (int) $t2->id, $subSession, $normalSession];
    }

    private function callEndedSessions(string $token): \Illuminate\Testing\TestResponse
    {
        $start = Carbon::now()->subDays(7)->toDateString();
        $end = Carbon::now()->toDateString();
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/attendance/ended-sessions?branch_id=1&start_date={$start}&end_date={$end}&per_page=100");
    }

    private function postAttendance(string $token, ClassSession $session): \Illuminate\Testing\TestResponse
    {
        $studentClass = StudentClass::findOrFail($session->StudentClassID);

        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/attendance', [
            'ClassSessionID' => $session->id,
            'StudentID' => (int) $studentClass->StudentID,
            'StudentClassID' => (int) $studentClass->ID,
            'Status' => 'present',
            'mark_mode' => 'arrival',
        ]);
    }

    private function extractIds(\Illuminate\Testing\TestResponse $res): array
    {
        return collect($res->json('data'))
            ->pluck('class_session_id')
            ->map(fn ($i) => (int) $i)
            ->all();
    }
}

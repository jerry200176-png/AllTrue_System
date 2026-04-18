<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ScheduleDiscrepancy;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ScheduleDiscrepancyApiTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────────────────────────
    // FR-001 / FR-005 — teacher can file a report for a known session;
    // reporter_id is always injected from the token, never from the body.
    // ─────────────────────────────────────────────────────────────────
    public function test_teacher_can_submit_discrepancy_for_known_session(): void
    {
        [$token, $teacher] = $this->makeUserToken(1, 'sd-t1@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'student_no_show',
            'notes' => '學生未到',
            // Attempt to tamper — must be ignored.
            'reporter_id' => 99999,
        ], $token);

        $res->assertStatus(201);
        $res->assertJsonPath('duplicate', false);
        $res->assertJsonPath('discrepancy.status', 'pending');
        $res->assertJsonPath('discrepancy.discrepancy_type', 'student_no_show');

        $this->assertDatabaseHas('schedule_discrepancies', [
            'class_session_id' => $sessionId,
            'reporter_id' => $teacher->id, // from token, not from body
            'branch_id' => 1,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('schedule_discrepancies', [
            'reporter_id' => 99999,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // FR-003 — duplicate guard: repeat submission for the same session
    // returns the existing report instead of creating a new one.
    // ─────────────────────────────────────────────────────────────────
    public function test_duplicate_report_for_same_session_returns_existing(): void
    {
        [$token] = $this->makeUserToken(1, 'sd-dup@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => '時間錯了',
        ], $token)->assertStatus(201);

        $res2 = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_student_list',
            'notes' => '再回報一次',
        ], $token);

        $res2->assertStatus(200);
        $res2->assertJsonPath('duplicate', true);
        $res2->assertJsonPath('existing.discrepancy_type', 'wrong_time');

        $this->assertEquals(1, DB::table('schedule_discrepancies')
            ->where('class_session_id', $sessionId)->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // FR-004 — missing_session must have subject/student/time and no
    // class_session_id; otherwise 422.
    // ─────────────────────────────────────────────────────────────────
    public function test_missing_session_requires_manual_context(): void
    {
        [$token] = $this->makeUserToken(1, 'sd-miss@test.com', 'T');

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'discrepancy_type' => 'missing_session',
            // missing subject / student_name / time_range
        ], $token);

        $res->assertStatus(422);

        $ok = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'discrepancy_type' => 'missing_session',
            'subject' => '國文',
            'student_name' => '小明',
            'time_range' => '14:00-15:30',
            'session_date' => now()->toDateString(),
            'notes' => '現場學生到了但系統沒有排課',
        ], $token);

        $ok->assertStatus(201);
        $ok->assertJsonPath('discrepancy.discrepancy_type', 'missing_session');
    }

    // ─────────────────────────────────────────────────────────────────
    // Lifecycle FR-006/FR-008 — pending → acknowledged → resolved with
    // mandatory 10-char resolution note; invalid note returns 422.
    // ─────────────────────────────────────────────────────────────────
    public function test_status_lifecycle_with_resolution_note_guard(): void
    {
        [$teacherToken] = $this->makeUserToken(1, 'sd-life-t@test.com', 'T');
        [$directorToken, $director] = $this->makeUserToken(1, 'sd-life-d@test.com', 'A');
        $sessionId = $this->makeClassSession(1);

        $create = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => '時間錯誤',
        ], $teacherToken)->assertStatus(201);
        $id = (int) $create->json('discrepancy.id');

        // pending → acknowledged
        $ack = $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id}", [
            'status' => 'acknowledged',
        ], $directorToken);
        $ack->assertOk();
        $ack->assertJsonPath('discrepancy.status', 'acknowledged');
        $this->assertDatabaseHas('schedule_discrepancies', [
            'id' => $id, 'status' => 'acknowledged',
            'acknowledged_by' => $director->id,
        ]);

        // resolved without note → 422
        $bad = $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id}", [
            'status' => 'resolved',
            'resolution_note' => '太短',
        ], $directorToken);
        $bad->assertStatus(422);

        // resolved with valid note → 200
        $ok = $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id}", [
            'status' => 'resolved',
            'resolution_note' => '已補排課，並通知家長與老師',
        ], $directorToken);
        $ok->assertOk();
        $ok->assertJsonPath('discrepancy.status', 'resolved');
        $this->assertDatabaseHas('schedule_discrepancies', [
            'id' => $id, 'status' => 'resolved',
            'resolved_by' => $director->id,
        ]);

        // Further updates on a resolved report → 409 (idempotency guard)
        $again = $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id}", [
            'status' => 'acknowledged',
        ], $directorToken);
        $again->assertStatus(409);
    }

    // ─────────────────────────────────────────────────────────────────
    // FR-011 — teacher may withdraw their own pending report; cannot do
    // so once a director has acknowledged it.
    // ─────────────────────────────────────────────────────────────────
    public function test_teacher_can_withdraw_own_pending_report_not_after_ack(): void
    {
        [$teacherToken] = $this->makeUserToken(1, 'sd-wd-t@test.com', 'T');
        [$directorToken] = $this->makeUserToken(1, 'sd-wd-d@test.com', 'A');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_student_list',
            'notes' => '學生名單錯',
        ], $teacherToken)->assertStatus(201);
        $id = (int) $res->json('discrepancy.id');

        // Pending → withdraw OK.
        $this->authJson('POST', "/api/v1/schedule-discrepancies/{$id}/withdraw", [], $teacherToken)
            ->assertOk()
            ->assertJsonPath('discrepancy.status', 'withdrawn');

        // File a fresh report, ack it, then try to withdraw → 409.
        $sessionId2 = $this->makeClassSession(1);
        $res2 = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId2,
            'discrepancy_type' => 'class_cancelled',
            'notes' => '該堂取消',
        ], $teacherToken)->assertStatus(201);
        $id2 = (int) $res2->json('discrepancy.id');

        $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id2}", [
            'status' => 'acknowledged',
        ], $directorToken)->assertOk();

        $this->authJson('POST', "/api/v1/schedule-discrepancies/{$id2}/withdraw", [], $teacherToken)
            ->assertStatus(409);
    }

    // ─────────────────────────────────────────────────────────────────
    // FR-011 — another teacher cannot withdraw someone else's report.
    // ─────────────────────────────────────────────────────────────────
    public function test_other_teacher_cannot_withdraw_someone_elses_report(): void
    {
        [$tokA] = $this->makeUserToken(1, 'sd-w-a@test.com', 'T');
        [$tokB] = $this->makeUserToken(1, 'sd-w-b@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'student_no_show',
            'notes' => '缺席',
        ], $tokA)->assertStatus(201);
        $id = (int) $res->json('discrepancy.id');

        $this->authJson('POST', "/api/v1/schedule-discrepancies/{$id}/withdraw", [], $tokB)
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // require_campus — cross-campus read is blocked.
    // A director from campus 2 cannot see reports in campus 1.
    // ─────────────────────────────────────────────────────────────────
    public function test_cross_campus_director_cannot_view_other_campus_reports(): void
    {
        [$teacherToken] = $this->makeUserToken(1, 'sd-xc-t@test.com', 'T');
        [$dir2Token] = $this->makeUserToken(2, 'sd-xc-d2@test.com', 'A');
        $sessionId = $this->makeClassSession(1);

        $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => 'xxx',
        ], $teacherToken)->assertStatus(201);

        // director of campus 2 asking for branch_id=1 → 403
        $this->authJson('GET', '/api/v1/schedule-discrepancies?branch_id=1', [], $dir2Token)
            ->assertStatus(403);

        // director of campus 2 fetching summary scoped to their own campus
        // must NOT see campus 1 reports.
        $summary = $this->authJson('GET', '/api/v1/schedule-discrepancies/summary?branch_id=2', [], $dir2Token);
        $summary->assertOk();
        $this->assertSame(0, (int) $summary->json('pending'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Cross-campus tampering: teacher in campus 2 tries to file a report
    // against branch_id=1 → 403.
    // ─────────────────────────────────────────────────────────────────
    public function test_teacher_cannot_file_report_for_another_campus(): void
    {
        [$token] = $this->makeUserToken(2, 'sd-t-c2@test.com', 'T');

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'discrepancy_type' => 'missing_session',
            'subject' => '國文',
            'student_name' => '小華',
            'time_range' => '14:00-15:30',
            'session_date' => now()->toDateString(),
        ], $token);

        $res->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // Session binding guard — class_session_id belonging to a different
    // campus than branch_id returns 403.
    // ─────────────────────────────────────────────────────────────────
    public function test_session_cross_campus_binding_is_blocked(): void
    {
        [$token] = $this->makeUserToken([1, 2], 'sd-bind@test.com', 'T');
        $sessionCampus1 = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 2,
            'class_session_id' => $sessionCampus1,
            'discrepancy_type' => 'wrong_time',
            'notes' => '企圖跨校偽造',
        ], $token);

        $res->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /my — teacher sees only their own reports.
    // ─────────────────────────────────────────────────────────────────
    public function test_mine_endpoint_scopes_to_reporter(): void
    {
        [$tokA, $userA] = $this->makeUserToken(1, 'sd-my-a@test.com', 'T');
        [$tokB, $userB] = $this->makeUserToken(1, 'sd-my-b@test.com', 'T');
        $sessionA = $this->makeClassSession(1);
        $sessionB = $this->makeClassSession(1);

        $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionA,
            'discrepancy_type' => 'wrong_time',
            'notes' => 'A',
        ], $tokA)->assertStatus(201);

        $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionB,
            'discrepancy_type' => 'student_no_show',
            'notes' => 'B',
        ], $tokB)->assertStatus(201);

        $mineA = $this->authJson('GET', '/api/v1/schedule-discrepancies/my?branch_id=1', [], $tokA);
        $mineA->assertOk();
        $rows = $mineA->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($userA->id, (int) $rows[0]['reporter_id']);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /active-for-session — returns the currently-active report so
    // the attendance page can render the "已回報" badge. After resolve it
    // still returns the resolved report (so teacher can see outcome).
    // ─────────────────────────────────────────────────────────────────
    public function test_active_for_session_returns_latest_relevant_report(): void
    {
        [$teacherToken] = $this->makeUserToken(1, 'sd-act-t@test.com', 'T');
        [$directorToken] = $this->makeUserToken(1, 'sd-act-d@test.com', 'A');
        $sessionId = $this->makeClassSession(1);

        // No report yet → null.
        $this->authJson('GET', "/api/v1/schedule-discrepancies/active-for-session?class_session_id={$sessionId}", [], $teacherToken)
            ->assertOk()
            ->assertJsonPath('discrepancy', null);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => 'abc',
        ], $teacherToken)->assertStatus(201);
        $id = (int) $res->json('discrepancy.id');

        $this->authJson('GET', "/api/v1/schedule-discrepancies/active-for-session?class_session_id={$sessionId}", [], $teacherToken)
            ->assertOk()
            ->assertJsonPath('discrepancy.id', $id)
            ->assertJsonPath('discrepancy.status', 'pending');

        // Resolve it, then the endpoint should still return it so the
        // teacher can see the director's response.
        $this->authJson('PUT', "/api/v1/schedule-discrepancies/{$id}", [
            'status' => 'resolved',
            'resolution_note' => '已補排課並通知相關人員',
        ], $directorToken)->assertOk();

        $this->authJson('GET', "/api/v1/schedule-discrepancies/active-for-session?class_session_id={$sessionId}", [], $teacherToken)
            ->assertOk()
            ->assertJsonPath('discrepancy.status', 'resolved');
    }

    // ─────────────────────────────────────────────────────────────────
    // Archive command — resolved/withdrawn reports older than 12 months
    // get archived_at set and disappear from the active summary.
    // ─────────────────────────────────────────────────────────────────
    public function test_archive_command_marks_old_reports(): void
    {
        [$tok, $user] = $this->makeUserToken(1, 'sd-arc@test.com', 'T');

        // Old resolved — 13 months ago
        $oldId = DB::table('schedule_discrepancies')->insertGetId([
            'class_session_id' => null,
            'reporter_id' => $user->id,
            'branch_id' => 1,
            'discrepancy_type' => 'missing_session',
            'session_date' => now()->subMonths(13)->toDateString(),
            'subject' => '數學',
            'student_name' => '小明',
            'time_range' => '15:00-16:00',
            'notes' => 'old one',
            'status' => 'resolved',
            'resolved_at' => now()->subMonths(13),
            'resolution_note' => '已處理完畢並排入系統',
            'created_at' => now()->subMonths(13),
            'updated_at' => now()->subMonths(13),
        ]);

        // Recent resolved — 1 month ago (must NOT be archived)
        $recentId = DB::table('schedule_discrepancies')->insertGetId([
            'class_session_id' => null,
            'reporter_id' => $user->id,
            'branch_id' => 1,
            'discrepancy_type' => 'missing_session',
            'session_date' => now()->subMonth()->toDateString(),
            'subject' => '數學',
            'student_name' => '小華',
            'time_range' => '15:00-16:00',
            'notes' => 'recent one',
            'status' => 'resolved',
            'resolved_at' => now()->subMonth(),
            'resolution_note' => '已處理完畢並排入系統',
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        Artisan::call('schedule-discrepancies:archive');

        $this->assertNotNull(DB::table('schedule_discrepancies')->where('id', $oldId)->value('archived_at'));
        $this->assertNull(DB::table('schedule_discrepancies')->where('id', $recentId)->value('archived_at'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Summary — excludes archived records.
    // ─────────────────────────────────────────────────────────────────
    public function test_summary_excludes_archived_records(): void
    {
        [$tok, $user] = $this->makeUserToken(1, 'sd-sum@test.com', 'A');

        // Active pending (should count)
        DB::table('schedule_discrepancies')->insert([
            'reporter_id' => $user->id, 'branch_id' => 1,
            'discrepancy_type' => 'wrong_time',
            'status' => 'pending', 'notes' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Archived pending (should NOT count)
        DB::table('schedule_discrepancies')->insert([
            'reporter_id' => $user->id, 'branch_id' => 1,
            'discrepancy_type' => 'wrong_time',
            'status' => 'pending', 'notes' => 'x',
            'archived_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies/summary?branch_id=1', [], $tok);
        $res->assertOk();
        $this->assertSame(1, (int) $res->json('pending'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Notifier graceful degradation — when LINE token is missing or the
    // group id is blank, report submission still succeeds and no
    // exception is thrown.
    // ─────────────────────────────────────────────────────────────────
    public function test_submit_succeeds_even_without_line_config(): void
    {
        // Null out both required fields on Campus 1.
        DB::table('Campus')->where('id', 1)->update([
            'messaging_channel_token' => null,
            'staff_line_group_id' => null,
        ]);

        [$token] = $this->makeUserToken(1, 'sd-nokey@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => '測試無 LINE 設定仍可回報',
        ], $token);
        $res->assertStatus(201);
    }

    // ────────────── helpers ──────────────

    private function authJson(string $method, string $url, array $data, string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url, $data);
    }

    private function makeUserToken($campusIds, string $loginName, string $type): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'SD ' . $loginName,
            'PSW' => 'secret',
            'type' => $type,
            'phone' => (string) rand(900000000, 999999999),
            'MustChangePassword' => false,
        ]);

        foreach ((array) $campusIds as $cid) {
            UserCampus::create([
                'CampusID' => $cid,
                'UserID' => $user->id,
                'Admin' => $type === 'A' ? 1 : 0,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return [$token, $user];
    }

    /**
     * Create a minimal Student + StudentClass + ClassSession in the
     * given campus and return the session ID.
     */
    private function makeClassSession(int $campusId): int
    {
        $studentId = DB::table('Student')->insertGetId([
            'name' => '測試生' . uniqid(),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $studentClassId = DB::table('StudentClass')->insertGetId([
            'StudentID' => $studentId,
            'GradeID' => 0,
            'SubjectID' => 1,
            'TeacherID' => 0,
            'by1' => 0,
            'StartDate' => now(),
            'TotalHours' => 0,
            'RoomID' => '',
            'SessionCount' => 1,
            'Charge' => 0,
        ]);

        return (int) DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $studentClassId,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '14:00:00',
            'EndTime' => '15:00:00',
            'Status' => 'scheduled',
        ]);
    }
}

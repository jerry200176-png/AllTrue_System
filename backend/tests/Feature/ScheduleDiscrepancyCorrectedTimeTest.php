<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ScheduleDiscrepancy;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\ScheduleDiscrepancyNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PRD 2026-04-18: 老師回報「正確時間」— FR-003 / FR-004 / FR-005.
 */
class ScheduleDiscrepancyCorrectedTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_valid_corrected_time_range(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-ok@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => '14:00-16:00',
            'notes' => '時間錯了',
        ], $token);

        $res->assertStatus(201);
        $res->assertJsonPath('discrepancy.corrected_time_range', '14:00-16:00');

        $this->assertDatabaseHas('schedule_discrepancies', [
            'class_session_id' => $sessionId,
            'corrected_time_range' => '14:00-16:00',
        ]);
    }

    public function test_store_trims_whitespace_in_corrected_time_range(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-trim@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => '  14:00 - 16:00  ',
            'notes' => 'n',
        ], $token);

        $res->assertStatus(201);
        // Stored as canonical zero-padded HH:MM-HH:MM.
        $res->assertJsonPath('discrepancy.corrected_time_range', '14:00-16:00');
    }

    public function test_store_omitted_corrected_time_range_is_null(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-null@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'notes' => 'no corrected time',
        ], $token);

        $res->assertStatus(201);
        $this->assertNull($res->json('discrepancy.corrected_time_range'));
        $this->assertDatabaseHas('schedule_discrepancies', [
            'class_session_id' => $sessionId,
            'corrected_time_range' => null,
        ]);
    }

    public function test_store_rejects_non_time_string(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-bad@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => '下午兩點',
            'notes' => 'x',
        ], $token);

        $res->assertStatus(422);
        $this->assertDatabaseMissing('schedule_discrepancies', [
            'class_session_id' => $sessionId,
        ]);
    }

    public function test_store_rejects_reversed_time_range(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-rev@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => '16:00-14:00',
            'notes' => 'x',
        ], $token);

        $res->assertStatus(422);
    }

    public function test_store_rejects_overlong_string(): void
    {
        [$token] = $this->makeUserToken(1, 'sdct-long@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $res = $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => str_repeat('1', 40),
            'notes' => 'x',
        ], $token);

        $res->assertStatus(422);
    }

    public function test_index_returns_corrected_time_range_field(): void
    {
        [$dirToken] = $this->makeUserToken([1], 'sdct-dir@test.com', 'A');
        [$teacherToken] = $this->makeUserToken(1, 'sdct-list-t@test.com', 'T');
        $sessionId = $this->makeClassSession(1);

        $this->authJson('POST', '/api/v1/schedule-discrepancies', [
            'branch_id' => 1,
            'class_session_id' => $sessionId,
            'discrepancy_type' => 'wrong_time',
            'corrected_time_range' => '09:00-10:30',
            'notes' => '早上改',
        ], $teacherToken)->assertStatus(201);

        $res = $this->authJson('GET', '/api/v1/schedule-discrepancies?branch_id=1', [], $dirToken);
        $res->assertOk();
        $rows = $res->json('data') ?? [];
        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('corrected_time_range', $rows[0]);
        $this->assertSame('09:00-10:30', $rows[0]['corrected_time_range']);
    }

    public function test_line_push_message_includes_corrected_time_range(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response(['message' => 'ok'], 200),
        ]);

        // Configure the campus so the notifier actually attempts a push.
        DB::table('Campus')->where('id', 1)->update([
            'messaging_channel_token' => 'test-token',
            'staff_line_group_id' => 'U-test-group',
        ]);

        [$teacher] = $this->makeUserRecord(1, 'sdct-noti-t@test.com', 'T');

        $discrepancy = ScheduleDiscrepancy::create([
            'class_session_id' => null,
            'reporter_id' => $teacher->id,
            'branch_id' => 1,
            'discrepancy_type' => 'wrong_time',
            'session_date' => now()->toDateString(),
            'subject' => '國文',
            'student_name' => '小明',
            'time_range' => '10:00-12:00',
            'corrected_time_range' => '14:00-16:00',
            'notes' => 'x',
            'status' => ScheduleDiscrepancy::STATUS_PENDING,
        ]);

        ScheduleDiscrepancyNotifier::notify($discrepancy);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $text = $body['messages'][0]['text'] ?? '';
            return str_contains($text, '正確時間應為：14:00-16:00');
        });
    }

    public function test_line_push_message_omits_corrected_time_line_when_empty(): void
    {
        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response(['message' => 'ok'], 200),
        ]);
        DB::table('Campus')->where('id', 1)->update([
            'messaging_channel_token' => 'test-token',
            'staff_line_group_id' => 'U-test-group',
        ]);

        [$teacher] = $this->makeUserRecord(1, 'sdct-noti-empty@test.com', 'T');

        $discrepancy = ScheduleDiscrepancy::create([
            'class_session_id' => null,
            'reporter_id' => $teacher->id,
            'branch_id' => 1,
            'discrepancy_type' => 'wrong_time',
            'session_date' => now()->toDateString(),
            'subject' => '國文',
            'student_name' => '小明',
            'time_range' => '10:00-12:00',
            'notes' => 'x',
            'status' => ScheduleDiscrepancy::STATUS_PENDING,
        ]);

        ScheduleDiscrepancyNotifier::notify($discrepancy);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $text = $body['messages'][0]['text'] ?? '';
            return !str_contains($text, '正確時間應為');
        });
    }

    // ────────── helpers ──────────

    private function authJson(string $method, string $url, array $data, string $token)
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->json($method, $url, $data);
    }

    private function makeUserToken($campusIds, string $loginName, string $type): array
    {
        [$user] = $this->makeUserRecord($campusIds, $loginName, $type);
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);
        return [$token, $user];
    }

    private function makeUserRecord($campusIds, string $loginName, string $type): array
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'SDCT',
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

        return [$user];
    }

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

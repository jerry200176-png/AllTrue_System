<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * QA Baseline: Learning Records Performance & Correctness
 *
 * Exercises the teacher learning-records flow:
 *   login -> fetch records -> filter -> verify structure
 *
 * Blocking criteria:
 *   - learning-records index must return 200 within per_page cap
 *   - teacher can only see own records
 *   - status filters must work correctly
 */
class LearningRecordsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_learning_records_within_per_page_cap(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(5);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?per_page=20');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertLessThanOrEqual(20, count($data));
        $this->assertCount(5, $data);
    }

    public function test_filters_records_by_status_correctly(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(5);

        DB::table('LearningRecord')
            ->whereIn('id', array_slice($setup['record_ids'], 0, 2))
            ->update(['Status' => 'approved']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?status=pending&per_page=20');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_respects_per_page_cap(): void
    {
        [$token] = $this->createTeacherWithRecords(3);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?per_page=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(200, $response->json('per_page'));
    }

    public function test_teacher_cannot_see_another_teachers_records(): void
    {
        [$token1, $setup1] = $this->createTeacherWithRecords(3, 'teacher_a_perf');
        [$token2, $setup2] = $this->createTeacherWithRecords(2, 'teacher_b_perf');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token1}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?per_page=50');

        $response->assertOk();
        $returnedIds = collect($response->json('data'))->pluck('id')->toArray();
        $overlap = array_intersect($returnedIds, $setup2['record_ids']);
        $this->assertEmpty($overlap, 'Teacher A should not see Teacher B records');
    }

    public function test_response_includes_required_frontend_fields(): void
    {
        [$token] = $this->createTeacherWithRecords(1);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?per_page=5');

        $response->assertOk();
        $record = $response->json('data.0');
        $this->assertNotNull($record);
        $this->assertArrayHasKey('id', $record);
        $this->assertArrayHasKey('Status', $record);
        $this->assertArrayHasKey('SessionDate', $record);
        $this->assertArrayHasKey('student_name', $record);
        $this->assertArrayHasKey('student_class_label', $record);
        $this->assertArrayHasKey('teacher_name', $record);
    }

    public function test_health_endpoint_returns_perf_flags(): void
    {
        // SEC-F3: perf_flags moved to /health/detailed (auth required).
        [$token] = $this->createTeacherWithRecords(1, 'perf_health_test');
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/health/detailed');
        $response->assertOk();
        $response->assertJsonStructure(['status', 'timestamp', 'perf_flags']);
        $this->assertEquals('ok', $response->json('status'));
    }

    /**
     * PRD 評量表日期可視性改善 — FR-003 日期 range 篩選 edge case：
     * start_date > end_date 時後端回傳空集合，不應 5xx。
     * 前端已有 dateRangeError 防呆避免送出此類參數，但後端仍須穩定處理。
     */
    public function test_date_range_with_start_greater_than_end_returns_empty_result(): void
    {
        [$token] = $this->createTeacherWithRecords(3);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?start_date=2099-12-31&end_date=2000-01-01&per_page=20');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    /**
     * PRD 評量表日期可視性改善 — FR-003 日期 range 篩選 Happy Path：
     * 指定合法的 start_date ～ end_date 僅回傳區間內記錄。
     */
    public function test_date_range_filter_returns_only_records_in_range(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(3);
        // 將其中一筆改成 2020-01-01 以外範圍
        DB::table('LearningRecord')
            ->whereIn('id', array_slice($setup['record_ids'], 0, 1))
            ->update(['SessionDate' => '2020-01-01']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?start_date=2019-12-01&end_date=2020-01-31&per_page=20');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('2020-01-01', $data[0]['SessionDate']);
    }

    public function test_server_timing_header_is_present(): void
    {
        [$token] = $this->createTeacherWithRecords(1);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?per_page=5');

        $response->assertOk();
        $this->assertTrue($response->headers->has('Server-Timing'));
        $this->assertTrue($response->headers->has('X-Trace-Id'));
    }

    // ──────────────────────────────────────────────

    private function createTeacherWithRecords(int $count, string $prefix = 'perf_teacher'): array
    {
        $email = "{$prefix}_" . uniqid() . '@test.com';

        $user = User::create([
            'LoginName' => $email,
            'Name' => 'PerfTeacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000001',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        // AttachAuthUser 中 auth_teacher_id = $user->id（不是 Teacher.id），
        // controller 的 StudentClass.TeacherID / LR.TeacherID 都會拿 user.id 比對。
        $teacherId = $user->id;

        $user->update(['teacher_id' => $teacherId]);

        $student = DB::table('Student')->insertGetId([
            'name' => 'PerfStudent',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
        ]);

        $studentClass = DB::table('StudentClass')->insertGetId([
            'StudentID' => $student,
            'TeacherID' => $teacherId,
            'SubjectID' => 1,
            'GradeID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(30)->toDateTimeString(),
            'TotalHours' => 20,
            'Charge' => 1000,
            'Pay' => 500,
            'Paid' => 0,
            'Stop' => 0,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'SessionCount' => 10,
            'SessionDuration' => 60,
        ]);

        $recordIds = [];
        for ($i = 0; $i < $count; $i++) {
            $date = now()->subDays($i)->format('Y-m-d');
            $sessionId = DB::table('ClassSession')->insertGetId([
                'StudentClassID' => $studentClass,
                'SessionDate' => $date,
                'StartTime' => '14:00',
                'EndTime' => '15:00',
                'Status' => 'attended',
            ]);

            $recordIds[] = DB::table('LearningRecord')->insertGetId([
                'StudentClassID' => $studentClass,
                'ClassSessionID' => $sessionId,
                'TeacherID' => $teacherId,
                'Subject' => 'Math',
                'SessionDate' => $date,
                'StartTime' => '14:00',
                'EndTime' => '15:00',
                'Content' => '',
                'Status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $tokenPlain = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $tokenPlain,
            'expires_at' => now()->addDay(),
        ]);

        return [
            $tokenPlain,
            [
                'user_id' => $user->id,
                'teacher_id' => $teacherId,
                'student_id' => $student,
                'student_class_id' => $studentClass,
                'record_ids' => $recordIds,
            ],
        ];
    }
}

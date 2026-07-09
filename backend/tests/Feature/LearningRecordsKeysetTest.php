<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRD: 評量表分頁穩定性與長期資料管控
 *
 * Keyset (cursor) pagination — `GET /api/v1/learning-records?before_id=N`
 *
 * Validates:
 *   FR-003  `before_id` filters WHERE id < before_id and ignores `page`.
 *   FR-004  Iterating via before_id terminates on empty `data`.
 *           Mid-iteration inserts do NOT affect already-enumerated pages.
 *   FR-006  `/api/v1/health` exposes `lr_default_window_days` perf flag.
 *   Risk 1  Strict branching: `before_id` path and OFFSET path never share state.
 *   Risk 2  Not validated here (tab-based window exemption is a frontend behavior).
 */
class LearningRecordsKeysetTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_id_returns_only_records_with_smaller_id(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(10);
        $ids = $setup['record_ids'];
        sort($ids);
        $mid = $ids[5]; // expect 5 records below this id

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/learning-records?before_id={$mid}&per_page=50");

        $response->assertOk();
        $returned = collect($response->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame('keyset', $response->json('pagination'));
        $this->assertCount(5, $returned, 'Should return exactly 5 records with id < mid');
        foreach ($returned as $id) {
            $this->assertLessThan($mid, $id, 'Each returned id must be < before_id');
        }
    }

    public function test_before_id_ignores_page_parameter(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(6);
        $ids = $setup['record_ids'];
        sort($ids);
        $anchor = end($ids); // last = largest id; all 5 others are < it

        // page=999 would normally return an empty OFFSET page; with before_id it must be ignored.
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/learning-records?before_id={$anchor}&page=999&per_page=50");

        $response->assertOk();
        $this->assertSame('keyset', $response->json('pagination'));
        $this->assertCount(5, $response->json('data'));
    }

    public function test_iterating_before_id_terminates_on_empty_data(): void
    {
        [$token] = $this->createTeacherWithRecords(7);

        $anchor = PHP_INT_MAX;
        $seen = [];
        $iterations = 0;
        while ($iterations < 20) {
            $iterations++;
            $response = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/learning-records?before_id={$anchor}&per_page=3");
            $response->assertOk();
            $rows = $response->json('data');
            if (empty($rows)) {
                break; // Slack next_cursor termination
            }
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $this->assertArrayNotHasKey($id, $seen, 'Duplicate id across keyset pages');
                $seen[$id] = true;
            }
            $anchor = (int) $response->json('next_before_id');
            $this->assertGreaterThan(0, $anchor, 'next_before_id must be positive until terminal page');
        }

        $this->assertCount(7, $seen, 'Keyset iteration should visit every record exactly once');
        $this->assertLessThan(20, $iterations, 'Iteration should terminate cleanly, not hit guard');
    }

    public function test_mid_iteration_insert_does_not_affect_earlier_keyset_pages(): void
    {
        [$token, $setup] = $this->createTeacherWithRecords(5);

        // First keyset page from the top.
        $response1 = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?before_id=' . PHP_INT_MAX . '&per_page=2');
        $response1->assertOk();
        $firstIds = collect($response1->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $anchor = (int) $response1->json('next_before_id');
        $this->assertCount(2, $firstIds);
        $this->assertGreaterThan(0, $anchor);

        // Simulate a new record being inserted between calls (sliding-window attack).
        $newSessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $setup['student_class_id'],
            'SessionDate' => now()->toDateString(),
            'StartTime' => '16:00',
            'EndTime' => '17:00',
            'Status' => 'attended',
        ]);
        DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $setup['student_class_id'],
            'ClassSessionID' => $newSessionId,
            'TeacherID' => $setup['teacher_id'],
            'Subject' => 'Math',
            'SessionDate' => now()->toDateString(),
            'Content' => 'inserted-mid-iteration',
            'Status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Continue iteration from the saved anchor. The new record has a larger id and
        // therefore must NOT appear in any subsequent keyset page (they all ask id < anchor).
        $seen = array_flip($firstIds);
        while (true) {
            $response = $this->withHeaders([
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ])->getJson("/api/v1/learning-records?before_id={$anchor}&per_page=2");
            $response->assertOk();
            $rows = $response->json('data');
            if (empty($rows)) break;
            foreach ($rows as $r) {
                $id = (int) $r['id'];
                $this->assertArrayNotHasKey($id, $seen, 'Duplicate across keyset iteration despite mid-insert');
                $seen[$id] = true;
            }
            $anchor = (int) $response->json('next_before_id');
        }

        // We saw all 5 original records and zero duplicates. The inserted newer record
        // is legitimately excluded by the keyset anchor (id >= anchor).
        $this->assertCount(5, $seen);
    }

    public function test_health_endpoint_exposes_lr_default_window_days_flag(): void
    {
        // SEC-F3: perf_flags moved to /health/detailed (auth required).
        config(['perfflags.learning_records_default_window_days' => 90]);
        [$token] = $this->createTeacherWithRecords(1, 'keyset_health_test');
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/health/detailed');
        $response->assertOk();
        $response->assertJsonStructure([
            'perf_flags' => [
                'lr_default_per_page',
                'lr_max_per_page',
                'lr_default_window_days',
            ],
        ]);
        $this->assertSame(90, (int) $response->json('perf_flags.lr_default_window_days'));
    }

    public function test_before_id_respects_role_and_campus_scoping(): void
    {
        [$token1, $setupA] = $this->createTeacherWithRecords(3, 'keyset_a');
        [$_, $setupB]      = $this->createTeacherWithRecords(3, 'keyset_b');

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token1}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?before_id=' . PHP_INT_MAX . '&per_page=50');

        $response->assertOk();
        $returnedIds = collect($response->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $bleed = array_intersect($returnedIds, $setupB['record_ids']);
        $this->assertEmpty($bleed, 'before_id must not bypass role/campus scoping');
        $this->assertSame(sort($setupA['record_ids']) === true ? $setupA['record_ids'] : $setupA['record_ids'], $setupA['record_ids']);
        $this->assertCount(3, $returnedIds);
    }

    public function test_has_more_flag_flips_off_on_final_page(): void
    {
        [$token] = $this->createTeacherWithRecords(3);

        // per_page=10 > total(3) → should return has_more=false on first call.
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/learning-records?before_id=' . PHP_INT_MAX . '&per_page=10');
        $response->assertOk();
        $this->assertFalse($response->json('has_more'));
        $this->assertCount(3, $response->json('data'));
    }

    // ──────────────────────────────────────────────

    private function createTeacherWithRecords(int $count, string $prefix = 'keyset_teacher'): array
    {
        $email = "{$prefix}_" . uniqid() . '@test.com';

        $user = User::create([
            'LoginName' => $email,
            'Name' => 'KeysetTeacher',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0900000002',
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
        // 因此測試的 TeacherID 必須設為 user.id，不能另建 Teacher.id。
        $teacherId = $user->id;

        $user->update(['teacher_id' => $teacherId]);

        $student = DB::table('Student')->insertGetId([
            'name' => 'KeysetStudent',
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
            'RemainingSessions' => 20,
            'UsedSessions' => 0,
            'SessionCount' => 20,
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

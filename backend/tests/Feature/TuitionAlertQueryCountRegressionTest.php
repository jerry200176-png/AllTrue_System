<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #984 — AlertController::tuition unbounded full-table reads.
 *
 * As of this test, `tuition()` already narrows count-mode/date-mode courses with
 * SQL WHERE clauses and batches the payment/invoice lookups by StudentClassID
 * (lastPaidAtByStudentClassIds, invoiceAggregateByStudentClassIds, etc.) instead
 * of querying per row — no N+1 was found on inspection (2026-08-07). This test
 * locks that in as a regression guard: query count must stay roughly flat as
 * the number of unpaid count-mode courses grows, not scale linearly (N+1).
 */
class TuitionAlertQueryCountRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_count_does_not_scale_linearly_with_course_count(): void
    {
        $countSmall = $this->tuitionQueryCountForCourses(5);
        $countLarge = $this->tuitionQueryCountForCourses(40);

        // A per-row N+1 over 35 extra courses would add ~35+ queries. Batched
        // aggregate lookups add at most a small constant overhead per call.
        $this->assertLessThanOrEqual(
            5,
            $countLarge - $countSmall,
            "Query count grew with course count (suspected N+1): 5 courses={$countSmall}, 40 courses={$countLarge}"
        );
    }

    private function tuitionQueryCountForCourses(int $n): int
    {
        DB::table('StudentClass')->delete();
        DB::table('Student')->where('CampusID', 1)->delete();

        for ($i = 0; $i < $n; $i++) {
            $studentId = DB::table('Student')->insertGetId([
                'name' => "tuition-perf-{$n}-{$i}",
                'CampusID' => 1,
                'ClassID' => 1,
                'enable' => 1,
            ]);
            DB::table('StudentClass')->insert([
                'StudentID' => $studentId,
                'GradeID' => 1,
                'SubjectID' => 1,
                'TeacherID' => 1,
                'by1' => 1,
                'Period' => 4,
                'TotalHours' => 0,
                'Charge' => 1000,
                'Pay' => 0,
                'Paid' => 0,
                'Rate' => 1000,
                'ClassType' => 'one_on_one',
                'StartDate' => now()->subDays(10)->toDateTimeString(),
                'SessionCount' => 8,
                'SessionDuration' => 60,
                'RemainingSessions' => 8,
                'UsedSessions' => 0,
                'Stop' => 0,
                'ScheduleMode' => 'count',
            ]);
        }

        $token = $this->createUserToken("tuition-perf-{$n}@example.com");
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queryCount;
    }

    private function createUserToken(string $email): string
    {
        $user = User::create([
            'LoginName' => $email,
            'Name' => 'perf-test-user',
            'PSW' => bcrypt('test'),
            'type' => 'A',
            'status' => 'active',
        ]);
        UserCampus::firstOrCreate(
            ['UserID' => $user->id, 'CampusID' => 1],
            ['Admin' => 1, 'Approved' => 1]
        );
        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}

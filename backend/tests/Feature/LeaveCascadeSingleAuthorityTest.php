<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression coverage for the in-app director report (2026-08-06, student 陳禹慈):
 * an 8-session purchased course had drifted to 11+ live (non-cancelled) dated
 * sessions, tripping the system's own overbooking guard. Root cause: two
 * independent implementations of "auto-append a compensating session after
 * leave/cancel" existed — CourseLeaveCascadeService::appendTailAfterLeave()
 * (used by /schedules/leave-by-session and /schedules/retro-leave) and a
 * private copy in ClassSessionController::tryExtendOnLeave() (used by
 * PATCH /class-sessions/{id}) — with slightly different billable-status sets
 * and no shared invariant. ClassSessionController now delegates to the single
 * authoritative service instead of reimplementing the check.
 */
class LeaveCascadeSingleAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_billable_session_count_never_exceeds_purchased_across_both_leave_entry_points(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1]);
        $courseId = $this->seedWeeklyCourse(purchased: 8);

        $sessions = ClassSession::where('StudentClassID', $courseId)
            ->orderBy('SessionDate')->orderBy('id')->get();
        $this->assertCount(8, $sessions);

        // Leave #1 via PATCH /class-sessions/{id} — the endpoint that used to run
        // its own separate, uncoordinated append logic.
        $first = $sessions[2];
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$first->id}", [
            'status' => 'leave',
        ])->assertOk();

        $this->assertBillableCountIs($courseId, 8, 'after leave via PATCH class-sessions');

        // Leave #2 via POST /schedules/leave-by-session — the dedicated leave
        // endpoint that already routed through CourseLeaveCascadeService.
        $second = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')->first();
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/schedules/leave-by-session', [
            'class_session_id' => $second->id,
        ])->assertOk();

        $this->assertBillableCountIs($courseId, 8, 'after leave via leave-by-session');

        // Cancel one more scheduled session directly (attended-adjacent path also
        // routes through the same tryExtendOnLeave -> appendTailAfterLeave chain).
        $third = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')
            ->orderBy('SessionDate')->first();
        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$third->id}", [
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertBillableCountIs($courseId, 8, 'after cancel via PATCH class-sessions');

        // Every auto-appended tail row must carry the single authoritative
        // service's provenance-tagged note — never the old bare literal that
        // ClassSessionController used to write independently.
        $bareLegacyNoteCount = ClassSession::where('StudentClassID', $courseId)
            ->where('Note', '請假自動順延')
            ->count();
        $this->assertSame(0, $bareLegacyNoteCount, 'no session should carry the old untagged duplicate-writer note');

        $taggedAppendCount = ClassSession::where('StudentClassID', $courseId)
            ->where('Note', 'like', '%' . CourseLeaveCascadeService::NOTE_AUTO_EXTENDED . ':ld=%')
            ->count();
        $this->assertSame(3, $taggedAppendCount, 'all three compensating sessions must be tagged by the single authoritative writer');
    }

    public function test_paused_course_does_not_auto_extend_via_patch_endpoint(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00', 'Asia/Taipei'));
        $token = $this->createDirectorToken([1]);
        $courseId = $this->seedWeeklyCourse(purchased: 8);
        DB::table('StudentClass')->where('ID', $courseId)->update(['Stop' => 1]);

        $target = ClassSession::where('StudentClassID', $courseId)
            ->where('Status', 'scheduled')->orderBy('SessionDate')->first();

        $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->patchJson("/api/v1/class-sessions/{$target->id}", [
            'status' => 'leave',
        ])->assertOk();

        $total = ClassSession::where('StudentClassID', $courseId)->count();
        $this->assertSame(8, $total, 'a paused course must not get a compensating session auto-appended');
    }

    private function assertBillableCountIs(int $courseId, int $expected, string $context): void
    {
        $billable = ClassSession::where('StudentClassID', $courseId)
            ->whereNotIn('Status', CourseLeaveCascadeService::NON_BILLABLE_STATUSES)
            ->count();
        $this->assertSame($expected, $billable, "billable session count mismatch {$context}");
    }

    private function seedWeeklyCourse(int $purchased): int
    {
        $student = Student::create([
            'name' => '架構修正測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $courseId = (int) DB::table('StudentClass')->insertGetId([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-08-05',
            'TotalHours' => 0,
            'Charge' => 0,
            'Pay' => 0,
            'Paid' => 1,
            'Rate' => 0,
            'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count',
            'scheduling_policy' => 'auto_recurrence',
            'SessionCount' => $purchased,
            'SessionDuration' => 120,
            'RemainingSessions' => $purchased,
            'UsedSessions' => 0,
            'Stop' => 0,
            'week' => 3,
            'time' => '16:00:00',
        ]);

        $start = Carbon::parse('2026-08-05'); // Wednesday
        $rows = [];
        for ($i = 0; $i < $purchased; $i++) {
            $date = $start->copy()->addWeeks($i)->toDateString();
            $rows[] = [
                'StudentClassID' => $courseId,
                'SessionDate' => $date,
                'StartTime' => '16:00:00',
                'EndTime' => '18:00:00',
                'Status' => 'scheduled',
                'Note' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('ClassSession')->insert($rows);

        return $courseId;
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-leave-authority-' . uniqid() . '@example.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}

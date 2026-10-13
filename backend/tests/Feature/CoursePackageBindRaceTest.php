<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #3908: POST /course-packages/{id}/bind-courses checked its gate (including "no payment report") without locks, then
 * wrote PackageID. A payment report recorded in between was bound into the package. The bind now locks the courses and
 * then the package (same order as PaymentReportController::directorRecord) and re-runs the gate on the locked rows.
 */
class CoursePackageBindRaceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['LoginName' => 'bind3908@example.com', 'Name' => 'd', 'PSW' => 'secret', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    public function test_payment_report_recorded_after_the_preflight_is_seen_and_nothing_is_bound(): void
    {
        [$pkg, $course] = $this->packageAndCourse();

        // Race: the first payment_reports read is the unlocked preflight; a director records a report right after it.
        $raced = false;
        DB::listen(function (QueryExecuted $q) use (&$raced, $course) {
            if (!$raced && str_contains($q->sql, 'payment_reports') && str_starts_with(strtolower(ltrim($q->sql)), 'select')) {
                $raced = true;
                $this->report($course);
            }
        });

        $this->bind($pkg, [$course->ID])
            ->assertStatus(422)
            ->assertJsonPath('code', 'bind_state_changed')
            ->assertJsonPath('blocked.0.student_class_id', (int) $course->ID)
            ->assertJsonPath('blocked.0.reasons', ['payment_report_exists']);

        $this->assertTrue($raced, 'the race hook must have fired');
        $this->assertNull($course->fresh()->PackageID, 'a course with a payment report must not join the package');
    }

    public function test_bind_locks_courses_then_the_package_before_writing(): void
    {
        [$pkg, $course] = $this->packageAndCourse();

        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });

        $this->bind($pkg, [$course->ID])->assertOk();
        $this->assertSame((int) $pkg->id, (int) $course->fresh()->PackageID);

        $courseLock = $this->firstIndex($sql, fn ($s) => str_contains($s, 'from `studentclass`') && str_contains($s, 'for update'));
        $packageLock = $this->firstIndex($sql, fn ($s) => str_contains($s, 'from `course_packages`') && str_contains($s, 'for update'));
        $recheck = $this->lastIndex($sql, fn ($s) => str_starts_with($s, 'select') && str_contains($s, 'payment_reports'));
        $write = $this->firstIndex($sql, fn ($s) => str_starts_with($s, 'update `studentclass`'));

        $this->assertNotNull($courseLock, 'courses are locked');
        $this->assertNotNull($packageLock, 'the package is locked');
        $this->assertLessThan($packageLock, $courseLock, 'lock order: courses, then package (as directorRecord)');
        $this->assertLessThan($recheck, $packageLock, 'the gate is re-read after both locks');
        $this->assertLessThan($write, $recheck, 'the gate is re-read before PackageID is written');
    }

    private function packageAndCourse(): array
    {
        $student = Student::create(['name' => 's' . uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => 1, 'name' => '測試方案', 'total_sessions' => 20,
            'remaining_sessions' => 20, 'used_sessions' => 0, 'rate' => 500, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => false, 'stop' => false, 'enabled' => true,
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
            'StartDate' => now()->toDateString(), 'TotalHours' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 10,
            'RemainingSessions' => 10, 'UsedSessions' => 0, 'SessionDuration' => 120, 'ClassType' => 'one_on_one',
            'Rate' => 500, 'rate_unit' => 'session', 'Charge' => 0, 'Pay' => 0, 'Paid' => 0, 'Stop' => 0,
        ]);

        return [$pkg, $course];
    }

    private function report(StudentClass $course): void
    {
        PaymentReport::create(['StudentID' => $course->StudentID, 'StudentClassID' => $course->ID, 'reported_by_name' => 'x',
            'payment_date' => now()->toDateString(), 'payment_method' => 'cash', 'reported_amount' => 500, 'status' => 'pending',
            'report_token_hash' => hash('sha256', uniqid('r', true)), 'token_expires_at' => now()->addDay()]);
    }

    private function bind(CoursePackage $pkg, array $ids): TestResponse
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])
            ->postJson("/api/v1/course-packages/{$pkg->id}/bind-courses", ['student_class_ids' => $ids]);
    }

    private function firstIndex(array $sql, callable $match): ?int
    {
        foreach ($sql as $i => $s) {
            if ($match($s)) {
                return $i;
            }
        }

        return null;
    }

    private function lastIndex(array $sql, callable $match): ?int
    {
        $found = null;
        foreach ($sql as $i => $s) {
            if ($match($s)) {
                $found = $i;
            }
        }

        return $found;
    }
}
